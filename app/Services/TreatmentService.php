<?php

namespace App\Services;

use App\Models\Risk;
use App\Models\RiskTreatment;
use App\Models\TreatmentMilestone;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Treatment plans (10.4). The lifecycle mirrors the exception and
 * improvement patterns already in the product: the owner takes a plan as
 * far as Implemented, and somebody else verifies it. Risk acceptance is a
 * separate, senior, expiring decision — the same shape as
 * ExceptionService::acceptRisk.
 */
class TreatmentService
{
    public const TRANSITIONS = [
        'Proposed' => ['Approved', 'Cancelled'],
        'Approved' => ['In Progress', 'Cancelled'],
        'In Progress' => ['Implemented', 'Overdue', 'Cancelled'],
        'Implemented' => ['Verified', 'In Progress'],
        'Overdue' => ['In Progress', 'Implemented', 'Cancelled'],
        'Verified' => [],
        'Cancelled' => [],
    ];

    public function __construct(private RiskAssessmentService $assessments) {}

    public function propose(Risk $risk, array $data, User $author): RiskTreatment
    {
        // Milestones arrive alongside the plan but belong to their own table.
        // Stripped explicitly rather than relying on mass-assignment guards,
        // which seeders run without.
        $milestones = $data['milestones'] ?? [];
        unset($data['milestones']);

        $treatment = RiskTreatment::create([
            'tenant_id' => $risk->tenant_id,
            'reference' => RiskTreatment::nextReference('TRT'),
            'risk_id' => $risk->id,
            'status' => 'Proposed',
            'progress' => 0,
            'last_update_at' => now(),
            ...$data,
        ]);

        foreach ($milestones as $index => $milestone) {
            $this->addMilestone($treatment, [
                'title' => $milestone['title'],
                'due_at' => $milestone['due_at'] ?? null,
                'owner_id' => $milestone['owner_id'] ?? $treatment->owner_id,
                'sequence' => $index + 1,
            ]);
        }

        return $treatment;
    }

    /**
     * Approval is maker-checker (R2). Accepting a risk is a stronger gate
     * still: senior approval plus a mandatory expiry date, so an accepted
     * risk is re-confirmed rather than quietly forgotten.
     */
    public function approve(RiskTreatment $treatment, User $approver, array $data = []): RiskTreatment
    {
        if ($treatment->owner_id === $approver->id) {
            throw ValidationException::withMessages([
                'approval' => 'A treatment plan cannot be approved by its own owner.',
            ]);
        }

        if ($treatment->strategy === 'Accept') {
            if (! $approver->hasRole('Control Function Head')) {
                throw ValidationException::withMessages([
                    'approval' => 'Accepting a risk requires Control Function Head approval.',
                ]);
            }

            if (empty($data['acceptance_expiry'])) {
                throw ValidationException::withMessages([
                    'acceptance_expiry' => 'Risk acceptance must carry an expiry date.',
                ]);
            }
        }

        $this->transition($treatment, 'Approved', array_filter([
            'approver_id' => $approver->id,
            'acceptance_reason' => $data['acceptance_reason'] ?? null,
            'accepted_by' => $treatment->strategy === 'Accept' ? $approver->id : null,
            'acceptance_expiry' => $data['acceptance_expiry'] ?? null,
            'last_update_at' => now(),
        ], fn ($value) => $value !== null));

        $treatment->auditAction('approved', null, [
            'by' => $approver->id,
            'strategy' => $treatment->strategy,
            'acceptance_expiry' => $treatment->acceptance_expiry?->toDateString(),
        ]);

        return $treatment;
    }

    public function start(RiskTreatment $treatment): RiskTreatment
    {
        $this->transition($treatment, 'In Progress', ['last_update_at' => now()]);

        return $treatment;
    }

    public function recordProgress(RiskTreatment $treatment, int $progress, ?string $note = null): RiskTreatment
    {
        $progress = max(0, min(100, $progress));

        $treatment->update([
            'progress' => $progress,
            'last_update_at' => now(),
            'status' => $treatment->status === 'Overdue' && ! $treatment->isOverdue() ? 'In Progress' : $treatment->status,
        ]);

        $treatment->auditAction('progress_recorded', null, ['progress' => $progress, 'note' => $note]);

        return $treatment;
    }

    public function markImplemented(RiskTreatment $treatment): RiskTreatment
    {
        $outstanding = $treatment->milestones()
            ->whereNotIn('status', ['Completed', 'Cancelled'])
            ->count();

        if ($outstanding > 0) {
            throw ValidationException::withMessages([
                'milestones' => "This plan still has {$outstanding} open milestone(s).",
            ]);
        }

        $this->transition($treatment, 'Implemented', ['progress' => 100, 'last_update_at' => now()]);

        return $treatment;
    }

