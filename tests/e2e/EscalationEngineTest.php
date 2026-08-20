<?php

namespace Tests\e2e;

use App\Models\Control;
use App\Models\ControlException;
use App\Models\EscalationEvent;
use App\Models\EscalationMatrix;
use Carbon\CarbonImmutable;

/**
 * TC-12 — the escalation engine (FR-8.1 … FR-8.9).
 *
 * Escalation is a daily 07:00 batch (`secondline:run-escalations`), not
 * event-driven, so every case here drives the command through the clock
 * harness rather than waiting.
 *
 * The seeded Critical matrix defines four tiers for `exception_overdue`:
 *
 *   tier 1  day 0   Control Owner
 *   tier 2  day 2   Line Manager
 *   tier 3  day 5   Control Function Head
 *   tier 4  day 10  Executive Viewer
 *
 * That ladder is the subject of TC-12-02 and TC-12-03.
 */
class EscalationEngineTest extends E2ETestCase
{
    private function criticalException(int $overdueByDays): ControlException
    {
        $control = Control::withoutGlobalScopes()->where('status', 'Active')->firstOrFail();
        $control->forceFill(['owner_id' => $this->account('owner')->id])->saveQuietly();

        return ControlException::withoutGlobalScopes()->create([
            'tenant_id' => $control->tenant_id,
            'reference' => ControlException::nextReference('EXC'),
            'source_type' => 'Manual',
            'control_id' => $control->id,
            'title' => 'E2E fixture — escalation ladder',
            'severity' => 'Critical',
            'owner_id' => $this->account('owner')->id,
            'responsible_party_id' => $this->account('owner')->id,
            'unit_id' => $control->unit_id,
            'raised_by' => $this->account('officer')->id,
            'date_raised' => now()->subDays($overdueByDays + 7)->toDateString(),
            'target_closure_date' => now()->subDays($overdueByDays)->toDateString(),
            'status' => 'In Progress',
            'is_overdue' => $overdueByDays > 0,
            'age_days' => $overdueByDays + 7,
        ]);
    }

    /** @return array<int,int> tier number => events fired, for this exception only */
    private function tiersFiredFor(ControlException $exception): array
    {
        return EscalationEvent::withoutGlobalScopes()
            ->where('exception_id', $exception->id)
            ->join('escalation_matrices', 'escalation_matrices.id', '=', 'escalation_events.matrix_id')
            ->where('escalation_matrices.trigger_condition', 'exception_overdue')
            ->selectRaw('escalation_matrices.tier_no as tier, count(*) as fired')
            ->groupBy('escalation_matrices.tier_no')
            ->pluck('fired', 'tier')
            ->map(fn ($n) => (int) $n)
            ->toArray();
    }

    private function runEscalations(?CarbonImmutable $at = null): void
    {
        $this->runCommandAt('secondline:run-escalations', $at ?? now());
    }

    // -----------------------------------------------------------------
    // TC-12-02 / TC-12-03 — the tier ladder
    // -----------------------------------------------------------------

    public function test_tc_12_02_an_exception_one_day_overdue_escalates_only_to_tier_one(): void
    {
        $exception = $this->criticalException(overdueByDays: 1);

        $this->runEscalations();

        $tiers = $this->tiersFiredFor($exception);

        $this->assertArrayHasKey(1, $tiers, 'FR-8.2: an overdue exception must escalate to tier 1.');

        $this->assertSame(
            [1],
            array_keys($tiers),
            'TC-12-02: one day overdue fired tiers '.implode(', ', array_keys($tiers)).'. '
            .'The Critical ladder sets tier 2 at day 2, tier 3 at day 5 and tier 4 at day 10, so only '
            .'tier 1 is due. `exception_overdue` in EscalationService::run() filters on is_overdue alone '
            .'and never references $rule->days_threshold, unlike exception_unassigned, exception_inactive '
            .'and test_overdue, which all do.'
        );
    }

    public function test_tc_12_03_the_ladder_advances_with_time_rather_than_firing_at_once(): void
    {
        $exception = $this->criticalException(overdueByDays: 6);

        $this->runEscalations();

        $tiers = array_keys($this->tiersFiredFor($exception));
        sort($tiers);

        $this->assertSame(
            [1, 2, 3],
            $tiers,
            'TC-12-03: six days overdue should have reached tiers 1, 2 and 3 but not tier 4 (day 10). '
            .'Fired: '.(implode(', ', $tiers) ?: 'none').'.'
        );
    }

