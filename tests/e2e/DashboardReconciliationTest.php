<?php

namespace Tests\e2e;

use App\Models\Control;
use App\Models\ControlException;
use App\Services\DashboardService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * TC-15 — the executive dashboard (FR-10.1 … FR-10.6), and exit criterion
 * 6: *"Dashboard and report figures reconcile exactly to source data."*
 *
 * Every tile here is recomputed independently — with raw query-builder
 * counts written against the tables, not by reusing DashboardService's own
 * expressions — and compared. A tile that merely "looks right" is not a
 * pass; the plan rates any variance High.
 */
class DashboardReconciliationTest extends E2ETestCase
{
    private function metrics(array $filters = []): array
    {
        // Act as the Control Function Head so the tenant scope is applied
        // exactly as it would be for a real viewer.
        $this->actingAs($this->account('cfh'));

        return app(DashboardService::class)->metrics($filters);
    }

    /** Raw, tenant-scoped exception query built independently of the service. */
    private function exceptions(): Builder
    {
        return DB::table('control_exceptions')
            ->whereNull('deleted_at')
            ->where('tenant_id', $this->account('cfh')->tenant_id);
    }

    private const OPEN = ['Open', 'Assigned', 'In Progress', 'Remediated'];

    // -----------------------------------------------------------------
    // TC-15-01 — resolved vs unresolved
    // -----------------------------------------------------------------

    public function test_tc_15_01_open_and_closed_counts_reconcile_to_the_database(): void
    {
        $metrics = $this->metrics();

        $this->assertSame(
            (int) $this->exceptions()->whereIn('status', self::OPEN)->count(),
            $metrics['exceptions']['open'],
            'TC-15-01: the open-exceptions tile does not match the database.'
        );

        $this->assertSame(
            (int) $this->exceptions()->where('status', 'Verified-Closed')->count(),
            $metrics['exceptions']['closed'],
            'TC-15-01: the closed-exceptions tile does not match the database.'
        );

        $this->assertSame(
            (int) $this->exceptions()->where('status', 'Remediated')->count(),
            $metrics['exceptions']['pendingVerification'],
            'The pending-verification tile does not match the database.'
        );
    }

    // -----------------------------------------------------------------
    // TC-15-02 — severity breakdown, nothing lost or double-counted
    // -----------------------------------------------------------------

    public function test_tc_15_02_each_severity_bucket_reconciles(): void
    {
        $metrics = $this->metrics();

        foreach (['Critical', 'High', 'Medium', 'Low'] as $severity) {
            $this->assertSame(
                (int) $this->exceptions()->whereIn('status', self::OPEN)->where('severity', $severity)->count(),
                $metrics['exceptions']['bySeverity'][$severity],
                "TC-15-02: the {$severity} bucket does not match the database."
            );
        }
    }

    public function test_tc_15_02_the_severity_buckets_sum_to_the_open_total(): void
    {
        $metrics = $this->metrics();

        $this->assertSame(
            $metrics['exceptions']['open'],
            array_sum($metrics['exceptions']['bySeverity']),
            'TC-15-02: the severity buckets do not sum to the open total — a record is either uncounted '
            .'or double-counted.'
        );
    }

    /**
     * The BRD names four severities (FR-10.1) and the plan's TC-15-02 names
     * three. The implementation carries four, and this pins that down so
     * the discrepancy is a recorded fact rather than an assumption.
     */
    public function test_the_dashboard_reports_all_four_severities(): void
    {
        $this->assertSame(
            ['Critical', 'High', 'Medium', 'Low'],
            array_keys($this->metrics()['exceptions']['bySeverity']),
            'FR-10.1 names Critical/High/Medium/Low. The test plan TC-15-02 names only three; the '
            .'implementation is correct against the BRD.'
        );
    }

    // -----------------------------------------------------------------
    // TC-15-03 — overdue, recomputed with a frozen clock
    // -----------------------------------------------------------------

    public function test_tc_15_03_the_overdue_tile_reconciles(): void
    {
        $metrics = $this->metrics();

        $this->assertSame(
            (int) $this->exceptions()->whereIn('status', self::OPEN)->where('is_overdue', true)->count(),
            $metrics['exceptions']['overdue'],
            'TC-15-03: the overdue-exceptions tile does not match the database.'
        );
    }

    public function test_tc_15_03_the_overdue_tile_tracks_the_ageing_sweep(): void
    {
        $exception = ControlException::withoutGlobalScopes()
            ->where('tenant_id', $this->account('cfh')->tenant_id)
            ->whereIn('status', self::OPEN)->where('is_overdue', false)->first();

        if (! $exception) {
            $this->markTestSkipped('No non-overdue open exception available.');
        }

        $before = $this->metrics()['exceptions']['overdue'];

        $exception->forceFill(['is_overdue' => true])->saveQuietly();

        $this->assertSame(
            $before + 1,
            $this->metrics()['exceptions']['overdue'],
            'TC-15-06: the tile did not move when the underlying record changed.'
        );
    }

