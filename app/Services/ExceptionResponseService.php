<?php

namespace App\Services;

use App\Models\Evidence;
use App\Models\ExceptionAction;
use App\Models\ExceptionEscalation;
use App\Models\ExceptionResponse;
use App\Models\ExceptionResponseLink;
use App\Models\User;
use App\Notifications\ExceptionEscalationIssuedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * CR-01: the department's side of the loop — acknowledgement, the
 * structured answer, and the secure-link channel for respondents outside
 * the system (R-H).
 */
class ExceptionResponseService
{
    public function __construct(
        private ExceptionSlaCalculator $slaCalculator,
        private ExceptionEscalationService $escalationService,
        private NotificationDispatcher $dispatcher,
    ) {}

    /**
     * CR1.3: opening the response screen for the first time IS the
     * acknowledgement — the department cannot claim it never saw it.
     */
    public function acknowledge(ExceptionEscalation $escalation, ?User $user = null): void
    {
        if ($escalation->acknowledged_at || ! $escalation->isAwaitingResponse()) {
            return;
        }

        $escalation->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => $user?->id,
            'is_acknowledgement_late' => $escalation->acknowledge_due_at !== null
                && now()->greaterThan($escalation->acknowledge_due_at),
            'status' => $escalation->status === 'Issued' ? 'Acknowledged' : $escalation->status,
        ]);

        $escalation->exception->logActivity(
            'Acknowledgement',
            null,
            $user?->name ?? $escalation->external_name ?? 'External respondent',
        );
        $escalation->auditAction('acknowledged', null, [
            'late' => $escalation->is_acknowledgement_late,
        ]);
    }

    /** Save-draft: capture the work in progress with no status change. */
    public function saveDraft(ExceptionEscalation $escalation, array $data, ?User $user = null): ExceptionResponse
    {
        $draft = $this->currentDraft($escalation);

        $payload = [
            ...$this->responsePayload($escalation, $data, $user),
            'submitted_at' => null,
        ];

        if ($draft) {
            $draft->update($payload);

            return $draft;
        }

        return ExceptionResponse::withoutGlobalScope('tenant')->create([
            'tenant_id' => $escalation->tenant_id,
            'escalation_id' => $escalation->id,
            'exception_id' => $escalation->exception_id,
            'round_no' => $escalation->round_no,
            ...$payload,
        ]);
    }

    /**
     * CR1.3 — Submit: the on-the-record answer. Validates against
     * required_response, stamps lateness against the SLA clock, moves the
     * escalation to Responded and tells the control function.
     */
    public function submit(ExceptionEscalation $escalation, array $data, ?User $user = null, string $channel = 'portal'): ExceptionResponse
    {
        if (! $escalation->isAwaitingResponse()) {
            throw ValidationException::withMessages([
                'response' => "Escalation {$escalation->reference} is {$escalation->status} and no longer accepts a response.",
            ]);
        }

        $this->assertRequiredContent($escalation, $data);

        $submittedAt = now();

        $response = DB::transaction(function () use ($escalation, $data, $user, $channel, $submittedAt) {
            $draft = $this->currentDraft($escalation);

            $payload = [
                ...$this->responsePayload($escalation, $data, $user),
                'channel' => $channel,
                'submitted_at' => $submittedAt,
                'is_late' => $this->slaCalculator->isLate($submittedAt->copy(), $escalation->response_due_at),
                'ip_address' => $channel === 'secure_link' ? request()?->ip() : null,
                'user_agent' => $channel === 'secure_link' ? substr((string) request()?->userAgent(), 0, 500) : null,
            ];

            $response = $draft
                ? tap($draft)->update($payload)
                : ExceptionResponse::withoutGlobalScope('tenant')->create([
                    'tenant_id' => $escalation->tenant_id,
                    'escalation_id' => $escalation->id,
                    'exception_id' => $escalation->exception_id,
                    'round_no' => $escalation->round_no,
                    ...$payload,
                ]);

            // Pick up the database defaults (review_status) so the model in
            // hand is reviewable without a second query by the caller.
            $response->refresh();

            $escalation->update([
                'status' => 'Responded',
                'first_response_at' => $escalation->first_response_at ?? $submittedAt,
                'last_response_at' => $submittedAt,
                'is_response_late' => $response->is_late,
            ]);

            $escalation->exception->logActivity(
                'Response Submitted',
                "Round {$escalation->round_no}",
                $response->position,
                str($data['management_comment'] ?? '')->limit(180),
            );
            $escalation->auditAction('responded', null, [
                'round' => $escalation->round_no,
                'position' => $response->position,
                'late' => $response->is_late,
            ]);

            return $response;
        });

        $this->escalationService->notifyResponseSubmitted($escalation->fresh(), $response);
        $this->escalationService->refreshExceptionCache($escalation->exception->fresh());

        return $response;
    }

    // ── Committed actions (CR1.5) ───────────────────────────────────

    public function recordActionProgress(ExceptionAction $action, User $user, float $percentage, ?string $note = null): ExceptionAction
    {
        $action->update([
            'status' => $percentage >= 100 ? $action->status : 'In Progress',
            'progress_percentage' => min(100, max(0, $percentage)),
            'progress_note' => $note ?? $action->progress_note,
        ]);

        return $action;
    }

    public function completeAction(ExceptionAction $action, User $user, ?string $note = null): ExceptionAction
    {
        $action->update([
            'status' => 'Completed',
            'progress_percentage' => 100,
            'progress_note' => $note ?? $action->progress_note,
            'completed_at' => now(),
            'completed_by' => $user->id,
        ]);

        $action->exception->logActivity('Action Completed', null, $action->reference, $note);
        $action->auditAction('completed', null, ['reference' => $action->reference]);

        return $action;
    }

    // ── Secure links (R-H) ──────────────────────────────────────────

    /**
     * Generate the one-time answer link for an external respondent. The
     * plaintext token is returned ONCE and never persisted — only its
     * sha256. Expiry defaults to the escalation's response deadline: the
     * link dies when the clock does.
     *
     * @return array{link: ExceptionResponseLink, token: string, url: string}
     */
    public function createSecureLink(ExceptionEscalation $escalation, User $creator, ?Carbon $expiresAt = null, bool $notify = true): array
    {
        $token = Str::random(48);

        $link = ExceptionResponseLink::withoutGlobalScope('tenant')->create([
            'tenant_id' => $escalation->tenant_id,
            'escalation_id' => $escalation->id,
            'token_hash' => ExceptionResponseLink::hashToken($token),
            'token_prefix' => substr($token, 0, 8),
            'created_by' => $creator->id,
            'expires_at' => $expiresAt ?? $escalation->response_due_at ?? now()->addDay(),
            'status' => 'active',
        ]);

        $link->auditAction('generated', null, ['prefix' => $link->token_prefix]);

        $url = route('public.exception-response.show', ['token' => $token]);

        if ($notify && $escalation->external_email) {
            $this->dispatcher->sendExternal(
                $escalation->external_email,
                'exception.escalation.issued',
                new ExceptionEscalationIssuedNotification($escalation, $url),
            );
        }

        return ['link' => $link, 'token' => $token, 'url' => $url];
    }

    /**
     * Resolve a presented token to its escalation, enforcing every R-H
     * property: hashed lookup, expiry, revocation, single-escalation scope,
     * and an access log with IP and user agent.
     */
    public function resolveLink(string $token): ExceptionResponseLink
    {
        $link = ExceptionResponseLink::withoutGlobalScopes()
            ->where('token_hash', ExceptionResponseLink::hashToken(trim($token)))
            ->first();

        if (! $link) {
            abort(404);
        }

        if ($link->status === 'active' && $link->expires_at->isPast()) {
            $link->update(['status' => 'expired']);
        }

        if (! $link->isUsable() && $link->status !== 'used') {
            abort(410, 'This response link is no longer valid.');
        }

        $link->update([
            'access_count' => $link->access_count + 1,
            'last_accessed_at' => now(),
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500),
        ]);

        $link->auditAction('accessed', null, [
            'ip' => request()?->ip(),
            'count' => $link->access_count,
        ]);

        return $link;
    }

    public function revokeLink(ExceptionResponseLink $link, User $actor): ExceptionResponseLink
    {
        $link->update([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revoked_by' => $actor->id,
        ]);

        $link->auditAction('revoked');

        return $link;
    }

    /** A submission through the link consumes it: one answer per token. */
    public function markLinkUsed(ExceptionResponseLink $link): void
    {
        $link->update([
            'status' => 'used',
            'submitted_at' => now(),
        ]);
    }

    // ── internals ───────────────────────────────────────────────────

    private function currentDraft(ExceptionEscalation $escalation): ?ExceptionResponse
    {
        return $escalation->responses()
            ->where('round_no', $escalation->round_no)
            ->whereNull('submitted_at')
            ->first();
    }

    private function responsePayload(ExceptionEscalation $escalation, array $data, ?User $user): array
    {
        return [
            'responder_id' => $user?->id,
            'responder_name' => $user?->name ?? ($data['responder_name'] ?? $escalation->external_name),
            'responder_email' => $user?->email ?? ($data['responder_email'] ?? $escalation->external_email),
            'position' => $data['position'],
            'management_comment' => $data['management_comment'],
            'root_cause' => $data['root_cause'] ?? null,
            'root_cause_category' => $data['root_cause_category'] ?? null,
            'agreed_action_plan' => $data['agreed_action_plan'] ?? null,
            'proposed_target_date' => $data['proposed_target_date'] ?? null,
        ];
    }

    /**
     * required_response drives what a valid answer must contain: 'both'
     * demands root cause AND action plan — unless the department DISPUTES,
     * where a rebuttal comment and supporting evidence are demanded instead.
     */
    private function assertRequiredContent(ExceptionEscalation $escalation, array $data): void
    {
        $errors = [];

        if (($data['position'] ?? null) === 'Disputed') {
            if (mb_strlen(trim($data['management_comment'] ?? '')) < 30) {
                $errors['management_comment'] = 'A dispute needs a substantive rebuttal (at least 30 characters).';
            }

            // Evidence is demanded from portal respondents, who can upload;
            // a secure-link respondent states the rebuttal on the record.
            if ($escalation->respondent_id) {
                $hasEvidence = Evidence::withoutGlobalScope('tenant')
                    ->where('tenant_id', $escalation->tenant_id)
                    ->where('linked_type', ExceptionEscalation::class)
                    ->where('linked_id', $escalation->id)
                    ->exists();

                if (! $hasEvidence) {
                    $errors['position'] = 'A disputed exception needs supporting evidence — upload it to this escalation before submitting.';
                }
            }
        } else {
            $needsRootCause = in_array($escalation->required_response, ['root_cause', 'both'], true);
            $needsActionPlan = in_array($escalation->required_response, ['action_plan', 'both'], true);

            if ($needsRootCause && blank($data['root_cause'] ?? null)) {
                $errors['root_cause'] = 'A root cause is required for this escalation.';
            }

            if ($needsActionPlan && blank($data['agreed_action_plan'] ?? null)) {
                $errors['agreed_action_plan'] = 'An agreed action plan is required for this escalation.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