    /**
     * The clearest statement of the same defect: the board tier must not
     * be told about an exception on the day it becomes overdue.
     */
    public function test_the_executive_tier_is_not_notified_on_day_one(): void
    {
        $exception = $this->criticalException(overdueByDays: 1);

        $this->runEscalations();

        $tierFour = EscalationEvent::withoutGlobalScopes()
            ->where('exception_id', $exception->id)
            ->join('escalation_matrices', 'escalation_matrices.id', '=', 'escalation_events.matrix_id')
            ->where('escalation_matrices.tier_no', 4)
            ->count();

        $this->assertSame(
            0,
            $tierFour,
            'FR-8.3/FR-8.4: the Executive Viewer tier is configured for day 10 but was escalated to on '
            .'day 1. Every tier firing at once removes the distinction between an exception that has just '
            .'slipped and one that has been ignored for ten days.'
        );
    }

    /**
     * FR-8.4 — "Critical escalates faster and further than Low."
     *
     * The Low ladder puts tier 1 at day 14. A Low exception one day
     * overdue must therefore be silent, and must not escalate on the same
     * schedule as a Critical one.
     */
    public function test_fr_8_4_severity_changes_the_pace_of_escalation(): void
    {
        $control = Control::withoutGlobalScopes()->where('status', 'Active')->firstOrFail();
        $control->forceFill(['owner_id' => $this->account('owner')->id])->saveQuietly();

        $low = ControlException::withoutGlobalScopes()->create([
            'tenant_id' => $control->tenant_id,
            'reference' => ControlException::nextReference('EXC'),
            'source_type' => 'Manual',
            'control_id' => $control->id,
            'title' => 'E2E fixture — Low severity pacing',
            'severity' => 'Low',
            'owner_id' => $this->account('owner')->id,
            'responsible_party_id' => $this->account('owner')->id,
            'unit_id' => $control->unit_id,
            'raised_by' => $this->account('officer')->id,
            'date_raised' => now()->subDays(8)->toDateString(),
            'target_closure_date' => now()->subDay()->toDateString(),
            'status' => 'In Progress',
            'is_overdue' => true,
            'age_days' => 8,
        ]);

        $this->runEscalations();

        $this->assertSame(
            [],
            $this->tiersFiredFor($low),
            'FR-8.4: a Low-severity exception one day overdue escalated, but its ladder sets tier 1 at '
            .'day 14. Because days_threshold is ignored for exception_overdue, a Low exception escalates '
            .'on exactly the same day as a Critical one — the severity ladder has no effect at all.'
        );
    }

    // -----------------------------------------------------------------
    // TC-12-04 — no premature escalation
    // -----------------------------------------------------------------

    public function test_tc_12_04_an_exception_within_its_target_date_does_not_escalate(): void
    {
        $exception = $this->criticalException(overdueByDays: 0);

        $this->runEscalations();

        $this->assertSame(
            [],
            $this->tiersFiredFor($exception),
            'TC-12-04: an exception still inside its target closure date must not escalate.'
        );
    }

    /** FR-8.8 — escalation is suspended once remediation is submitted. */
    public function test_fr_8_8_a_remediated_exception_does_not_escalate(): void
    {
        $exception = $this->criticalException(overdueByDays: 6);
        $exception->forceFill(['status' => 'Remediated'])->saveQuietly();

        $this->runEscalations();

        $this->assertSame(
            [],
            $this->tiersFiredFor($exception),
            'FR-8.8: escalation must be suspended while an exception is pending verification.'
        );
    }

    // -----------------------------------------------------------------
    // TC-12-06 — duplicate prevention
    // -----------------------------------------------------------------

    public function test_tc_12_06_re_running_the_sweep_does_not_re_escalate(): void
    {
        $exception = $this->criticalException(overdueByDays: 6);

        $this->runEscalations();
        $afterFirst = $this->tiersFiredFor($exception);

        $this->runEscalations();
        $this->runEscalations();
        $afterThird = $this->tiersFiredFor($exception);

        $this->assertSame(
            $afterFirst,
            $afterThird,
            'TC-12-06: re-running the scheduler re-sent escalations. Each (exception, rule) pair must fire once.'
        );

        foreach ($afterThird as $tier => $count) {
            $this->assertSame(1, $count, "Tier {$tier} fired {$count} times for the same exception.");
        }
    }

    // -----------------------------------------------------------------
    // TC-12-09 — thresholds are configuration, not code
    // -----------------------------------------------------------------

