<?php

namespace App\Services;

use App\Models\CsaCampaign;
use App\Models\FrameworkRequirement;
use App\Models\Policy;
use App\Models\PolicyException;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Policy, procedure and standard lifecycle (11.1).
 *
 * The gate that matters: a policy is published by someone other than its
 * owner. It is checked here as well as in PolicyPolicy because the service
 * is reachable from seeders, imports and commands, and there is no
 * administrator bypass anywhere on the path (R2).
 */
class PolicyService
{
    public function __construct(private CsaService $csa) {}

    // ── Authoring ────────────────────────────────────────────────────────

    public function create(array $data, User $author): Policy
    {
        return DB::transaction(function () use ($data, $author) {
            $policy = Policy::create([
                ...collect($data)->except(['sections'])->all(),
                'policy_ref' => Policy::nextReference('POL', true, 'policy_ref'),
                'owner_id' => $data['owner_id'] ?? $author->id,
                'status' => 'Draft',
                'version_no' => 1,
            ]);

            $this->syncSections($policy, $data['sections'] ?? []);

            $policy->auditAction('drafted');

            return $policy->fresh(['sections']);
        });
    }

    public function update(Policy $policy, array $data): Policy
    {
        if (in_array($policy->status, ['Superseded', 'Withdrawn'], true)) {
            throw ValidationException::withMessages([
                'status' => 'A superseded or withdrawn policy can no longer be edited.',
            ]);
        }

        return DB::transaction(function () use ($policy, $data) {
            $policy->update(collect($data)->except(['sections'])->all());

            if (array_key_exists('sections', $data)) {
                $this->syncSections($policy, $data['sections']);
            }

            return $policy->fresh(['sections']);
        });
    }

    /**
     * Sections are replaced wholesale in sequence order. A published policy's
     * text is immutable — revise it (which moves it to Under Revision) first,
     * so nobody edits the words a colleague has already attested to.
     */
    public function syncSections(Policy $policy, array $sections): void
    {
        if ($policy->status === 'Published') {
            throw ValidationException::withMessages([
                'sections' => 'Move the policy to Under Revision before changing its text.',
            ]);
        }

        $keep = [];

        foreach (array_values($sections) as $index => $section) {
            $attributes = [
                'parent_id' => $section['parent_id'] ?? null,
                'sequence' => $section['sequence'] ?? $index + 1,
                'heading' => $section['heading'],
                'body' => $section['body'] ?? null,
                'is_mandatory' => $section['is_mandatory'] ?? true,
                'control_ids' => $section['control_ids'] ?? [],
                'requirement_ids' => $section['requirement_ids'] ?? [],
            ];

            $row = isset($section['id'])
                ? $policy->sections()->whereKey($section['id'])->first()
                : null;

            $row
                ? $row->update($attributes)
                : $row = $policy->sections()->create($attributes);

            $keep[] = $row->id;
        }

        $policy->sections()->whereNotIn('id', $keep ?: [0])->delete();
    }

    // ── Lifecycle ────────────────────────────────────────────────────────

    public function submitForReview(Policy $policy, User $user): Policy
    {
        $this->transition($policy, 'Under Review');
        $policy->auditAction('submitted-for-review', null, ['by' => $user->id]);

        return $policy;
    }

    public function submitForApproval(Policy $policy, User $user): Policy
    {
        if ($policy->sections()->count() === 0) {
            throw ValidationException::withMessages([
                'sections' => 'A policy needs at least one section before it can go for approval.',
            ]);
        }

        $this->transition($policy, 'Pending Approval');
        $policy->auditAction('submitted-for-approval', null, ['by' => $user->id]);

        return $policy;
    }

    /** Maker-checker: the owner can never approve their own policy (R2). */
    public function approve(Policy $policy, User $approver): Policy
    {
        $this->assertIndependent($policy, $approver, 'approved');

        $this->transition($policy, 'Approved', [
            'approver_id' => $approver->id,
            'approved_at' => now(),
        ]);

        $policy->auditAction('approved', null, ['approver_id' => $approver->id]);

        return $policy;
    }

    public function reject(Policy $policy, User $approver, string $reason): Policy
    {
        $this->assertIndependent($policy, $approver, 'rejected');

        $this->transition($policy, 'Draft');
        $policy->auditAction('rejected', null, ['reason' => $reason, 'by' => $approver->id]);

        return $policy;
    }

