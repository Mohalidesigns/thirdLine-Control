<?php

namespace App\Services;

use App\Models\CheckResult;
use App\Models\Control;
use App\Models\EffectivenessRating;
use App\Models\RatingMatrixEntry;
use App\Models\TestInstance;
use App\Models\User;
use App\Notifications\TestInstanceNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TestingService
{
    public function __construct(
        private ExceptionService $exceptionService,
        private RiskAssessmentService $riskAssessments,
        private FrequencyResolver $frequencies,
    ) {}

    /**
     * Generate scheduled test instances from each active control's frequency
     * (FR-3.4). Idempotent: the (control_id, scope_key, period_label,
     * frequency_id) unique key means re-running never duplicates an instance.
     *
     * CR-03: departmental control functions are EXCLUDED here and handled
     * by ControlTaskService instead — they generate per desk and per
     * branch and per rhythm, which this loop cannot express. Without the
     * exclusion both jobs would manufacture an instance for the same
     * control on the same night, one global and one scoped.
     */
    public function generateScheduledInstances(?CarbonImmutable $asOf = null): int
    {
        $asOf = $asOf ?? CarbonImmutable::now();
        $created = 0;

        Control::withoutGlobalScopes()
            ->where('status', 'Active')
            ->where('is_template', false)
            ->where('is_control_function', false)
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
            ->where('scope_key', 'global')
            ->where('period_label', $label)
            ->whereNull('frequency_id')
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
            // FR-3.4: a generated instance carries an assigned tester — the
            // control owner performs the periodic test (SoD bites at review,
            // where the tester may not review their own work). DEF-013.
            'assigned_tester_id' => $control->owner_id,
            'status' => 'Scheduled',
        ]);
    }

    /**
     * CR-03 §E.1: delegated to FrequencyResolver, which owns cycle
     * boundaries for the whole platform now. The signature and the labels
     * it returns are unchanged — existing instances are keyed on them.
     *
     * @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    public function periodFor(string $frequency, CarbonImmutable $asOf): array
    {
        $cycle = FrequencyResolver::LEGACY_MAP[$frequency] ?? 'monthly';

        return $this->frequencies->boundaries($cycle, $asOf);
    }

    public function start(TestInstance $instance, User $tester): TestInstance
    {
        $this->assertNotLocked($instance);

        $instance->update([
            'status' => 'In Progress',
            // Whoever actually performs the test becomes the tester of
            // record — the review SoD check (FR-3.8) compares against this,
            // so a generation-time default assignee must not mask the real
            // performer.
            'assigned_tester_id' => $tester->id,
            'started_at' => $instance->started_at ?? now(),
        ]);

        // CR3: workflow transitions carry their own event, not just the
        // status diff the Auditable trait records.
        $instance->auditAction('test_started', null, ['tester' => $tester->name]);

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

        $instance = DB::transaction(function () use ($instance, $samplingData, $tester) {
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

            // CR3: the submission is its own event, with the outcome shape
            // an examiner wants at a glance.
            $instance->auditAction('test_submitted', null, [
                'tester' => $tester->name,
                'failed_checks' => $instance->checkResults->where('result', 'Fail')->count(),
                'total_checks' => $instance->checkResults->count(),
            ]);

            return $instance;
        });

        $this->notify($this->reviewersFor($instance, $tester), 'testing.review.requested',
            new TestInstanceNotification($instance, TestInstanceNotification::SUBMITTED, $tester->name));

        return $instance;
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

            $this->notify($this->usersExcept([$instance->assigned_tester_id], $reviewer), 'testing.signed_off',
                new TestInstanceNotification($instance, TestInstanceNotification::SIGNED_OFF, $reviewer->name));
        } else {
            $instance->update([
                'status' => 'In Progress',
                'reviewer_id' => $reviewer->id,
                'review_notes' => $notes,
            ]);
            $instance->auditAction('returned_to_tester', null, ['notes' => $notes]);

            $this->notify($this->usersExcept([$instance->assigned_tester_id], $reviewer), 'testing.returned',
                new TestInstanceNotification($instance, TestInstanceNotification::RETURNED, $reviewer->name, $notes));
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

        $rating = EffectivenessRating::updateOrCreate(
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
                // DEF-014: nobody has approved the NEW values — a stale
                // approver on a Pending Approval row mis-attributes the
                // rating in any export that renders approved_by alone.
                'approved_by' => null,
                'approved_at' => null,
            ],
        );

        $this->notify($this->controlFunctionHeads($instance, $rater), 'testing.rating.approval.requested',
            new TestInstanceNotification($instance->setRelation('effectivenessRating', $rating), TestInstanceNotification::RATING_PENDING, $rater->name));

        return $rating;
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

        $this->riskAssessments->recomputeForControl($rating->control);

        $instance = $rating->testInstance->setRelation('effectivenessRating', $rating);
        $this->notify($this->usersExcept([$rating->rated_by, $rating->control?->owner_id], $approver), 'testing.rating.published',
            new TestInstanceNotification($instance, TestInstanceNotification::RATING_PUBLISHED, $approver->name));

        return $rating;
    }

    /**
     * Who reviews a submitted test: the named reviewer when there is one,
     * otherwise everyone the policy lets review it — the control function
     * heads and, for a desk or branch task, the head of its sub-unit.
     * Never the tester (FR-3.8).
     */
    private function reviewersFor(TestInstance $instance, User $tester)
    {
        if ($instance->reviewer_id && $instance->reviewer_id !== $tester->id) {
            return $this->usersExcept([$instance->reviewer_id], $tester);
        }

        $unitHeadId = $instance->control_entity_id
            ? $instance->controlEntity?->controlUnit?->head_user_id
            : null;

        return $this->controlFunctionHeads($instance, $tester)
            ->merge($this->usersExcept([$unitHeadId], $tester))
            ->unique('id');
    }

    /** The control function heads who approve — never the person who acted (FR-7.6). */
    private function controlFunctionHeads(TestInstance $instance, User $actor)
    {
        return User::withoutGlobalScopes()
            ->where('tenant_id', $instance->tenant_id)
            ->role('Control Function Head')
            ->where('id', '!=', $actor->id)
            ->where('is_active', true)
            ->get();
    }

    private function usersExcept(array $ids, User $actor)
    {
        return User::withoutGlobalScopes()
            ->whereIn('id', array_filter($ids))
            ->where('id', '!=', $actor->id)
            ->where('is_active', true)
            ->get();
    }

    /** Fire-and-log — a notification hiccup never blocks the test workflow. */
    private function notify(iterable $recipients, string $eventKey, TestInstanceNotification $notification): void
    {
        try {
            app(NotificationDispatcher::class)->send($recipients, $eventKey, $notification);
        } catch (\Throwable $e) {
            Log::warning('Test workflow notification failed', [
                'test_instance_id' => $notification->instance->id,
                'event' => $eventKey,
                'error' => $e->getMessage(),
            ]);
        }
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
