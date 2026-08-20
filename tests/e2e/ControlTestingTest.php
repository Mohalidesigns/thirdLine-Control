<?php

namespace Tests\e2e;

use App\Models\AuditTrail;
use App\Models\CheckItem;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\TestInstance;
use App\Models\TestScript;
use App\Services\TestingService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * TC-07 — control testing execution (FR-3.1 … FR-3.10).
 */
class ControlTestingTest extends E2ETestCase
{
    private function service(): TestingService
    {
        return app(TestingService::class);
    }

    /** A control with an active script and two mandatory check items. */
    private function makeTestableControl(string $frequency = 'Monthly'): Control
    {
        $control = Control::withoutGlobalScopes()->where('status', 'Active')->firstOrFail();

        $control->forceFill([
            'frequency' => $frequency,
            'status' => 'Active',
            'is_template' => false,
            'owner_id' => $this->account('owner')->id,
        ])->saveQuietly();

        $script = TestScript::withoutGlobalScopes()->firstOrCreate(
            ['control_id' => $control->id, 'title' => 'E2E script'],
            ['tenant_id' => $control->tenant_id, 'version_no' => 1, 'status' => 'Active']
        );

        foreach ([1, 2] as $seq) {
            CheckItem::withoutGlobalScopes()->firstOrCreate(
                ['test_script_id' => $script->id, 'sequence' => $seq],
                [
                    'question' => "E2E mandatory check {$seq}",
                    'is_mandatory' => true,
                    'default_severity_on_fail' => 'High',
                ]
            );
        }

        return $control->refresh();
    }

    private function instanceFor(Control $control, CarbonImmutable $asOf): TestInstance
    {
        return $this->service()->createInstanceForPeriod($control, $asOf)
            ?? TestInstance::withoutGlobalScopes()->where('control_id', $control->id)->latest('id')->firstOrFail();
    }

    // -----------------------------------------------------------------
    // TC-07-01 — scheduling
    // -----------------------------------------------------------------

    public static function frequencyProvider(): array
    {
        return [
            'Daily' => ['Daily', '2026-05-14', '2026-05-14', '2026-05-14'],
            'Weekly' => ['Weekly', '2026-05-14', '2026-05-11', '2026-05-17'],
            'Monthly' => ['Monthly', '2026-05-14', '2026-05-01', '2026-05-31'],
            'Quarterly' => ['Quarterly', '2026-05-14', '2026-04-01', '2026-06-30'],
            'Semi-annual' => ['Semi-annual', '2026-05-14', '2026-01-01', '2026-06-30'],
            'Annual' => ['Annual', '2026-05-14', '2026-01-01', '2026-12-31'],
        ];
    }

    #[DataProvider('frequencyProvider')]
    public function test_tc_07_01_period_boundaries_are_correct_for_each_frequency(
        string $frequency, string $asOf, string $expectedStart, string $expectedEnd
    ): void {
        [$label, $start, $end] = $this->service()->periodFor($frequency, CarbonImmutable::parse($asOf));

        $this->assertNotEmpty($label);
        $this->assertSame($expectedStart, $start->toDateString(), "{$frequency} period start.");
        $this->assertSame($expectedEnd, $end->toDateString(), "{$frequency} period end.");
    }

    public function test_tc_07_01_a_second_run_for_the_same_period_does_not_duplicate(): void
    {
        $control = $this->makeTestableControl();
        $asOf = CarbonImmutable::parse('2026-05-14');

        $first = $this->service()->createInstanceForPeriod($control, $asOf);
        $this->assertNotNull($first, 'The first generation must create an instance.');

        $this->assertNull(
            $this->service()->createInstanceForPeriod($control, $asOf),
            'FR-3.4: a second run for the same period must not create a duplicate instance.'
        );
    }

    public function test_tc_07_11_a_retired_control_is_not_schedulable(): void
    {
        $control = $this->makeTestableControl();
        $control->forceFill(['status' => 'Retired'])->saveQuietly();

        $before = TestInstance::withoutGlobalScopes()->where('control_id', $control->id)->count();

        $this->runCommandAt('secondline:generate-test-instances', now());

        $this->assertSame(
            $before,
            TestInstance::withoutGlobalScopes()->where('control_id', $control->id)->count(),
            'FR-1.9 / TC-07-11: a retired control must not be scheduled for testing.'
        );
    }

