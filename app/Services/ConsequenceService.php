<?php

namespace App\Services;

use App\Models\ConsequenceAction;
use App\Models\ImprovementAction;
use App\Models\Investigation;
use App\Models\InvestigationFinding;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Consequence management (CR-04 §E.2).
 *
 *   recommend → approve | reject → in progress → implemented
 *
 * Three rules the source module does not enforce, and a Nigerian bank's
 * disciplinary process cannot do without:
 *
 *   1. §D.4-2 — the person who recommended a consequence never approves
 *      it. Enforced here, whatever roles the actor holds.
 *   2. A rejection carries a reason. An action recommended against a named
 *      person and then dismissed with no record is exactly what a
 *      disciplinary appeal asks to see.
 *   3. An action that bears on a person's employment names the person.
 *      'process_change' and 'no_action' do not; a dismissal does.
 */
class ConsequenceService
{
    public const TRANSITIONS = [
        'recommended' => ['approved', 'rejected'],
        'approved' => ['in_progress', 'implemented', 'rejected'],
        'in_progress' => ['implemented'],
        'implemented' => [],
        'rejected' => [],
    ];

    public function __construct(private ImprovementService $improvements) {}

    public function recommend(Investigation $investigation, array $data, User $actor): ConsequenceAction
    {
        if ($investigation->is_archived) {
            throw ValidationException::withMessages([
                'archive' => 'This investigation is archived. Restore it before recommending a consequence.',
            ]);
        }

        $type = $data['action_type'] ?? null;

        if (! in_array($type, ConsequenceAction::ACTION_TYPES, true)) {
            throw ValidationException::withMessages([
                'action_type' => "'{$type}' is not a consequence this platform records.",
            ]);
        }

        $subjectId = $data['investigation_subject_id'] ?? null;

        if (in_array($type, ConsequenceAction::PERSONAL_ACTION_TYPES, true) && ! $subjectId) {
            throw ValidationException::withMessages([
                'investigation_subject_id' => 'A consequence that bears on a person must name the subject it applies to.',
            ]);
        }

        if ($subjectId && ! $investigation->subjects()->whereKey($subjectId)->exists()) {
            throw ValidationException::withMessages([
                'investigation_subject_id' => 'That subject is not named on this investigation.',
            ]);
        }

        $action = ConsequenceAction::create([
            ...collect($data)->only([
                'investigation_subject_id', 'action_type', 'description', 'due_date', 'evidence_id',
            ])->all(),
            'tenant_id' => $investigation->tenant_id,
            'investigation_id' => $investigation->id,
            'reference' => ConsequenceAction::nextReference('CON'),
            'status' => 'recommended',
            'recommended_by' => $actor->id,
            'recommended_on' => now()->toDateString(),
        ]);

        app(InvestigationService::class)->recordSystemActivity(
            $investigation,
            'action_recommended',
            "Consequence recommended: {$this->label($action->action_type)}.",
            $actor,
            ['linked' => $action],
        );

        $action->auditAction('consequence.recommended', null, [
            'action_type' => $action->action_type,
            'subject_id' => $action->investigation_subject_id,
        ]);

        return $action;
    }

    /**
     * §D.4-2. The recommender never approves their own recommendation —
     * not because of a role, but because it is the same hand twice.
     */
    public function approve(ConsequenceAction $action, User $approver): ConsequenceAction
    {
        $this->assertTransition($action, 'approved');

        if ($action->recommended_by === $approver->id) {
            throw ValidationException::withMessages([
                'approval' => 'A consequence cannot be approved by the person who recommended it.',
            ]);
        }

        DB::transaction(function () use ($action, $approver) {
            $action->update([
                'status' => 'approved',
                'approved_by' => $approver->id,
                'approved_on' => now()->toDateString(),
                'rejection_reason' => null,
            ]);

            // §F.1. A process change is remediation work, and remediation
            // work in this product is an improvement action with an owner,
            // a due date and independent verification — not a line in a
            // report nobody chases.
            if ($action->action_type === 'process_change' && ! $action->improvement_action_id) {
                $this->spawnImprovement($action, $approver);
            }
        });

        app(InvestigationService::class)->recordSystemActivity(
            $action->investigation,
            'action_recommended',
            "Consequence approved: {$this->label($action->action_type)}.",
            $approver,
            ['linked' => $action],
        );

        $action->auditAction('consequence.approved', null, ['by' => $approver->id]);

        return $action->refresh();
    }

