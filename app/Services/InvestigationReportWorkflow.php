<?php

namespace App\Services;

use App\Models\Investigation;
use App\Models\InvestigationActivity;
use App\Models\InvestigationReport;
use App\Models\ReportRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Spec §5.3 — moving an investigation report through review.
 *
 * Draft → Manager Review → Group Head Internal Control Review → Approved
 * → Issued. Every transition is permission-gated by the map on the model,
 * writes to the review trail, and lands on the case diary — a report that
 * changed hands without the case timeline saying so is exactly the gap
 * this module exists to close.
 *
 * Two rules carry the weight:
 *
 *   1. **A reviewer may not approve their own preparation.** Not because
 *      the roles are usually different — they are — but because they are
 *      not always, and a workflow whose separation depends on how a tenant
 *      happened to assign roles is not a separation.
 *   2. **Issue freezes.** At issue the assembled document is written into
 *      `snapshot` and the issued PDF is rendered from it. Afterwards the
 *      case may be edited freely; -R01 does not move. The next report is
 *      -R02.
 */
class InvestigationReportWorkflow
{
    public function __construct(private InvestigationReportBuilder $builder) {}

    /**
     * The draft that completion produces. Idempotent: completing a case
     * twice, or a retried job, must not mint a second version 1.
     */
    public function openDraft(Investigation $investigation, User $actor, ?ReportRun $run = null): InvestigationReport
    {
        $existing = $investigation->reports()->orderByDesc('version')->first();

        if ($existing && ! $existing->isIssued()) {
            return $existing;
        }

        $version = ($existing?->version ?? 0) + 1;

        return DB::transaction(function () use ($investigation, $actor, $run, $version) {
            $report = InvestigationReport::create([
                'tenant_id' => $investigation->tenant_id,
                'investigation_id' => $investigation->id,
                'report_number' => InvestigationReport::numberFor($investigation, $version),
                'version' => $version,
                'workflow_state' => 'draft',
                'report_run_id' => $run?->id,
                'prepared_by_id' => $investigation->lead_investigator_id ?? $actor->id,
                'created_by' => $actor->id,
            ]);

            $this->diarise(
                $investigation,
                $actor,
                $version === 1
                    ? "Draft investigation report {$report->report_number} created for review."
                    : "Investigation report {$report->report_number} opened as version {$version}.",
                $report,
            );

            return $report;
        });
    }

    /**
     * A new version, raised deliberately after -R01 was issued.
     *
     * Spec §8.5: editing the case afterwards must produce -R02 rather than
     * mutate -R01. This is the only route to a second version, and it
     * refuses while an earlier one is still in flight — two live reports
     * on one case is two answers to the same question.
     */
    public function openNextVersion(Investigation $investigation, User $actor): InvestigationReport
    {
        $latest = $investigation->reports()->orderByDesc('version')->first();

        if (! $latest) {
            throw ValidationException::withMessages([
                'report' => 'There is no report on this investigation yet. Complete the case to produce the first one.',
            ]);
        }

        if (! $latest->isIssued()) {
            throw ValidationException::withMessages([
                'report' => "{$latest->report_number} has not been issued yet. Finish or withdraw it before opening another version.",
            ]);
        }

        return $this->openDraft($investigation, $actor);
    }

    /**
     * Move forward one node.
     *
     * @param  string  $to  the target state
     */
    public function advance(InvestigationReport $report, User $actor, string $to, ?string $comment = null): InvestigationReport
    {
        $this->assertNotIssued($report);

        if (! $report->mayTransitionTo($to)) {
            throw ValidationException::withMessages([
                'workflow_state' => "A report in '{$this->humanise($report->workflow_state)}' cannot move to '{$this->humanise($to)}'.",
            ]);
        }

        $permission = $report->permissionFor($to);

        if ($permission && ! $actor->can($permission)) {
            throw ValidationException::withMessages([
                'workflow_state' => "You do not hold the authority to take this report to '{$this->humanise($to)}'.",
            ]);
        }

        $this->assertNotSelfReview($report, $actor, $to);

        return DB::transaction(function () use ($report, $actor, $to, $comment) {
            $attributes = ['workflow_state' => $to, 'returned_reason' => null];

            // The trail is stamped for the node being LEFT, which is the
            // one the reviewer actually acted on.
            match ($report->workflow_state) {
                'manager_review' => $attributes += [
                    'manager_reviewed_by_id' => $actor->id,
                    'manager_reviewed_at' => now(),
                    'manager_comment' => $comment,
                ],
                'ghic_review' => $attributes += [
                    'ghic_reviewed_by_id' => $actor->id,
                    'ghic_reviewed_at' => now(),
                    'ghic_comment' => $comment,
                    'approved_by_id' => $actor->id,
                    'approved_at' => now(),
                ],
                default => null,
            };

            $report->update($attributes);

            $this->diarise(
                $report->investigation,
                $actor,
                "Report {$report->report_number} moved to {$this->humanise($to)}.",
                $report,
            );

            $report->auditAction('investigation_report.'.$to, null, [
                'report_number' => $report->report_number,
                'version' => $report->version,
            ]);

            return $report->refresh();
        });
    }