    public function test_an_event_driven_control_is_not_auto_scheduled(): void
    {
        $control = $this->makeTestableControl('Event-driven');
        $before = TestInstance::withoutGlobalScopes()->where('control_id', $control->id)->count();

        $this->runCommandAt('secondline:generate-test-instances', now());

        $this->assertSame(
            $before,
            TestInstance::withoutGlobalScopes()->where('control_id', $control->id)->count(),
            'An event-driven control has no calendar and must not be auto-scheduled.'
        );
    }

    /**
     * FR-3.4 requires a generated instance to carry "a due date and an
     * assigned tester". The due date is set; the tester is not.
     */
    public function test_fr_3_4_generated_instances_carry_an_assigned_tester(): void
    {
        $control = $this->makeTestableControl();

        $instance = $this->service()->createInstanceForPeriod($control, CarbonImmutable::parse('2026-07-14'));

        $this->assertNotNull($instance, 'Fixture: instance not created.');
        $this->assertNotNull($instance->due_date, 'FR-3.4: a due date must be set.');

        $this->assertNotNull(
            $instance->assigned_tester_id,
            'FR-3.4: a generated test instance must carry an assigned tester. '
            .'TestingService::createInstanceForPeriod() sets '
            ."'assigned_tester_id' => \$control->owner_id ? null : null — a ternary whose branches are "
            .'both null, so every generated instance is unassigned and lands in nobody\'s queue.'
        );
    }

    // -----------------------------------------------------------------
    // TC-07-03 / TC-07-04 — execution and automatic exception raising
    // -----------------------------------------------------------------

    private function executeAll(TestInstance $instance, string $result, ?string $comment = null): void
    {
        $tester = $this->account('officer');
        $this->service()->start($instance, $tester);

        foreach ($instance->testScript->checkItems as $item) {
            $this->service()->recordResult($instance, $item->id, $result, $comment, $tester);
        }
    }

    public function test_tc_07_03_a_fully_passed_test_raises_no_exception(): void
    {
        $control = $this->makeTestableControl();
        $instance = $this->instanceFor($control, CarbonImmutable::parse('2026-06-14'));

        $before = ControlException::withoutGlobalScopes()->count();

        $this->executeAll($instance->load('testScript.checkItems'), 'Pass');
        $this->service()->submit($instance->fresh(), [], $this->account('officer'));

        $this->assertSame('Submitted', $instance->fresh()->status);
        $this->assertSame($before, ControlException::withoutGlobalScopes()->count(), 'A passing test must raise no exception.');
    }

    public function test_tc_07_04_every_failed_check_item_raises_an_exception_automatically(): void
    {
        $control = $this->makeTestableControl();
        $instance = $this->instanceFor($control, CarbonImmutable::parse('2026-06-15'));
        $instance->load('testScript.checkItems');

        $before = ControlException::withoutGlobalScopes()->count();
        $itemCount = $instance->testScript->checkItems->count();

        $this->executeAll($instance, 'Fail', 'Control did not operate for the sampled items.');
        $this->service()->submit($instance->fresh(), [], $this->account('officer'));

        $this->assertSame(
            $before + $itemCount,
            ControlException::withoutGlobalScopes()->count(),
            'FR-3.6: every failed check item must raise an exception automatically.'
        );

        $raised = ControlException::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame('Test', $raised->source_type);
        $this->assertSame($instance->id, $raised->source_id, 'The exception must link back to its source test.');
        $this->assertSame($control->id, $raised->control_id);
        $this->assertSame('High', $raised->severity, 'Severity must come from the check item default.');
    }

    public function test_fr_3_2_a_failed_check_requires_a_comment(): void
    {
        $control = $this->makeTestableControl();
        $instance = $this->instanceFor($control, CarbonImmutable::parse('2026-06-16'))->load('testScript.checkItems');
        $tester = $this->account('officer');

        $this->service()->start($instance, $tester);

        $this->expectException(ValidationException::class);

        $this->service()->recordResult($instance, $instance->testScript->checkItems->first()->id, 'Fail', null, $tester);
    }

    public function test_submission_is_blocked_until_every_mandatory_item_is_answered(): void
    {
        $control = $this->makeTestableControl();
        $instance = $this->instanceFor($control, CarbonImmutable::parse('2026-06-17'))->load('testScript.checkItems');
        $tester = $this->account('officer');

        $this->service()->start($instance, $tester);
        // Answer only the first of two mandatory items.
        $this->service()->recordResult($instance, $instance->testScript->checkItems->first()->id, 'Pass', null, $tester);

        $this->expectException(ValidationException::class);

        $this->service()->submit($instance->fresh(), [], $tester);
    }

    // -----------------------------------------------------------------
    // TC-07-09 / TC-07-10 — review, lock and reopen
    // -----------------------------------------------------------------