    public function test_tc_12_09_changing_a_threshold_changes_behaviour(): void
    {
        // exception_unassigned *does* honour days_threshold, so it is the
        // fair subject for this case.
        $rule = EscalationMatrix::withoutGlobalScopes()
            ->where('trigger_condition', 'exception_unassigned')
            ->where('severity', 'Critical')
            ->firstOrFail();

        $control = Control::withoutGlobalScopes()->where('status', 'Active')->firstOrFail();

        $exception = ControlException::withoutGlobalScopes()->create([
            'tenant_id' => $control->tenant_id,
            'reference' => ControlException::nextReference('EXC'),
            'source_type' => 'Manual',
            'control_id' => $control->id,
            'title' => 'E2E fixture — unassigned threshold',
            'severity' => 'Critical',
            'owner_id' => null,
            'responsible_party_id' => null,
            'unit_id' => $control->unit_id,
            'raised_by' => $this->account('officer')->id,
            'date_raised' => now()->subDay()->toDateString(),
            'target_closure_date' => now()->addDays(6)->toDateString(),
            'status' => 'Open',
        ]);

        $eventsFor = fn () => EscalationEvent::withoutGlobalScopes()
            ->where('exception_id', $exception->id)->where('matrix_id', $rule->id)->count();

        // Threshold 5 days, exception raised yesterday — must not fire.
        $rule->forceFill(['days_threshold' => 5])->saveQuietly();
        $this->runEscalations();
        $this->assertSame(0, $eventsFor(), 'Fired before its configured threshold.');

        // Widen the threshold to 0 days — must now fire, with no code change.
        $rule->forceFill(['days_threshold' => 0])->saveQuietly();
        $this->runEscalations();
        $this->assertSame(1, $eventsFor(), 'TC-12-09: changing the configured threshold did not change behaviour.');
    }

    public function test_a_deactivated_rule_does_not_fire(): void
    {
        $exception = $this->criticalException(overdueByDays: 6);

        EscalationMatrix::withoutGlobalScopes()
            ->where('trigger_condition', 'exception_overdue')
            ->where('severity', 'Critical')
            ->update(['is_active' => false]);

        $this->runEscalations();

        $this->assertSame([], $this->tiersFiredFor($exception), 'A deactivated matrix rule still fired.');
    }

    // -----------------------------------------------------------------
    // TC-12-05 — recipient resolution failures
    // -----------------------------------------------------------------

    public function test_tc_12_05_an_escalation_with_no_resolvable_recipient_is_recorded_as_failed(): void
    {
        $exception = $this->criticalException(overdueByDays: 1);

        // Tier 1 for Critical resolves to the exception's Control Owner.
        $exception->forceFill(['owner_id' => null, 'responsible_party_id' => null])->saveQuietly();

        $this->runEscalations();

        $event = EscalationEvent::withoutGlobalScopes()
            ->where('exception_id', $exception->id)
            ->join('escalation_matrices', 'escalation_matrices.id', '=', 'escalation_events.matrix_id')
            ->where('escalation_matrices.tier_no', 1)
            ->where('escalation_matrices.trigger_condition', 'exception_overdue')
            ->select('escalation_events.*')
            ->first();

        $this->assertNotNull(
            $event,
            'TC-12-05: an unroutable escalation must still be recorded, not silently dropped.'
        );

        $this->assertSame(
            'Failed',
            $event->delivery_status,
            'TC-12-05: an escalation with no resolvable recipient must be recorded as Failed.'
        );
    }

    // -----------------------------------------------------------------
    // TC-12-08 — time zone
    // -----------------------------------------------------------------

    /**
     * The tenant operates in Africa/Lagos (UTC+1) while the application
     * clock is UTC. An exception whose target closure date is "today" in
     * Lagos must not be treated as overdue during the hour when Lagos has
     * rolled over but UTC has not.
     */
    public function test_tc_12_08_thresholds_do_not_drift_across_the_lagos_utc_boundary(): void
    {
        $this->assertSame('UTC', config('app.timezone'), 'Application clock assumption changed.');

        $exception = $this->criticalException(overdueByDays: 0);

        // 00:30 in Lagos on the due date = 23:30 UTC the previous day.
        $exception->forceFill([
            'target_closure_date' => '2026-09-15',
            'is_overdue' => false,
        ])->saveQuietly();

        $this->runEscalations($this->lagos('2026-09-15 00:30:00'));

        $this->assertSame(
            [],
            $this->tiersFiredFor($exception),
            'TC-12-08: an exception due today in Lagos escalated as overdue. Thresholds must evaluate '
            .'against the tenant timezone, not raw UTC.'
        );
    }
}
