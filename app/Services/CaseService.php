<?php

namespace App\Services;

use App\Models\CaseNote;
use App\Models\InvestigationCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Case, investigation and whistleblowing management (11.4).
 *
 * Two guarantees this service exists to keep:
 *
 *   1. **Allowlist access, no admin bypass.** Every read goes through the
 *      model's `allowlist` global scope; every grant is an explicit,
 *      audited act by someone already on the case. A System Administrator
 *      who is not named sees nothing — that is a feature of whistleblowing
 *      integrity, not an oversight, and CaseConfidentialityTest proves it.
 *
 *   2. **Anonymity is one-way.** An anonymous report stores no reporter
 *      identity at all: no user id, no IP, no agent (the model's
 *      auditsAnonymously() keeps the audit trail clean too). The reporter
 *      gets a token once; only its SHA-256 hash is kept, so the platform
 *      can verify a returning reporter but can never name them. There is
 *      deliberately no method here that turns a case back into a person.
 */
class CaseService
{
    // ── Intake ───────────────────────────────────────────────────────────

    /**
     * Open a case. When $anonymous is true the reporter argument is ignored
     * entirely — the caller cannot accidentally attribute an anonymous
     * report by passing the authenticated user along.
     *
     * @return array{case: InvestigationCase, token: ?string}
     */
    public function open(array $data, ?User $reporter, int $tenantId, bool $anonymous = false): array
    {
        return DB::transaction(function () use ($data, $reporter, $tenantId, $anonymous) {
            $token = $anonymous ? InvestigationCase::generateToken() : null;

            $case = new InvestigationCase([
                ...collect($data)->except(['reporter_id', 'access_user_ids'])->all(),
                'case_ref' => $this->nextReference($tenantId),
                'received_at' => $data['received_at'] ?? now(),
                'status' => 'Received',
                'is_anonymous' => $anonymous,
                'reporter_id' => $anonymous ? null : $reporter?->id,
                'reporter_token_hash' => $token ? InvestigationCase::hashToken($token) : null,
                'access_user_ids' => $this->initialAllowlist($data, $reporter, $anonymous),
            ]);

            $case->tenant_id = $tenantId;
            $case->saveQuietly();

            // Saved quietly on purpose: the generic `created` audit row would
            // copy every attribute, token hash included. The event is still
            // recorded (R3) — with a deliberate payload, and with no user, IP
            // or agent when the case is anonymous.
            $case->auditAction('opened', null, [
                'case_type' => $case->case_type,
                'is_anonymous' => $case->is_anonymous,
            ]);

            return ['case' => $case, 'token' => $token];
        });
    }

    /**
     * WhatsApp and other identifying channels (11.4). The phone number never
     * reaches persistence: the bridge hands over the message text only, and
     * the case records that anonymisation happened so a later reader knows
     * the absence of a contact is deliberate, not a gap.
     *
     * @return array{case: InvestigationCase, token: ?string}
     */
    public function openFromAnonymisingBridge(array $data, int $tenantId, string $channel): array
    {
        $stripped = collect($data)
            ->except(['reporter_id', 'reporter_contact', 'phone', 'msisdn', 'email', 'customer_contact'])
            ->all();

        $result = $this->open([
            ...$stripped,
            'channel' => $channel,
            'was_anonymised' => true,
            'anonymisation_note' => 'Received via '.$channel.'; originating identifier stripped at the bridge '
                .'before persistence (NCCG Recommended Practice 19, CBN whistleblowing guidance).',
        ], null, $tenantId, anonymous: true);

        return $result;
    }