    private function submittedInstance(string $period = '2026-06-18'): TestInstance
    {
        $control = $this->makeTestableControl();
        $instance = $this->instanceFor($control, CarbonImmutable::parse($period))->load('testScript.checkItems');

        $this->executeAll($instance, 'Pass');
        $this->service()->submit($instance->fresh(), [], $this->account('officer'));

        return $instance->fresh();
    }

    public function test_tc_07_10_a_reviewer_can_reject_a_test_back_to_the_tester(): void
    {
        $instance = $this->submittedInstance('2026-06-19');

        $this->service()->review($instance, $this->account('cfh'), false, 'Sampling basis not documented.');

        $instance->refresh();

        $this->assertSame('In Progress', $instance->status, 'A rejected test must return to the tester.');
        $this->assertSame('Sampling basis not documented.', $instance->review_notes);
    }

    public function test_tc_07_10_approval_locks_the_test(): void
    {
        $instance = $this->submittedInstance('2026-06-20');

        $this->service()->review($instance, $this->account('cfh'), true, 'Satisfactory.');
        $instance->refresh();

        $this->assertSame('Reviewed', $instance->status);
        $this->assertTrue($instance->isLocked(), 'FR-3.9: a signed-off test must be locked.');
    }

    public function test_tc_07_09_a_locked_test_cannot_be_edited(): void
    {
        $instance = $this->submittedInstance('2026-06-21');
        $this->service()->review($instance, $this->account('cfh'), true, 'Satisfactory.');
        $instance->refresh()->load('testScript.checkItems');

        $this->expectException(ValidationException::class);

        $this->service()->recordResult(
            $instance,
            $instance->testScript->checkItems->first()->id,
            'Fail',
            'Trying to change a signed-off result.',
            $this->account('officer')
        );
    }

    public function test_fr_12_3_the_tester_cannot_review_their_own_test(): void
    {
        $instance = $this->submittedInstance('2026-06-22');

        $this->expectException(ValidationException::class);

        // officer1 executed it via executeAll().
        $this->service()->review($instance, $this->account('officer'), true, 'Self-approval attempt.');
    }

    public function test_only_a_submitted_test_can_be_reviewed(): void
    {
        $control = $this->makeTestableControl();
        $instance = $this->instanceFor($control, CarbonImmutable::parse('2026-06-23'));

        $this->expectException(ValidationException::class);

        $this->service()->review($instance, $this->account('cfh'), true, 'Reviewing a Scheduled test.');
    }

    public function test_fr_3_9_reopening_a_locked_test_requires_a_reason_and_is_audited(): void
    {
        $instance = $this->submittedInstance('2026-06-24');
        $this->service()->review($instance, $this->account('cfh'), true, 'Satisfactory.');

        $this->service()->reopen($instance->fresh(), $this->account('cfh'), 'Regulator queried the sample size.');

        $instance->refresh();

        $this->assertSame('Reopened', $instance->status);
        $this->assertSame('Regulator queried the sample size.', $instance->reopen_reason);

        $this->assertNotNull(
            AuditTrail::where('entity_type', TestInstance::class)
                ->where('entity_id', $instance->id)->where('action', 'reopened')->first(),
            'FR-3.9: a reopen must be captured in the audit trail.'
        );
    }

    public function test_an_unlocked_test_cannot_be_reopened(): void
    {
        $instance = $this->submittedInstance('2026-06-25');

        $this->expectException(ValidationException::class);

        $this->service()->reopen($instance, $this->account('cfh'), 'Not locked yet.');
    }

    // -----------------------------------------------------------------
    // TC-07-08 — overdue
    // -----------------------------------------------------------------

    public function test_tc_07_08_an_overdue_test_is_flagged_and_a_reviewed_one_is_not(): void
    {
        $control = $this->makeTestableControl();
        $instance = $this->instanceFor($control, CarbonImmutable::parse('2026-06-26'));
        $instance->forceFill(['due_date' => now()->subDays(3), 'status' => 'In Progress'])->saveQuietly();

        $this->assertContains(
            $instance->id,
            TestInstance::withoutGlobalScopes()->overdue()->pluck('id')->all(),
            'A test past its due date must be flagged overdue.'
        );

        $instance->forceFill(['status' => 'Reviewed'])->saveQuietly();

        $this->assertNotContains(
            $instance->id,
            TestInstance::withoutGlobalScopes()->overdue()->pluck('id')->all(),
            'A signed-off test must leave the overdue set.'
        );
    }
}