    /**
     * Publication (11.1). Supersedes the predecessor, sets the next review
     * date from the policy's own frequency, and — where the policy demands
     * attestation — opens the campaign automatically, because a policy
     * nobody has signed is a policy nobody has read.
     */
    public function publish(Policy $policy, User $publisher, ?array $scope = null): Policy
    {
        $this->assertIndependent($policy, $publisher, 'published');

        if ($policy->status !== 'Approved') {
            throw ValidationException::withMessages([
                'status' => 'Only an approved policy can be published.',
            ]);
        }

        return DB::transaction(function () use ($policy, $publisher, $scope) {
            $effectiveFrom = $policy->effective_from ?? now()->toDateString();

            $this->transition($policy, 'Published', [
                'published_at' => now(),
                'effective_from' => $effectiveFrom,
                'next_review_at' => $this->nextReviewDate($policy, Carbon::parse($effectiveFrom)),
            ]);

            if ($policy->supersedes_policy_id) {
                $this->supersede($policy);
            }

            $policy->auditAction('published', null, ['publisher_id' => $publisher->id]);

            if ($policy->requires_attestation) {
                $this->openAttestationCampaign($policy->fresh(['sections']), $publisher, $scope);
            }

            return $policy->fresh(['sections', 'attestationCampaign']);
        });
    }

    /** Reopen a published policy for change without losing what is in force. */
    public function revise(Policy $policy, User $user): Policy
    {
        $this->transition($policy, 'Under Revision', ['version_no' => $policy->version_no + 1]);
        $policy->auditAction('revision-started', null, ['by' => $user->id]);

        return $policy;
    }

    public function withdraw(Policy $policy, User $user, string $reason): Policy
    {
        $this->transition($policy, 'Withdrawn', [
            'withdrawal_reason' => $reason,
            'effective_to' => now()->toDateString(),
        ]);

        $policy->auditAction('withdrawn', null, ['reason' => $reason, 'by' => $user->id]);

        return $policy;
    }

    private function supersede(Policy $policy): void
    {
        $predecessor = Policy::find($policy->supersedes_policy_id);

        if (! $predecessor || $predecessor->status !== 'Published') {
            return;
        }

        $predecessor->update([
            'status' => 'Superseded',
            'effective_to' => now()->toDateString(),
        ]);

        $predecessor->auditAction('superseded', null, ['by_policy_id' => $policy->id]);
    }