    public function reject(ConsequenceAction $action, User $approver, string $reason): ConsequenceAction
    {
        $this->assertTransition($action, 'rejected');

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'rejection_reason' => 'Rejecting a recommended consequence requires a reason.',
            ]);
        }

        $action->update([
            'status' => 'rejected',
            'approved_by' => $approver->id,
            'approved_on' => now()->toDateString(),
            'rejection_reason' => $reason,
        ]);

        app(InvestigationService::class)->recordSystemActivity(
            $action->investigation,
            'action_recommended',
            "Consequence rejected: {$this->label($action->action_type)}.",
            $approver,
            ['description' => $reason, 'linked' => $action],
        );

        $action->auditAction('consequence.rejected', null, ['by' => $approver->id, 'reason' => $reason]);

        return $action->refresh();
    }

    public function markInProgress(ConsequenceAction $action, User $actor): ConsequenceAction
    {
        $this->assertTransition($action, 'in_progress');

        $action->update(['status' => 'in_progress']);
        $action->auditAction('consequence.in_progress', null, ['by' => $actor->id]);

        return $action->refresh();
    }

    /**
     * Implementation records what was actually recovered and rolls it up
     * to the investigation's total, so "how much did we get back" has one
     * answer rather than a sum somebody computes by hand.
     */
    public function implement(ConsequenceAction $action, User $actor, array $data): ConsequenceAction
    {
        $this->assertTransition($action, 'implemented');

        DB::transaction(function () use ($action, $actor, $data) {
            $action->update([
                'status' => 'implemented',
                'implemented_on' => $data['implemented_on'] ?? now()->toDateString(),
                'implemented_by' => $actor->id,
                'implementation_note' => $data['implementation_note'] ?? null,
                'amount_recovered' => $data['amount_recovered'] ?? $action->amount_recovered,
                'evidence_id' => $data['evidence_id'] ?? $action->evidence_id,
            ]);

            $this->rollUpRecovery($action->investigation);
        });

        app(InvestigationService::class)->recordSystemActivity(
            $action->investigation,
            'action_recommended',
            "Consequence implemented: {$this->label($action->action_type)}.",
            $actor,
            ['description' => $data['implementation_note'] ?? null, 'linked' => $action],
        );

        $action->auditAction('consequence.implemented', null, [
            'by' => $actor->id,
            'amount_recovered' => $action->amount_recovered,
        ]);

        return $action->refresh();
    }

    /**
     * The investigation's amount_recovered is derived, never typed: it is
     * the sum of what its implemented consequences actually recovered.
     */
    public function rollUpRecovery(Investigation $investigation): void
    {
        $total = $investigation->consequenceActions()
            ->where('status', 'implemented')
            ->sum('amount_recovered');

        $investigation->update(['amount_recovered' => $total > 0 ? $total : null]);
    }

    /**
     * §F.1, the other direction: a finding's recommendation becomes
     * tracked remediation work, back-linked so the finding and the action
     * each know about the other.
     */
    public function raiseImprovementFromFinding(InvestigationFinding $finding, array $data, User $actor): ImprovementAction
    {
        if ($finding->improvement_action_id) {
            throw ValidationException::withMessages([
                'improvement' => "Finding {$finding->reference} already has improvement action ".
                    ($finding->improvementAction?->reference ?? '#'.$finding->improvement_action_id).'.',
            ]);
        }

        return DB::transaction(function () use ($finding, $data, $actor) {
            $improvement = $this->improvements->propose([
                'tenant_id' => $finding->tenant_id,
                'source_type' => 'investigation',
                'source_id' => $finding->id,
                'title' => $data['title'] ?? "Remediate: {$finding->title}",
                'description' => $data['description'] ?? $finding->recommendation,
                'priority' => $data['priority'] ?? $this->priorityFor($finding->severity),
                'owner_id' => $data['owner_id'] ?? null,
                'due_at' => $data['due_at'] ?? null,
                'control_id' => $finding->control_id,
            ], $actor);

            $finding->update(['improvement_action_id' => $improvement->id]);

            app(InvestigationService::class)->recordSystemActivity(
                $finding->investigation,
                'action_recommended',
                "Improvement action {$improvement->reference} raised from finding {$finding->reference}.",
                $actor,
                ['linked' => $improvement],
            );

            $finding->auditAction('investigation.improvement_raised', null, [
                'improvement' => $improvement->reference,
            ]);

            return $improvement;
        });
    }

    private function spawnImprovement(ConsequenceAction $action, User $actor): void
    {
        $improvement = $this->improvements->propose([
            'tenant_id' => $action->tenant_id,
            'source_type' => 'investigation',
            'source_id' => $action->investigation_id,
            'title' => 'Process change: '.($action->investigation?->title ?? "Investigation #{$action->investigation_id}"),
            'description' => $action->description,
            'priority' => 'High',
            'due_at' => $action->due_date,
        ], $actor);

        $action->update(['improvement_action_id' => $improvement->id]);
    }

    private function priorityFor(string $severity): string
    {
        return match ($severity) {
            'Critical' => 'Critical',
            'High' => 'High',
            'Moderate' => 'Medium',
            default => 'Low',
        };
    }

    private function assertTransition(ConsequenceAction $action, string $to): void
    {
        $allowed = self::TRANSITIONS[$action->status] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "A consequence in '{$action->status}' cannot move to '{$to}'.",
            ]);
        }
    }

    public function label(string $actionType): string
    {
        return ucfirst(str_replace('_', ' ', $actionType));
    }
}
