<?php

namespace App\Services;

use App\Models\CheckResult;
use App\Models\Control;
use App\Models\EffectivenessRating;
use App\Models\RatingMatrixEntry;
use App\Models\TestInstance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TestingService
{
    public function __construct(
        private ExceptionService $exceptionService,
        private ResidualRiskService $residualRiskService,
    ) {}

    /**
     * Generate scheduled test instances from each active control's frequency
     * (FR-3.4). Idempotent: the (control_id, period_label) unique key means
     * re-running never duplicates an instance.
     */
    public function generateScheduledInstances(?CarbonImmutable $asOf = null): int
    {
        $asOf = $asOf ?? CarbonImmutable::now();
        $created = 0;

        Control::withoutGlobalScopes()
            ->where('status', 'Active')
            ->where('is_template', false)
            ->where('frequency', '!=', 'Event-driven')
            ->with('testScripts')
            ->chunkById(100, function ($controls) use ($asOf, &$created) {
                foreach ($controls as $control) {
                    if ($this->createInstanceForPeriod($control, $asOf)) {
                        $created++;
                    }
                }
            });

        return $created;
    }

    public function createInstanceForPeriod(Control $control, CarbonImmutable $asOf): ?TestInstance
    {
        [$label, $start, $end] = $this->periodFor($control->frequency, $asOf);

        $exists = TestInstance::withoutGlobalScopes()
            ->where('control_id', $control->id)
            ->where('period_label', $label)
            ->exists();

        if ($exists) {
            return null;
        }

        $script = $control->testScripts->where('status', 'Active')->sortByDesc('version_no')->first();

        return TestInstance::create([
            'tenant_id' => $control->tenant_id,
            'control_id' => $control->id,
            'test_script_id' => $script?->id,
            'reference' => TestInstance::nextReference('TST'),
            'period_label' => $label,
            'period_start' => $start,
            'period_end' => $end,
            'due_date' => $end->addDays(5),
            'assigned_tester_id' => $control->owner_id ? null : null,
            'status' => 'Scheduled',
        ]);
    }

    /**
     * @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    public function periodFor(string $frequency, CarbonImmutable $asOf): array
    {
        return match ($frequency) {
            'Daily' => [$asOf->format('Y-m-d'), $asOf->startOfDay(), $asOf->endOfDay()],
            'Weekly' => ['W'.$asOf->format('W-Y'), $asOf->startOfWeek(), $asOf->endOfWeek()],
            'Monthly' => [$asOf->format('M-Y'), $asOf->startOfMonth(), $asOf->endOfMonth()],
            'Quarterly' => ['Q'.$asOf->quarter.'-'.$asOf->year, $asOf->startOfQuarter(), $asOf->endOfQuarter()],
            'Semi-annual' => [($asOf->month <= 6 ? 'H1' : 'H2').'-'.$asOf->year,
                $asOf->month <= 6 ? $asOf->startOfYear() : $asOf->setMonth(7)->startOfMonth(),
                $asOf->month <= 6 ? $asOf->setMonth(6)->endOfMonth() : $asOf->endOfYear()],
            'Annual' => [(string) $asOf->year, $asOf->startOfYear(), $asOf->endOfYear()],
            default => [$asOf->format('M-Y'), $asOf->startOfMonth(), $asOf->endOfMonth()],
        };
    }

    public function start(TestInstance $instance, User $tester): TestInstance
    {
        $this->assertNotLocked($instance);

        $instance->update([
            'status' => 'In Progress',
            'assigned_tester_id' => $instance->assigned_tester_id ?? $tester->id,
            'started_at' => $instance->started_at ?? now(),
        ]);

        return $instance;
    }

    /**
     * Record a single check item result. Comment is mandatory on Fail
     * and N/A (FR-3.2).
     */
    public function recordResult(TestInstance $instance, int $checkItemId, string $result, ?string $comment, User $tester): CheckResult
    {
        $this->assertNotLocked($instance);

        if (in_array($result, ['Fail', 'NA'], true) && blank($comment)) {
            throw ValidationException::withMessages([
                'comment' => 'A comment is required when a check is marked Fail or N/A.',
            ]);
        }

        return CheckResult::updateOrCreate(
            ['test_instance_id' => $instance->id, 'check_item_id' => $checkItemId],
            ['result' => $result, 'comment' => $comment, 'tested_by' => $tester->id, 'tested_at' => now()],
        );
    }

    /**
     * Submit for review. Every Failed check item automatically raises an
     * exception — no manual re-keying (FR-3.6).
     */
    public function submit(TestInstance $instance, array $samplingData, User $tester): TestInstance
    {
        $this->assertNotLocked($instance);

        return DB::transaction(function () use ($instance, $samplingData, $tester) {
            $instance->loadMissing('checkResults.checkItem', 'testScript.checkItems', 'control');

            $mandatory = $instance->testScript?->checkItems->where('is_mandatory', true)->pluck('id') ?? collect();
            $answered = $instance->checkResults->whereNotNull('result')->pluck('check_item_id');

            if ($mandatory->diff($answered)->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'results' => 'Every mandatory check item must be answered before submission.',
                ]);
            }

            $instance->update([
                ...$samplingData,
                'status' => 'Submitted',
                'submitted_at' => now(),
                'assigned_tester_id' => $instance->assigned_tester_id ?? $tester->id,
            ]);

            foreach ($instance->checkResults->where('result', 'Fail') as $failed) {
                $this->exceptionService->raiseFromCheckResult($instance, $failed);
            }

            return $instance;
        });
    }

    /**
     * Reviewer sign-off. Tester ≠ reviewer is enforced here and in
     * TestInstancePolicy (FR-3.8, §4 SoD).
     */
    public function review(TestInstance $instance, User $reviewer, bool $approved, ?string $notes = null): TestInstance
    {
        if ($instance->assigned_tester_id === $reviewer->id) {
            throw ValidationException::withMessages([
                'review' => 'The tester who executed a test cannot review it.',
            ]);
        }

        if ($instance->status !== 'Submitted') {
            throw ValidationException::withMessages([
                'review' => 'Only submitted tests can be reviewed.',
            ]);
        }

        if ($approved) {
            $instance->update([
                'status' => 'Reviewed',
                'reviewer_id' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);
            $instance->auditAction('review_signed_off');

            // Publish the completed test to ThirdLine on sign-off (FR-11.2).
            app(IntegrationService::class)->publishTestResult($instance->fresh(['effectivenessRating', 'control']));
        } else {
            $instance->update([
                'status' => 'In Progress',
                'reviewer_id' => $reviewer->id,
                'review_notes' => $notes,
            ]);
            $instance->auditAction('returned_to_tester', null, ['notes' => $notes]);
        }

        return $instance;
    }

    /**
     * Reopen a locked test — formal, reasoned, audited (FR-3.9).
     */
    public function reopen(TestInstance $instance, User $user, string $reason): TestInstance
    {
        if (! $instance->isLocked()) {
            throw ValidationException::withMessages(['reopen' => 'Only reviewed or closed tests can be reopened.']);
        }

        $instance->update(['status' => 'Reopened', 'reopen_reason' => $reason]);
        $instance->auditAction('reopened', null, ['reason' => $reason, 'by' => $user->id]);

        return $instance;
    }

    /**
     * Capture the effectiveness rating for a test instance (FR-7.1—7.5).
     * The overall rating derives from the configurable matrix, never from code.
     */
    public function rate(TestInstance $instance, array $data, User $rater): EffectivenessRating
    {
        foreach (['design', 'operating'] as $dimension) {
            if ($data["{$dimension}_effectiveness"] !== 'Effective' && blank($data["{$dimension}_rationale"] ?? null)) {
                throw ValidationException::withMessages([
                    "{$dimension}_rationale" => 'A documented rationale is required for any rating other than Effective.',
                ]);
            }
        }

        $overall = RatingMatrixEntry::resolve(
            $data['design_effectiveness'],
            $data['operating_effectiveness'],
            $instance->tenant_id,
        );

        return EffectivenessRating::updateOrCreate(
            ['test_instance_id' => $instance->id],
            [
                'tenant_id' => $instance->tenant_id,
                'control_id' => $instance->control_id,
                'period_label' => $instance->period_label,
                'design_effectiveness' => $data['design_effectiveness'],
                'design_rationale' => $data['design_rationale'] ?? null,
                'design_rationale_rich' => $data['design_rationale_rich'] ?? null,
                'operating_effectiveness' => $data['operating_effectiveness'],
                'operating_rationale' => $data['operating_rationale'] ?? null,
                'operating_rationale_rich' => $data['operating_rationale_rich'] ?? null,
                'overall_rating' => $overall,
                'status' => 'Pending Approval',
                'rated_by' => $rater->id,
            ],
        );
    }

    /**
     * Publish a rating after control function approval (FR-7.6) and feed
     * residual risk (FR-7.8).
     */
    public function approveRating(EffectivenessRating $rating, User $approver): EffectivenessRating
    {
        if ($rating->rated_by === $approver->id) {
            throw ValidationException::withMessages([
                'approval' => 'A rating cannot be approved by the user who proposed it.',
            ]);
        }

        $rating->update([
            'status' => 'Published',
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);

        $this->residualRiskService->recomputeForControl($rating->control);

        return $rating;
    }

    private function assertNotLocked(TestInstance $instance): void
    {
        if ($instance->isLocked()) {
            throw ValidationException::withMessages([
                'locked' => 'This test has been signed off and is locked. Reopen it formally to make changes.',
            ]);
        }
    }
}