    /**
     * The failing path the phase brief calls out: the verifier is never the
     * owner. Checked here as well as in the policy so a service-level call
     * cannot slip past the gate (R2).
     */
    public function verify(RiskTreatment $treatment, User $verifier, ?string $notes = null): RiskTreatment
    {
        if ($treatment->owner_id === $verifier->id) {
            throw ValidationException::withMessages([
                'verification' => 'A treatment plan cannot be verified by the owner who implemented it.',
            ]);
        }

        $this->transition($treatment, 'Verified', [
            'verified_by' => $verifier->id,
            'verified_at' => now(),
            'verification_notes' => $notes,
            'last_update_at' => now(),
        ]);

        $treatment->auditAction('verified', null, ['by' => $verifier->id]);

        // A verified treatment usually moves the residual position, so the
        // risk is re-scored and re-checked against appetite immediately.
        if ($risk = $treatment->risk()->withoutGlobalScopes()->first()) {
            $this->assessments->recomputeResidual($risk);
        }

        return $treatment;
    }

    public function cancel(RiskTreatment $treatment, User $actor, string $reason): RiskTreatment
    {
        $this->transition($treatment, 'Cancelled', ['last_update_at' => now()]);
        $treatment->auditAction('cancelled', null, ['by' => $actor->id, 'reason' => $reason]);

        return $treatment;
    }

    // ── Milestones ───────────────────────────────────────────────────

    public function addMilestone(RiskTreatment $treatment, array $data): TreatmentMilestone
    {
        return TreatmentMilestone::create([
            'tenant_id' => $treatment->tenant_id,
            'treatment_id' => $treatment->id,
            'status' => 'Pending',
            'sequence' => $data['sequence'] ?? ($treatment->milestones()->max('sequence') + 1),
            ...$data,
        ]);
    }

    public function completeMilestone(TreatmentMilestone $milestone): TreatmentMilestone
    {
        $milestone->update(['status' => 'Completed', 'completed_at' => now()]);

        $treatment = $milestone->treatment;
        $total = $treatment->milestones()->whereNot('status', 'Cancelled')->count();
        $done = $treatment->milestones()->where('status', 'Completed')->count();

        if ($total > 0) {
            $treatment->update([
                'progress' => (int) round($done / $total * 100),
                'last_update_at' => now(),
            ]);
        }

        return $milestone;
    }

    // ── Alerting ─────────────────────────────────────────────────────

    /**
     * Daily sweep: flag overdue plans and overdue milestones. The
     * escalation itself runs through EscalationService's treatment_overdue
     * rules, so who hears about it and when stays configurable (10.4).
     */
    public function refreshOverdue(?int $tenantId = null): int
    {
        $count = 0;

        RiskTreatment::withoutGlobalScopes()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereIn('status', ['Approved', 'In Progress'])
            ->whereNotNull('due_at')
            ->whereDate('due_at', '<', now())
            ->each(function (RiskTreatment $treatment) use (&$count) {
                $treatment->updateQuietly(['status' => 'Overdue']);
                $treatment->auditAction('became_overdue', null, ['due_at' => $treatment->due_at?->toDateString()]);
                $count++;
            });

        TreatmentMilestone::withoutGlobalScopes()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereIn('status', ['Pending', 'In Progress'])
            ->whereNotNull('due_at')
            ->whereDate('due_at', '<', now())
            ->update(['status' => 'Overdue']);

        // Expired acceptances go back on the register for re-confirmation,
        // exactly as an expired exception acceptance does.
        RiskTreatment::withoutGlobalScopes()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('strategy', 'Accept')
            ->whereIn('status', ['Approved', 'Implemented', 'Verified'])
            ->whereNotNull('acceptance_expiry')
            ->whereDate('acceptance_expiry', '<', now())
            ->each(function (RiskTreatment $treatment) use (&$count) {
                $treatment->updateQuietly(['status' => 'Overdue']);
                $treatment->auditAction('acceptance_expired', null, [
                    'expired_on' => $treatment->acceptance_expiry?->toDateString(),
                ]);
                $count++;
            });

        return $count;
    }

    private function transition(RiskTreatment $treatment, string $to, array $extra = []): void
    {
        $allowed = self::TRANSITIONS[$treatment->status] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "A treatment cannot move from {$treatment->status} to {$to}.",
            ]);
        }

        $treatment->update(['status' => $to, ...$extra]);
    }
}