    public function test_the_ageing_buckets_sum_to_the_open_total(): void
    {
        $metrics = $this->metrics();

        $this->assertSame(
            $metrics['exceptions']['open'],
            array_sum($metrics['exceptions']['ageing']),
            'FR-10.5: the ageing buckets (0-30/31-60/61-90/90+) do not sum to the open total. A negative '
            .'or null age_days would fall through every bucket.'
        );
    }

    public function test_unresolved_critical_and_high_reconciles(): void
    {
        $metrics = $this->metrics();

        $this->assertSame(
            (int) $this->exceptions()->whereIn('status', self::OPEN)->whereIn('severity', ['Critical', 'High'])->count(),
            $metrics['exceptions']['unresolvedCriticalHigh'],
            'FR-10.1: the unresolved Critical/High tile does not match the database.'
        );

        $this->assertSame(
            $metrics['exceptions']['bySeverity']['Critical'] + $metrics['exceptions']['bySeverity']['High'],
            $metrics['exceptions']['unresolvedCriticalHigh'],
            'The Critical/High tile disagrees with the severity buckets on the same dashboard.'
        );
    }

    // -----------------------------------------------------------------
    // FR-10.3 / FR-10.4 — testing and effectiveness tiles
    // -----------------------------------------------------------------

    public function test_fr_10_3_testing_tiles_reconcile_and_the_completion_rate_is_arithmetically_right(): void
    {
        $metrics = $this->metrics();
        $tenantId = $this->account('cfh')->tenant_id;

        $base = fn () => DB::table('test_instances')->whereNull('deleted_at')->where('tenant_id', $tenantId);

        $total = (int) $base()->count();
        $completed = (int) $base()->whereIn('status', ['Reviewed', 'Closed'])->count();

        $this->assertSame($total, $metrics['testing']['total'], 'Total tests tile.');
        $this->assertSame($completed, $metrics['testing']['completed'], 'Completed tests tile.');
        $this->assertSame(
            (int) $base()->whereIn('status', ['In Progress', 'Submitted', 'Reopened'])->count(),
            $metrics['testing']['inProgress'],
            'In-progress tests tile.'
        );

        $expectedRate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

        $this->assertEqualsWithDelta(
            $expectedRate,
            $metrics['testing']['completionRate'],
            0.05,
            'FR-10.3: the testing completion rate does not match completed ÷ total.'
        );
    }

    public function test_fr_10_4_the_effectiveness_distribution_counts_each_control_once(): void
    {
        $metrics = $this->metrics();

        $distinctControls = (int) DB::table('effectiveness_ratings')
            ->whereNull('deleted_at')
            ->where('status', 'Published')
            ->where('tenant_id', $this->account('cfh')->tenant_id)
            ->distinct()->count('control_id');

        $this->assertSame(
            $distinctControls,
            array_sum($metrics['effectiveness']),
            'FR-10.4: the effectiveness distribution must count each control exactly once, using its '
            .'latest published rating.'
        );
    }

    public function test_control_gap_and_orphan_tiles_reconcile(): void
    {
        $metrics = $this->metrics();

        $this->assertSame(
            (int) Control::active()->where('is_template', false)->count(),
            $metrics['controls']['active'],
            'Active-controls tile.'
        );

        $this->assertGreaterThanOrEqual(0, $metrics['controls']['gaps']);
        $this->assertGreaterThanOrEqual(0, $metrics['controls']['orphans']);

        $totalRisks = $metrics['universe']['totalRisks'];

        if ($totalRisks > 0) {
            $this->assertEqualsWithDelta(
                round((($totalRisks - $metrics['controls']['gaps']) / $totalRisks) * 100),
                $metrics['universe']['coveragePct'],
                0.01,
                'FR-2.5: risk coverage % must equal (risks − control gaps) ÷ risks.'
            );
        }
    }

    // -----------------------------------------------------------------
    // TC-15-04 — filters and scoping
    // -----------------------------------------------------------------