    /**
     * Send it back to the preparer. A reason is mandatory — "returned, no
     * reason given" is not a review, and the preparer cannot act on it.
     */
    public function returnToPreparer(InvestigationReport $report, User $actor, string $reason): InvestigationReport
    {
        $this->assertNotIssued($report);

        if (! $report->isReturnable()) {
            throw ValidationException::withMessages([
                'workflow_state' => "A report in '{$this->humanise($report->workflow_state)}' is not with a reviewer, so there is nobody to return it from.",
            ]);
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'returned_reason' => 'Say why the report is going back. A return with no reason cannot be acted on.',
            ]);
        }

        $permission = $report->workflow_state === 'manager_review'
            ? 'review investigation-reports'
            : 'approve investigation-reports';

        if (! $actor->can($permission)) {
            throw ValidationException::withMessages([
                'workflow_state' => 'You do not hold the authority to return this report.',
            ]);
        }

        return DB::transaction(function () use ($report, $actor, $reason) {
            $from = $report->workflow_state;

            $attributes = ['workflow_state' => 'draft', 'returned_reason' => $reason];

            // Record who sent it back, in the block for the node they sat
            // at, and clear any approval an earlier pass had recorded.
            if ($from === 'manager_review') {
                $attributes += [
                    'manager_reviewed_by_id' => $actor->id,
                    'manager_reviewed_at' => now(),
                    'manager_comment' => $reason,
                ];
            } else {
                $attributes += [
                    'ghic_reviewed_by_id' => $actor->id,
                    'ghic_reviewed_at' => now(),
                    'ghic_comment' => $reason,
                    'approved_by_id' => null,
                    'approved_at' => null,
                ];
            }

            $report->update($attributes);

            $this->diarise(
                $report->investigation,
                $actor,
                "Report {$report->report_number} returned to the preparer from {$this->humanise($from)}.",
                $report,
            );

            return $report->refresh();
        });
    }

    /**
     * Issue it. This is the point of no return, so it does the freezing
     * before it does the announcing.
     */
    public function issue(InvestigationReport $report, User $actor): InvestigationReport
    {
        $this->assertNotIssued($report);

        if ($report->workflow_state !== 'approved') {
            throw ValidationException::withMessages([
                'workflow_state' => 'Only an approved report may be issued.',
            ]);
        }

        if (! $actor->can('issue investigation-reports')) {
            throw ValidationException::withMessages([
                'workflow_state' => 'You do not hold the authority to issue this report.',
            ]);
        }

        $investigation = $report->investigation;
        $snapshot = $this->builder->document($investigation, $actor);

        DB::transaction(function () use ($report, $actor, $snapshot) {
            $report->update([
                'workflow_state' => 'issued',
                'snapshot' => $snapshot,
                'issued_at' => now(),
                'issue_date' => now()->toDateString(),
            ]);

            $this->diarise(
                $report->investigation,
                $actor,
                "Investigation report {$report->report_number} issued.",
                $report,
                'report_issued',
            );

            $report->auditAction('investigation_report.issued', null, [
                'report_number' => $report->report_number,
                'version' => $report->version,
            ]);
        });

        // Rendered from the frozen snapshot, and outside the transaction:
        // a renderer that falls over must not un-issue a report that three
        // people have now signed.
        try {
            $run = $this->builder->renderSnapshot($report->refresh(), $actor);
            $report->update(['report_run_id' => $run->id]);
        } catch (\Throwable $e) {
            Log::error('Issued investigation report failed to render', [
                'report' => $report->report_number,
                'error' => $e->getMessage(),
            ]);
        }

        return $report->refresh();
    }

    /**
     * The document a viewer should be shown: the frozen snapshot once the
     * report is issued, the live record while it is still moving.
     *
     * @return array<string, mixed>
     */
    public function documentFor(InvestigationReport $report, User $viewer): array
    {
        return $report->isIssued() && $report->snapshot
            ? $report->snapshot
            : $this->builder->document($report->investigation, $viewer);
    }

    // ── Guards ───────────────────────────────────────────────────────────

    private function assertNotIssued(InvestigationReport $report): void
    {
        if ($report->isIssued()) {
            throw ValidationException::withMessages([
                'workflow_state' => "{$report->report_number} has been issued and cannot be changed. Open a new version instead.",
            ]);
        }
    }

    /**
     * A preparer may not review their own report, and a manager who passed
     * it up may not then approve it themselves. The separation is enforced
     * per PERSON, not per role: two hats on one head is still one head.
     */
    private function assertNotSelfReview(InvestigationReport $report, User $actor, string $to): void
    {
        if ($to === 'manager_review') {
            return; // Submitting your own draft upward is the preparer's job.
        }

        if ($report->prepared_by_id === $actor->id) {
            throw ValidationException::withMessages([
                'workflow_state' => 'You prepared this report. It needs a reviewer who did not.',
            ]);
        }

        if ($to === 'approved' && $report->manager_reviewed_by_id === $actor->id) {
            throw ValidationException::withMessages([
                'workflow_state' => 'You already reviewed this report as manager. Approval is a second pair of eyes.',
            ]);
        }
    }

    private function diarise(
        Investigation $investigation,
        User $actor,
        string $title,
        InvestigationReport $report,
        string $type = 'report_reviewed',
    ): void {
        InvestigationActivity::create([
            'tenant_id' => $investigation->tenant_id,
            'investigation_id' => $investigation->id,
            'activity_type' => $type,
            'title' => $title,
            'activity_date' => now(),
            'performed_by' => $actor->id,
            'linked_type' => InvestigationReport::class,
            'linked_id' => $report->id,
        ]);
    }

    private function humanise(string $state): string
    {
        return match ($state) {
            'ghic_review' => 'Group Head Internal Control Review',
            'manager_review' => 'Manager Review',
            default => ucfirst($state),
        };
    }
}
