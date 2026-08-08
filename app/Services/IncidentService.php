<?php

namespace App\Services;

use App\Models\Control;
use App\Models\ControlException;
use App\Models\Incident;
use App\Models\IncidentAction;
use App\Models\IncidentNotification;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Incident management (11.2): intake, triage, investigation, containment,
 * loss capture, control-failure linkage and regulatory notification.
 *
 * The regulatory windows are not here — NotificationObligationResolver
 * reads them from the seeded obligation records (R1). What is here is the
 * closure gate: an incident cannot be closed while a mandatory action is
 * open or a required notification is outstanding.
 */
class IncidentService
{
    public function __construct(
        private NotificationObligationResolver $resolver,
        private LinkageService $linkage,
        private TestingService $testing,
    ) {}

    // ── Intake and triage ────────────────────────────────────────────────

    public function report(array $data, ?User $reporter = null): Incident
    {
        return DB::transaction(function () use ($data, $reporter) {
            // Normalised to the app timezone before storage: Eloquent writes a
            // Carbon's wall-clock reading, so a zoned instant would otherwise
            // be persisted offset and start the notification clock late.
            $detectedAt = isset($data['detected_at'])
                ? Carbon::parse($data['detected_at'])->setTimezone(config('app.timezone'))
                : now();

            $incident = Incident::create([
                ...collect($data)->except(['actions', 'control_failure_ids'])->all(),
                'incident_ref' => Incident::nextReference('INC', true, 'incident_ref'),
                'detected_at' => $detectedAt,
                'reported_at' => now(),
                'reported_by' => $reporter?->id,
                'status' => 'Reported',
                'control_failure_ids' => $data['control_failure_ids'] ?? [],
            ]);

            $this->recomputeLoss($incident);

            $this->addTimelineEntry($incident, 'reported', 'Incident reported.', $reporter, $incident->reported_at, true);

            if ($detectedAt) {
                $this->addTimelineEntry($incident, 'detected', 'Incident detected via '
                    .($incident->detection_method ?: 'an unrecorded route').'.', $reporter, $detectedAt, true);
            }

            // Notification duties are computed at intake, not at triage: the
            // 72-hour clock starts when we became aware, not when we got
            // around to looking at it.
            $this->resolver->resolve($incident);

            $this->linkControlFailures($incident, $data['control_failure_ids'] ?? []);
            $this->linkRisks($incident, $data['risk_ids'] ?? []);

            $incident->auditAction('reported');

            return $incident->fresh(['actions', 'notifications', 'timeline']);
        });
    }

    public function triage(Incident $incident, array $data, User $user): Incident
    {
        $before = ['severity' => $incident->severity, 'incident_type' => $incident->incident_type];

        $incident->update(collect($data)->only([
            'severity', 'incident_type', 'basel_event_type', 'owner_id',
            'investigator_id', 'entity_id', 'process_id', 'near_miss',
        ])->all());

        // A retyped or re-scoped incident may owe a different regulator.
        $this->resolver->resolve($incident->fresh());

        $this->transition($incident, 'Triaged');
        $this->addTimelineEntry($incident, 'triaged', "Triaged as {$incident->severity} {$incident->incident_type}.", $user);
        $incident->auditAction('triaged', $before, ['severity' => $incident->severity]);

        return $incident->fresh();
    }

    public function startInvestigation(Incident $incident, User $user): Incident
    {
        $this->transition($incident, 'Under Investigation', [
            'investigator_id' => $incident->investigator_id ?? $user->id,
        ]);

        $this->addTimelineEntry($incident, 'investigation-started', 'Investigation opened.', $user);

        return $incident->fresh();
    }

    public function contain(Incident $incident, User $user, string $note): Incident
    {
        $this->transition($incident, 'Contained');
        $this->addTimelineEntry($incident, 'contained', $note, $user, null, true);

        return $incident->fresh();
    }