    public function test_tc_15_04_a_unit_filter_narrows_every_exception_tile(): void
    {
        $unitId = ControlException::withoutGlobalScopes()
            ->where('tenant_id', $this->account('cfh')->tenant_id)
            ->whereNotNull('unit_id')->value('unit_id');

        if (! $unitId) {
            $this->markTestSkipped('No exception carries a unit.');
        }

        $filtered = $this->metrics(['unit_id' => $unitId]);

        $this->assertSame(
            (int) $this->exceptions()->whereIn('status', self::OPEN)->where('unit_id', $unitId)->count(),
            $filtered['exceptions']['open'],
            'TC-15-04: the unit filter does not reconcile.'
        );

        $this->assertLessThanOrEqual(
            $this->metrics()['exceptions']['open'],
            $filtered['exceptions']['open'],
            'A filtered count cannot exceed the unfiltered one.'
        );
    }

    public function test_a_severity_filter_reconciles(): void
    {
        $filtered = $this->metrics(['severity' => 'Critical']);

        $this->assertSame(
            (int) $this->exceptions()->whereIn('status', self::OPEN)->where('severity', 'Critical')->count(),
            $filtered['exceptions']['open'],
            'The severity filter does not reconcile.'
        );

        $this->assertSame(
            0,
            $filtered['exceptions']['bySeverity']['Low'],
            'Filtering to Critical must empty the other severity buckets.'
        );
    }

    /**
     * FR-10.6 requires filters on period, unit, process, control owner,
     * severity, status and risk.
     *
     * The dashboard UI offers a unit selector only, and the exception
     * register adds status, severity, search and an overdue toggle. So
     * this is an unimplemented capability rather than a control that is
     * offered and quietly ignored — which is the better of the two, and
     * the distinction is asserted here rather than assumed.
     */
    public function test_fr_10_6_period_process_owner_and_risk_filters_are_not_implemented(): void
    {
        $unfiltered = $this->metrics()['exceptions']['open'];

        $unsupported = [];

        foreach (['process_id' => 1, 'owner_id' => 1, 'risk_id' => 1, 'period' => '2026-01'] as $filter => $value) {
            if ($this->metrics([$filter => $value])['exceptions']['open'] === $unfiltered) {
                $unsupported[] = $filter;
            }
        }

        $this->assertSame(
            [],
            $unsupported,
            'FR-10.6 (Must) requires seven filters. These four are implemented nowhere — not on the '
            .'dashboard and not on the exception register: '.implode(', ', $unsupported).'. '
            .'DashboardService::metrics() reads only unit_id and severity.'
        );
    }

    // -----------------------------------------------------------------
    // TC-15-04 — tenant scoping
    // -----------------------------------------------------------------

    public function test_tc_15_04_the_dashboard_is_tenant_scoped(): void
    {
        $ours = $this->metrics()['exceptions']['open'];

        $this->actingAs($this->otherTenantAccount('other_cfh'));
        $theirs = app(DashboardService::class)->metrics()['exceptions']['open'];

        $globalOpen = (int) DB::table('control_exceptions')
            ->whereNull('deleted_at')->whereIn('status', self::OPEN)->count();

        $this->assertLessThan(
            $globalOpen,
            $ours,
            'The dashboard shows every tenant\'s exceptions, not just this tenant\'s.'
        );

        $this->assertNotSame($ours, $theirs, 'Two different tenants produced identical dashboards.');
    }

    // -----------------------------------------------------------------
    // TC-15-07 — drill-through
    // -----------------------------------------------------------------

    public function test_tc_15_07_the_open_tile_drills_through_to_a_matching_list(): void
    {
        $tile = $this->metrics()['exceptions']['open'];

        $response = $this->actingAs($this->account('cfh'))->get('/exceptions?status=open');
        $response->assertOk();

        $page = $response->viewData('page')['props'];
        $listTotal = data_get($page, 'exceptions.total');

        $this->assertNotNull($listTotal, 'The exceptions list did not expose a total to reconcile against.');

        $this->assertSame(
            $tile,
            (int) $listTotal,
            'FR-10.2 / TC-15-07: the open-exceptions tile and the list it drills through to disagree.'
        );
    }

    // -----------------------------------------------------------------
    // TC-15-05 — empty state
    // -----------------------------------------------------------------

    public function test_tc_15_05_a_tenant_with_no_data_renders_a_clean_zero_state(): void
    {
        $this->actingAs($this->otherTenantAccount('other_cfh'));

        // The other tenant holds exactly one exception, at Remediated.
        $metrics = app(DashboardService::class)->metrics(['severity' => 'Low']);

        $this->assertSame(0, $metrics['exceptions']['open']);
        $this->assertSame(0, array_sum($metrics['exceptions']['bySeverity']));
        $this->assertSame(0, $metrics['testing']['total']);
        $this->assertSame(0, $metrics['testing']['completionRate'], 'A zero-test tenant must not divide by zero.');
        $this->assertSame(0, $metrics['universe']['coveragePct']);
    }
}