    public function transition(Policy $policy, string $to, array $extra = []): void
    {
        $allowed = Policy::TRANSITIONS[$policy->status] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "A policy cannot move from {$policy->status} to {$to}.",
            ]);
        }

        $policy->update(['status' => $to, ...$extra]);
    }

    /** The rule stated once: approver and publisher are never the owner. */
    private function assertIndependent(Policy $policy, User $actor, string $verb): void
    {
        if ($policy->owner_id !== null && $policy->owner_id === $actor->id) {
            throw ValidationException::withMessages([
                'approval' => "A policy cannot be {$verb} by the person who owns it.",
            ]);
        }
    }

    public function nextReviewDate(Policy $policy, ?Carbon $from = null): ?Carbon
    {
        $from ??= now();

        return match ($policy->review_frequency) {
            'monthly' => $from->copy()->addMonthNoOverflow(),
            'quarterly' => $from->copy()->addMonthsNoOverflow(3),
            'semi_annual' => $from->copy()->addMonthsNoOverflow(6),
            'annual' => $from->copy()->addYear(),
            'biennial' => $from->copy()->addYears(2),
            'triennial' => $from->copy()->addYears(3),
            default => null,
        };
    }

    // ── Attestation (reuses the Phase 9 campaign machinery) ──────────────

    /**
     * Open an attestation campaign for a published policy. The campaign's
     * creator is the policy owner and its approver is the publisher, so the
     * maker-checker rule CsaService already enforces is satisfied by the
     * same separation that let the policy be published at all.
     */
    public function openAttestationCampaign(Policy $policy, User $publisher, ?array $scope = null): ?CsaCampaign
    {
        if ($policy->attestation_campaign_id) {
            return $policy->attestationCampaign;
        }

        $respondents = $this->attestationAudience($policy, $scope);

        if ($respondents === []) {
            return null;
        }

        $owner = $policy->owner ?? $publisher;

        $campaign = $this->csa->createCampaign([
            'tenant_id' => $policy->tenant_id,
            'name' => 'Attestation: '.$policy->title,
            'description' => 'Attestation campaign opened automatically on publication of '.$policy->policy_ref.'.',
            'campaign_type' => 'attestation',
            'period_label' => now()->format('Y'),
            'opens_at' => now(),
            'closes_at' => now()->addDays(30),
            'scope_definition' => [
                'policy_id' => $policy->id,
                'assignments' => array_map(fn (int $id) => ['respondent_id' => $id], $respondents),
            ],
        ], $owner);

        $this->csa->approveCampaign($campaign, $publisher);
        $this->csa->open($campaign->fresh());

        $policy->update(['attestation_campaign_id' => $campaign->id]);
        $policy->auditAction('attestation-campaign-opened', null, [
            'campaign_id' => $campaign->id,
            'audience' => count($respondents),
        ]);

        return $campaign->fresh();
    }

    /**
     * Who must attest. `applies_to` drives it: {"all_staff": true},
     * {"role_names": [...]}, {"unit_ids": [...]} or {"user_ids": [...]}.
     * An explicit scope argument (from the publish screen) wins.
     *
     * @return array<int, int>
     */
    public function attestationAudience(Policy $policy, ?array $scope = null): array
    {
        $rules = $scope ?? $policy->applies_to ?? ['all_staff' => true];

        if (! empty($rules['user_ids'])) {
            return array_map('intval', $rules['user_ids']);
        }

        $query = User::query()->where('is_active', true);

        if (! empty($rules['role_names'])) {
            $query->role($rules['role_names']);
        } elseif (! empty($rules['unit_ids'])) {
            $query->whereIn('unit_id', $rules['unit_ids']);
        } elseif (empty($rules['all_staff'])) {
            return [];
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    // ── Gap analysis (11.1) ──────────────────────────────────────────────

    /**
     * Framework requirements that no published policy claims to govern.
     *
     * The inverse of Phase 8's control-coverage view: a requirement can be
     * fully controlled and still ungoverned, and a regulator will ask for
     * the policy, not the control.
     *
     * @return array<string, mixed>
     */
    public function gapAnalysis(?int $frameworkId = null): array
    {
        $governed = collect();

        Policy::published()
            ->with('sections:id,policy_id,requirement_ids')
            ->chunk(100, function ($policies) use ($governed) {
                foreach ($policies as $policy) {
                    $governed->push(...$policy->governedRequirementIds());
                }
            });

        $governedIds = $governed->unique()->values()->all();

        $requirements = FrameworkRequirement::query()
            ->when($frameworkId, fn ($q, $id) => $q->where('framework_id', $id))
            ->with('framework:id,code,name')
            ->get(['id', 'framework_id', 'ref_code', 'title', 'requirement_type']);

        $gaps = $requirements->reject(fn ($r) => in_array($r->id, $governedIds, true))->values();

        return [
            'total_requirements' => $requirements->count(),
            'governed' => $requirements->count() - $gaps->count(),
            'coverage_percent' => $requirements->count() > 0
                ? (int) round(($requirements->count() - $gaps->count()) / $requirements->count() * 100)
                : 0,
            'gaps' => $gaps->map(fn ($r) => [
                'requirement_id' => $r->id,
                'ref_code' => $r->ref_code,
                'title' => $r->title,
                'framework' => $r->framework?->only(['id', 'code', 'name']),
            ])->all(),
        ];
    }

    // ── Policy exceptions / waivers (11.1) ───────────────────────────────

    public function requestException(Policy $policy, array $data, User $requester): PolicyException
    {
        if ($policy->status !== 'Published') {
            throw ValidationException::withMessages([
                'policy' => 'A waiver can only be raised against a published policy.',
            ]);
        }

        $exception = PolicyException::create([
            ...$data,
            'tenant_id' => $policy->tenant_id,
            'reference' => PolicyException::nextReference('PEX'),
            'policy_id' => $policy->id,
            'requested_by' => $requester->id,
            'status' => 'Requested',
        ]);

        $exception->auditAction('requested');

        return $exception;
    }

    /**
     * Maker-checker again: the person who needs the waiver never grants it,
     * whatever role they hold.
     */
    public function decideException(
        PolicyException $exception,
        User $approver,
        bool $approved,
        ?string $notes = null,
    ): PolicyException {
        if ($exception->requested_by === $approver->id) {
            throw ValidationException::withMessages([
                'approval' => 'A policy waiver cannot be approved by the person who requested it.',
            ]);
        }

        if (! in_array($exception->status, ['Requested', 'Under Review'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only an open waiver request can be decided.',
            ]);
        }

        $exception->update([
            'status' => $approved ? 'Approved' : 'Rejected',
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'decision_notes' => $notes,
            'review_at' => $approved
                ? ($exception->review_at ?? $exception->requested_to)
                : null,
        ]);

        $exception->auditAction($approved ? 'approved' : 'rejected', null, ['notes' => $notes]);

        return $exception;
    }

    public function revokeException(PolicyException $exception, User $user, string $reason): PolicyException
    {
        if ($exception->status !== 'Approved') {
            throw ValidationException::withMessages([
                'status' => 'Only an approved waiver can be revoked.',
            ]);
        }

        $exception->update(['status' => 'Revoked', 'revoked_reason' => $reason]);
        $exception->auditAction('revoked', null, ['reason' => $reason, 'by' => $user->id]);

        return $exception;
    }

    /** Daily sweep: a waiver past its end date expires rather than lingering. */
    public function expireWaivers(?int $tenantId = null): int
    {
        $expired = 0;

        PolicyException::withoutGlobalScope('tenant')
            ->when($tenantId, fn ($q, $t) => $q->where('tenant_id', $t))
            ->where('status', 'Approved')
            ->whereDate('requested_to', '<', now()->toDateString())
            ->each(function (PolicyException $exception) use (&$expired) {
                $exception->update(['status' => 'Expired']);
                $exception->auditAction('expired');
                $expired++;
            });

        return $expired;
    }
}