    public function markRemediated(Incident $incident, User $user, array $data): Incident
    {
        $this->transition($incident, 'Remediated', collect($data)->only([
            'root_cause', 'root_cause_category', 'contributing_factors', 'lessons_learned',
        ])->all());

        $this->addTimelineEntry($incident, 'remediated', 'Remediation complete.', $user, null, true);

        return $incident->fresh();
    }

    /**
     * The closure gate (11.2). Both conditions are business rules, not UI
     * niceties, so they are enforced here and reported together rather than
     * one at a time.
     */
    public function close(Incident $incident, User $user, ?string $note = null): Incident
    {
        $openActions = $incident->openMandatoryActions()->count();
        $outstanding = $incident->outstandingNotifications()->count();

        $errors = [];

        if ($openActions > 0) {
            $errors['actions'] = "This incident has {$openActions} open mandatory action(s); complete or cancel them before closing.";
        }

        if ($outstanding > 0) {
            $errors['notifications'] = "This incident owes {$outstanding} regulatory notification(s); record or waive them before closing.";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->transition($incident, 'Closed', [
            'closed_by' => $user->id,
            'closed_at' => now(),
        ]);

        $this->addTimelineEntry($incident, 'closed', $note ?? 'Incident closed.', $user, null, true);
        $incident->auditAction('closed', null, ['note' => $note]);

        return $incident->fresh();
    }

    public function reopen(Incident $incident, User $user, string $reason): Incident
    {
        $this->transition($incident, 'Reopened', ['closed_by' => null, 'closed_at' => null]);
        $this->addTimelineEntry($incident, 'reopened', $reason, $user, null, true);
        $incident->auditAction('reopened', null, ['reason' => $reason]);

        return $incident->fresh();
    }

    public function transition(Incident $incident, string $to, array $extra = []): void
    {
        if ($to === $incident->status) {
            $incident->update($extra);

            return;
        }

        $allowed = Incident::TRANSITIONS[$incident->status] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "An incident cannot move from {$incident->status} to {$to}.",
            ]);
        }

        $incident->update(['status' => $to, ...$extra]);
    }

    // ── Loss capture (R7) ────────────────────────────────────────────────

    /**
     * Net loss is derived, never typed in: gross less recovery, in integer
     * minor units. Recovery above gross is a data-entry error and is
     * rejected rather than silently producing a negative loss.
     */
    public function recordLoss(Incident $incident, array $data): Incident
    {
        $currency = $data['currency'] ?? $incident->currency;

        if (! $currency) {
            throw ValidationException::withMessages([
                'currency' => 'A loss needs a currency — amounts are stored as minor units plus an ISO-4217 code.',
            ]);
        }

        $gross = (int) ($data['gross_loss_minor'] ?? $incident->gross_loss_minor);
        $recovery = (int) ($data['recovery_minor'] ?? $incident->recovery_minor);

        if ($recovery > $gross) {
            throw ValidationException::withMessages([
                'recovery_minor' => 'Recovery cannot exceed the gross loss.',
            ]);
        }

        $incident->update([
            'currency' => $currency,
            'gross_loss_minor' => $gross,
            'recovery_minor' => $recovery,
            'net_loss_minor' => Money::of($gross, $currency)
                ->subtract(Money::of($recovery, $currency))->amount,
        ]);

        $incident->auditAction('loss-recorded', null, [
            'gross' => $gross, 'recovery' => $recovery, 'currency' => $currency,
        ]);

        return $incident->fresh();
    }

    private function recomputeLoss(Incident $incident): void
    {
        if (! $incident->currency) {
            return;
        }

        $incident->updateQuietly([
            'net_loss_minor' => (int) $incident->gross_loss_minor - (int) $incident->recovery_minor,
        ]);
    }

    // ── Actions ──────────────────────────────────────────────────────────

    public function addAction(Incident $incident, array $data): IncidentAction
    {
        $action = $incident->actions()->create([
            ...$data,
            'tenant_id' => $incident->tenant_id,
            'status' => 'Open',
        ]);

        $this->addTimelineEntry(
            $incident,
            'action-raised',
            ucfirst($action->action_type).' action raised: '.$action->title,
            null,
        );

        return $action;
    }

    public function completeAction(IncidentAction $action, User $user, ?int $evidenceId = null): IncidentAction
    {
        $action->update([
            'status' => 'Completed',
            'completed_at' => now(),
            'evidence_id' => $evidenceId ?? $action->evidence_id,
        ]);

        $this->addTimelineEntry($action->incident, 'action-completed', 'Action completed: '.$action->title, $user);

        return $action;
    }

    /** Independent verification: an action's owner cannot verify it (R2). */
    public function verifyAction(IncidentAction $action, User $verifier): IncidentAction
    {
        if ($action->owner_id !== null && $action->owner_id === $verifier->id) {
            throw ValidationException::withMessages([
                'verification' => 'An incident action cannot be verified by the person who owns it.',
            ]);
        }

        if ($action->status !== 'Completed') {
            throw ValidationException::withMessages([
                'status' => 'Only a completed action can be verified.',
            ]);
        }

        $action->update(['status' => 'Verified', 'verified_by' => $verifier->id, 'verified_at' => now()]);
        $action->auditAction('verified');

        return $action;
    }

    public function cancelAction(IncidentAction $action, User $user, string $reason): IncidentAction
    {
        $action->update(['status' => 'Cancelled']);
        $action->auditAction('cancelled', null, ['reason' => $reason, 'by' => $user->id]);

        return $action;
    }

    // ── Regulatory notification ──────────────────────────────────────────

    /** Recompute this incident's duties from the current obligation records. */
    public function refreshNotifications(Incident $incident)
    {
        return $this->resolver->resolve($incident);
    }

    public function recordNotification(
        IncidentNotification $notification,
        User $user,
        string $reference,
        ?string $notes = null,
    ): IncidentNotification {
        if (trim($reference) === '') {
            throw ValidationException::withMessages([
                'notification_reference' => 'Record the regulator’s acknowledgement reference.',
            ]);
        }

        $notification->update([
            'status' => 'Notified',
            'notified_at' => now(),
            'notified_by' => $user->id,
            'notification_reference' => $reference,
            'notes' => $notes,
        ]);

        $notification->auditAction('notified', null, ['reference' => $reference]);

        $incident = $notification->incident;

        $this->addTimelineEntry(
            $incident,
            'regulator-notified',
            ($notification->regulator?->code ?? 'Regulator').' notified — reference '.$reference,
            $user,
            null,
            true,
        );

        $incident->updateQuietly([
            'notified_at' => $incident->notified_at ?? now(),
            'notification_reference' => $incident->notification_reference ?? $reference,
        ]);

        return $notification->fresh();
    }

    /**
     * Standing a notification down is a compliance decision with a name
     * against it — it is never inferred, and it never happens silently.
     */
    public function waiveNotification(IncidentNotification $notification, User $user, string $reason): IncidentNotification
    {
        $notification->update([
            'status' => 'Waived',
            'is_required' => false,
            'notes' => $reason,
            'notified_by' => $user->id,
        ]);

        $notification->auditAction('waived', null, ['reason' => $reason, 'by' => $user->id]);

        return $notification->fresh();
    }

    // ── Control failure linkage (11.2) ───────────────────────────────────

    /**
     * An incident that names a failed control flags that control for
     * re-testing and, per tenant configuration, opens an exception against
     * it. The linkage itself is an edge in the Phase 10 graph, so the
     * control page shows the incident and vice versa.
     *
     * @param  array<int, int>  $controlIds
     */
    public function linkControlFailures(Incident $incident, array $controlIds): int
    {
        $linked = 0;

        foreach (array_unique(array_map('intval', $controlIds)) as $controlId) {
            $control = Control::find($controlId);

            if (! $control) {
                continue;
            }

            $this->linkage->link('incident', $incident->id, 'control', $control->id, 'causes');

            // Flag for re-testing by materialising this period's test
            // instance now rather than waiting for the nightly generator.
            // createInstanceForPeriod is idempotent per (control, period).
            $this->testing->createInstanceForPeriod($control, CarbonImmutable::now());

            if ($this->config($incident)['raise_exception_on_control_failure'] ?? true) {
                $this->raiseControlException($incident, $control);
            }

            $linked++;
        }

        if ($linked > 0) {
            $incident->updateQuietly([
                'control_failure_ids' => array_values(array_unique(array_map(
                    'intval',
                    [...($incident->control_failure_ids ?? []), ...$controlIds],
                ))),
            ]);
        }

        return $linked;
    }

    private function raiseControlException(Incident $incident, Control $control): ?ControlException
    {
        $existing = ControlException::query()
            ->where('source_type', 'Incident')
            ->where('source_id', $incident->id)
            ->where('control_id', $control->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $exception = ControlException::create([
            'tenant_id' => $incident->tenant_id,
            'reference' => ControlException::nextReference('EXC'),
            'source_type' => 'Incident',
            'source_id' => $incident->id,
            'control_id' => $control->id,
            'title' => 'Control failed in incident '.$incident->incident_ref.': '.str($incident->title)->limit(100),
            'description' => $incident->description,
            'severity' => $incident->severity,
            'owner_id' => $control->owner_id,
            'unit_id' => $control->unit_id,
            'raised_by' => $incident->reported_by,
            'date_raised' => now()->toDateString(),
            'target_closure_date' => now()->addDays(match ($incident->severity) {
                'Critical' => 7, 'High' => 14, 'Medium' => 30, default => 60,
            })->toDateString(),
            'status' => $control->owner_id ? 'Assigned' : 'Open',
        ]);

        $this->linkage->link('incident', $incident->id, 'exception', $exception->id, 'causes');

        return $exception;
    }

    /** @param  array<int, int>  $riskIds */
    public function linkRisks(Incident $incident, array $riskIds): int
    {
        $linked = 0;

        foreach (array_unique(array_map('intval', $riskIds)) as $riskId) {
            $this->linkage->link('incident', $incident->id, 'risk', $riskId, 'indicates');
            $linked++;
        }

        if ($linked > 0) {
            $incident->updateQuietly(['risk_ids' => array_values(array_unique(array_map('intval', $riskIds)))]);
        }

        return $linked;
    }

    // ── Timeline ─────────────────────────────────────────────────────────

    public function addTimelineEntry(
        Incident $incident,
        string $eventType,
        string $description,
        ?User $actor = null,
        ?Carbon $occurredAt = null,
        bool $isMaterial = false,
    ) {
        return $incident->timeline()->create([
            'tenant_id' => $incident->tenant_id,
            'occurred_at' => $occurredAt ?? now(),
            'actor_id' => $actor?->id ?? auth()->id(),
            'event_type' => $eventType,
            'description' => $description,
            'is_material' => $isMaterial,
        ]);
    }

    // ── Analysis ─────────────────────────────────────────────────────────

    /**
     * Loss by Basel event type, per currency — the shape operational-risk
     * capital work needs. Currencies are never summed together (R7).
     *
     * @return array<int, array<string, mixed>>
     */
    public function lossByBaselEventType(?int $tenantId = null): array
    {
        return Incident::query()
            ->when($tenantId, fn ($q, $t) => $q->withoutGlobalScope('tenant')->where('tenant_id', $t))
            ->whereNotNull('basel_event_type')
            ->whereNotNull('currency')
            ->where('near_miss', false)
            // Aliased net_loss_total, not net_loss: the model appends a
            // net_loss accessor, and an aggregate column of the same name
            // would be shadowed by it and read as the wrong value entirely.
            ->selectRaw('basel_event_type, currency, COUNT(*) as incident_count, SUM(net_loss_minor) as net_loss_total')
            ->groupBy('basel_event_type', 'currency')
            ->get()
            ->map(fn ($row) => [
                'basel_event_type' => $row->basel_event_type,
                'incidents' => (int) $row->incident_count,
                'net_loss' => Money::of((int) $row->net_loss_total, $row->currency)->jsonSerialize(),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function config(Incident $incident): array
    {
        return (array) ($incident->tenant?->settings['incidents'] ?? []);
    }
}
