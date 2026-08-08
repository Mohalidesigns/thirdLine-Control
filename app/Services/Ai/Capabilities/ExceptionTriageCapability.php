<?php

namespace App\Services\Ai\Capabilities;

use App\Models\ControlException;
use App\Models\User;
use App\Services\Ai\AcceptedDraft;
use App\Services\Ai\CapabilityRequest;
use App\Services\Ai\Exceptions\AiDecisionRequiredException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * §C.6 #5 — exception triage and root cause.
 *
 * Applying writes the root cause and the remediation plan onto the
 * exception, and does nothing else. It does not change the status, does not
 * mark anything remediated, and certainly does not close: the lifecycle in
 * ExceptionService is a maker-checker chain ending with a Control Function
 * Head verifying a fix they did not perform (R2), and an assistant that
 * could move an exception along that chain would dissolve the whole gate.
 *
 * A closed exception is history and is refused outright.
 */
class ExceptionTriageCapability extends Capability
{
    public function key(): string
    {
        return 'exception.triage';
    }

    public function request(User $user, array $input, ?Model $subject = null): CapabilityRequest
    {
        $exception = $subject instanceof ControlException ? $subject : null;

        return new CapabilityRequest(
            capabilityKey: $this->key(),
            user: $user,
            variables: [
                'exception' => $exception ? [
                    'reference' => $exception->reference,
                    'title' => $exception->title,
                    'description' => $exception->description,
                    'severity' => $exception->severity,
                    'source' => $exception->source_type,
                    'age_days' => $exception->age_days,
                    'is_recurring' => (bool) $exception->is_recurring,
                    'existing_root_cause' => $exception->root_cause,
                ] : (array) ($input['exception'] ?? []),
                'control' => $exception?->control ? [
                    'reference' => $exception->control->control_ref,
                    'title' => $exception->control->title,
                    'objective' => $exception->control->objective,
                    'type' => $exception->control->type,
                    'nature' => $exception->control->nature,
                ] : null,
                'history' => $this->history($exception),
            ],
            subject: $exception,
            retrievalQuery: trim(($exception?->title ?? '').' '.($exception?->description ?? '')),
        );
    }

    public function isApplicable(): bool
    {
        return true;
    }

    public function apply(AcceptedDraft $draft, User $approver): Model
    {
        $exception = $draft->interaction->subject;

        if (! $exception instanceof ControlException) {
            throw new AiDecisionRequiredException('This triage draft is not attached to an exception.');
        }

        if (in_array($exception->status, ['Verified-Closed', 'Risk Accepted'], true)) {
            throw new AiDecisionRequiredException(
                'This exception is closed. A triage suggestion cannot be applied to it.',
            );
        }

        return DB::transaction(function () use ($exception, $draft, $approver) {
            $before = $exception->only(['root_cause', 'remediation_plan']);

            $exception->update([
                'root_cause' => $draft->get('probable_root_cause') ?: $exception->root_cause,
                'remediation_plan' => $this->plan($draft->get('proposed_remediation', []))
                    ?: $exception->remediation_plan,
                // Status is deliberately untouched.
            ]);

            $exception->logActivity(
                'Comment',
                null,
                null,
                'AI triage accepted by '.$approver->name.'. Root cause and remediation plan updated for review.',
            );

            $exception->auditAction('ai_triage_applied', $before, [
                ...$draft->provenance(),
                'recurrence_risk' => $draft->get('recurrence_risk'),
                'category' => $draft->get('category'),
            ]);

            $draft->recordApplication($exception);

            return $exception;
        });
    }

    /**
     * A numbered plan rather than a paragraph — a remediation plan gets read
     * by the person who has to do it, and an ordered list with an owner role
     * against each step is what they can actually work from.
     *
     * @param  array<int, mixed>  $actions
     */
    private function plan(array $actions): ?string
    {
        $lines = [];

        foreach ($actions as $index => $action) {
            if (! is_array($action) || blank($action['action'] ?? null)) {
                continue;
            }

            $lines[] = sprintf(
                '%d. %s%s%s',
                $index + 1,
                $action['action'],
                filled($action['owner_role'] ?? null) ? ' — owner: '.$action['owner_role'] : '',
                filled($action['effort'] ?? null) ? ' (effort: '.$action['effort'].')' : '',
            );
        }

        return $lines === []
            ? null
            : "Proposed remediation (AI draft, accepted for review):\n".implode("\n", $lines);
    }

    /**
     * Prior exceptions on the same control. Recurrence is the signal that
     * separates "someone made a mistake" from "the control does not work",
     * and it is the thing a triage most often gets wrong without history.
     *
     * @return array<int, array<string, mixed>>
     */
    private function history(?ControlException $exception): array
    {
        if (! $exception?->control_id) {
            return [];
        }

        return ControlException::query()
            ->where('control_id', $exception->control_id)
            ->whereKeyNot($exception->getKey())
            ->latest('date_raised')
            ->limit(10)
            ->get(['id', 'reference', 'title', 'severity', 'status', 'root_cause', 'date_raised'])
            ->map(fn (ControlException $prior) => [
                'reference' => $prior->reference,
                'title' => $prior->title,
                'severity' => $prior->severity,
                'status' => $prior->status,
                'root_cause' => $prior->root_cause,
                'raised' => $prior->date_raised?->toDateString(),
            ])->all();
    }
}