    private function nextReference(int $tenantId): string
    {
        $year = now()->year;
        $stem = "CASE-{$year}-";

        $last = InvestigationCase::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('case_ref', 'like', $stem.'%')
            ->orderByDesc('id')
            ->value('case_ref');

        $next = $last ? ((int) substr($last, strlen($stem))) + 1 : 1;

        return $stem.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * A case starts visible to whoever must act on it and nobody else. A
     * named reporter is on their own case; an anonymous one is not, because
     * there is no identity to put on the list.
     *
     * @return array<int, int>
     */
    private function initialAllowlist(array $data, ?User $reporter, bool $anonymous): array
    {
        $ids = array_map('intval', $data['access_user_ids'] ?? []);

        if (! empty($data['lead_investigator_id'])) {
            $ids[] = (int) $data['lead_investigator_id'];
        }

        if (! $anonymous && $reporter) {
            $ids[] = $reporter->id;
        }

        return array_values(array_unique($ids));
    }

    // ── Access control (11.4) ────────────────────────────────────────────

    /**
     * Grant access. Only someone already on the case may widen it, and the
     * grant is audited with both names — an allowlist nobody can audit is
     * not a control.
     */
    public function grantAccess(InvestigationCase $case, User $grantee, User $grantedBy): InvestigationCase
    {
        if (! $case->grantsAccessTo($grantedBy)) {
            throw ValidationException::withMessages([
                'access' => 'Only someone already on this case can grant access to it.',
            ]);
        }

        $ids = array_values(array_unique([
            ...array_map('intval', $case->access_user_ids ?? []),
            $grantee->id,
        ]));

        $case->update(['access_user_ids' => $ids]);
        $case->auditAction('access-granted', null, ['grantee' => $grantee->id, 'granted_by' => $grantedBy->id]);

        return $case;
    }

    public function revokeAccess(InvestigationCase $case, User $user, User $revokedBy): InvestigationCase
    {
        if (! $case->grantsAccessTo($revokedBy)) {
            throw ValidationException::withMessages([
                'access' => 'Only someone already on this case can change its access list.',
            ]);
        }

        if ($case->lead_investigator_id === $user->id) {
            throw ValidationException::withMessages([
                'access' => 'Reassign the case before removing its lead investigator.',
            ]);
        }

        $ids = array_values(array_filter(
            array_map('intval', $case->access_user_ids ?? []),
            fn (int $id) => $id !== $user->id,
        ));

        $case->update(['access_user_ids' => $ids]);
        $case->auditAction('access-revoked', null, ['user' => $user->id, 'revoked_by' => $revokedBy->id]);

        return $case;
    }

    /** Every open of a case file is a logged event, not just every change. */
    public function recordAccess(InvestigationCase $case, User $user): void
    {
        $case->auditAction('viewed', null, ['user_id' => $user->id]);
    }

    // ── Investigation lifecycle ──────────────────────────────────────────

    public function assess(InvestigationCase $case, User $user, array $data): InvestigationCase
    {
        $this->transition($case, 'Assessed', collect($data)->only([
            'severity', 'confidentiality', 'lead_investigator_id', 'entity_id', 'subject_persons',
        ])->all());

        if (! empty($data['lead_investigator_id'])) {
            $case->update([
                'access_user_ids' => array_values(array_unique([
                    ...array_map('intval', $case->access_user_ids ?? []),
                    (int) $data['lead_investigator_id'],
                ])),
            ]);
        }

        $case->auditAction('assessed', null, ['by' => $user->id]);

        return $case->fresh();
    }

    public function startInvestigation(InvestigationCase $case, User $user, string $plan): InvestigationCase
    {
        if (! $case->lead_investigator_id) {
            throw ValidationException::withMessages([
                'lead_investigator_id' => 'Assign a lead investigator before opening the investigation.',
            ]);
        }

        $this->transition($case, 'Under Investigation', ['investigation_plan' => $plan]);
        $case->auditAction('investigation-started', null, ['by' => $user->id]);

        return $case->fresh();
    }

    /**
     * Conclude. The person who reported a case can never be the one who
     * decides whether it is substantiated (R2) — the pattern the rest of
     * the platform uses for maker-checker, applied to investigation.
     */
    public function conclude(InvestigationCase $case, User $user, string $outcome, array $data): InvestigationCase
    {
        if (! in_array($outcome, ['Substantiated', 'Unsubstantiated', 'Referred'], true)) {
            throw ValidationException::withMessages([
                'outcome' => 'A case concludes as Substantiated, Unsubstantiated or Referred.',
            ]);
        }

        if ($case->reporter_id !== null && $case->reporter_id === $user->id) {
            throw ValidationException::withMessages([
                'outcome' => 'A case cannot be concluded by the person who reported it.',
            ]);
        }

        $this->transition($case, $outcome, [
            'findings' => $data['findings'] ?? $case->findings,
            'conclusion' => $data['conclusion'] ?? $case->conclusion,
            'actions_taken' => $data['actions_taken'] ?? $case->actions_taken,
            'referred_to' => $outcome === 'Referred' ? ($data['referred_to'] ?? null) : $case->referred_to,
        ]);

        $case->auditAction('concluded', null, ['outcome' => $outcome, 'by' => $user->id]);

        return $case->fresh();
    }

    public function close(InvestigationCase $case, User $user, ?string $note = null): InvestigationCase
    {
        $this->transition($case, 'Closed', ['closed_by' => $user->id, 'closed_at' => now()]);
        $case->auditAction('closed', null, ['note' => $note, 'by' => $user->id]);

        return $case->fresh();
    }

    public function transition(InvestigationCase $case, string $to, array $extra = []): void
    {
        if ($to === $case->status) {
            $case->update($extra);

            return;
        }

        $allowed = InvestigationCase::TRANSITIONS[$case->status] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "A case cannot move from {$case->status} to {$to}.",
            ]);
        }

        $case->update(['status' => $to, ...$extra]);
    }

    // ── Notes ────────────────────────────────────────────────────────────

    public function addNote(InvestigationCase $case, User $author, string $note, bool $privileged = false, bool $reporterVisible = false): CaseNote
    {
        if ($privileged && $reporterVisible) {
            throw ValidationException::withMessages([
                'note' => 'A privileged note cannot also be visible to the reporter.',
            ]);
        }

        return $case->notes()->create([
            'tenant_id' => $case->tenant_id,
            'author_id' => $author->id,
            'note' => $note,
            'is_privileged' => $privileged,
            'is_reporter_visible' => $reporterVisible,
        ]);
    }

    // ── The reporter's one-way channel (11.4) ────────────────────────────

    /**
     * Look a case up by the token handed to an anonymous reporter. Returns
     * only what the reporter is entitled to see — never the allowlist, the
     * investigator's notes or anything that could identify the subjects.
     *
     * @return array<string, mixed>|null
     */
    public function statusForToken(string $token): ?array
    {
        $case = InvestigationCase::withoutGlobalScopes()
            ->where('reporter_token_hash', InvestigationCase::hashToken(trim($token)))
            ->first();

        if (! $case) {
            return null;
        }

        $case->auditAction('reporter-status-checked');

        return [
            'case_ref' => $case->case_ref,
            'status' => $case->status,
            'received_at' => $case->received_at?->toIso8601String(),
            'closed_at' => $case->closed_at?->toIso8601String(),
            'updates' => $case->notes()
                ->where('is_reporter_visible', true)
                ->where('is_privileged', false)
                ->orderBy('created_at')
                ->get(['note', 'created_at'])
                ->map(fn (CaseNote $n) => [
                    'note' => $n->note,
                    'at' => $n->created_at?->toIso8601String(),
                ])->all(),
        ];
    }

    /**
     * A reporter replying on their token adds a note without ever
     * authenticating — the row carries no author, by design.
     */
    public function replyWithToken(string $token, string $message): ?CaseNote
    {
        $case = InvestigationCase::withoutGlobalScopes()
            ->where('reporter_token_hash', InvestigationCase::hashToken(trim($token)))
            ->first();

        if (! $case) {
            return null;
        }

        $note = $case->notes()->create([
            'tenant_id' => $case->tenant_id,
            'author_id' => null,
            'note' => $message,
            'is_privileged' => false,
            'is_reporter_visible' => true,
        ]);

        $case->auditAction('reporter-replied');

        return $note;
    }

    // ── Board reporting (11.4) ───────────────────────────────────────────

    /**
     * Aggregate extract for the board: counts and outcomes only, with no
     * titles, no subjects and no privileged content. A board paper that
     * names a whistleblower is a breach, not a report.
     *
     * @return array<string, mixed>
     */
    public function boardExtract(?int $tenantId = null, ?string $from = null, ?string $to = null): array
    {
        $query = InvestigationCase::withoutGlobalScopes()
            ->when($tenantId, fn ($q, $t) => $q->where('tenant_id', $t))
            ->when($from, fn ($q, $f) => $q->where('received_at', '>=', $f))
            ->when($to, fn ($q, $t) => $q->where('received_at', '<=', $t));

        $cases = (clone $query)->get(['case_type', 'status', 'severity', 'is_anonymous', 'received_at', 'closed_at']);

        return [
            'total' => $cases->count(),
            'anonymous' => $cases->where('is_anonymous', true)->count(),
            'open' => $cases->whereIn('status', InvestigationCase::OPEN_STATUSES)->count(),
            'by_type' => $cases->countBy('case_type')->all(),
            'by_status' => $cases->countBy('status')->all(),
            'by_severity' => $cases->countBy('severity')->all(),
            'substantiation_rate' => $this->substantiationRate($cases),
            'median_days_to_close' => $this->medianDaysToClose($cases),
        ];
    }

    private function substantiationRate($cases): ?int
    {
        $decided = $cases->whereIn('status', ['Substantiated', 'Unsubstantiated'])->count();

        return $decided > 0
            ? (int) round($cases->where('status', 'Substantiated')->count() / $decided * 100)
            : null;
    }

    private function medianDaysToClose($cases): ?int
    {
        $durations = $cases
            ->filter(fn ($c) => $c->closed_at && $c->received_at)
            ->map(fn ($c) => (int) $c->received_at->diffInDays($c->closed_at))
            ->sort()
            ->values();

        if ($durations->isEmpty()) {
            return null;
        }

        $middle = intdiv($durations->count(), 2);

        return $durations->count() % 2 === 1
            ? (int) $durations[$middle]
            : (int) round(($durations[$middle - 1] + $durations[$middle]) / 2);
    }
}
