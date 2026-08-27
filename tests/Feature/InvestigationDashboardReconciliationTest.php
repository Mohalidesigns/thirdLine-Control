<?php

namespace Tests\Feature;

use App\Models\Investigation;
use App\Models\InvestigationActivity;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvestigationDashboardService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec §6.2 and §8.4 — the dashboard figures.
 *
 * The specification's five-case pack is used here as a FIXTURE, not as
 * seed data: the demo pack this product ships stays as it is (a deliberate
 * choice — it exercises the module rather than reproducing another
 * product's screenshots). What §6.2 is genuinely useful for is that its
 * numbers pin down the DEFINITIONS, and the definitions are what a
 * dashboard lives or dies by:
 *
 *   - a draft is overdue but not outstanding;
 *   - "no open cases" is true of a register holding three drafts;
 *   - recovery rate is of confirmed loss, not of exposure;
 *   - the risk donut counts completed cases only.
 *
 * Every figure below is quoted from §6.2 and asserted exactly.
 */
class InvestigationDashboardReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $head;

    /** The specification's reference period. */
    private const FROM = '2026-01-01';

    private const TO = '2026-08-26';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        // Every date in §6.2 is relative to 26 Aug 2026, and "overdue"
        // compares against today — so today has to be that day.
        $this->travelTo('2026-08-26 09:00:00');

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->head = User::factory()->create([
            'email' => 'head@test.local', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);
        $this->head->assignRole('Control Function Head');

        $this->seedSpecPack();
    }

    /** §6.1 — the five cases, exactly as specified. */
    private function seedSpecPack(): void
    {
        $make = fn (array $attributes) => Investigation::withoutEvents(
            fn () => Investigation::create([
                'tenant_id' => $this->tenant->id,
                'lead_investigator_id' => $this->head->id,
                'created_by' => $this->head->id,
                'currency' => 'NGN',
                ...$attributes,
            ])
        );

        $make([
            'reference' => 'INV-2026-0001', 'title' => 'Missing office equipment',
            'category' => 'asset_misappropriation', 'source' => 'anonymous_tip',
            'status' => 'completed', 'priority' => 'Medium', 'risk_rating' => 'High',
            'reported_date' => '2026-07-28', 'target_completion_date' => '2026-10-13',
            'completed_date' => '2026-08-13',
            'estimated_financial_impact' => 32_900_000, 'confirmed_financial_loss' => 50_000_000,
            'amount_recovered' => 2_000_000,
        ]);

        $make([
            'reference' => 'INV-2026-0002', 'title' => 'CBN query on suspicious transactions',
            'category' => 'regulatory_directive', 'source' => 'regulator',
            'status' => 'draft', 'priority' => 'High',
            'reported_date' => '2026-07-11', 'target_completion_date' => '2026-08-10',
            'estimated_financial_impact' => 20_000_000,
        ]);

        $make([
            'reference' => 'INV-2026-0003', 'title' => 'Special review of expense claims',
            'category' => 'other', 'source' => 'management_directive',
            'status' => 'closed', 'priority' => 'Low', 'risk_rating' => 'Moderate',
            'reported_date' => '2026-06-24', 'target_completion_date' => '2026-09-24',
            'completed_date' => '2026-08-12',
            'estimated_financial_impact' => 5_000_000, 'confirmed_financial_loss' => 5_000_000,
            'amount_recovered' => 100_000,
        ]);

        $make([
            'reference' => 'INV-2026-0004', 'title' => 'Special review of expense claims',
            'category' => 'other', 'source' => 'management_directive',
            'status' => 'draft', 'priority' => 'Medium',
            'reported_date' => '2026-06-19', 'target_completion_date' => '2026-08-19',
            'estimated_financial_impact' => 15_000_000,
        ]);

        $make([
            'reference' => 'INV-2026-0005', 'title' => 'Special review of expense claims',
            'category' => 'other', 'source' => 'management_directive',
            'status' => 'draft', 'priority' => 'Critical',
            'reported_date' => '2026-06-06', 'target_completion_date' => '2026-12-06',
            'estimated_financial_impact' => 7_900_000,
        ]);
    }

    /** @return array<string, mixed> */
    private function dashboard(array $filters = []): array
    {
        $this->actingAs($this->head);

        return app(InvestigationDashboardService::class)->build($this->head, $filters ?: [
            'from' => self::FROM, 'to' => self::TO,
        ]);
    }

    // ── §6.2 — the KPI row ───────────────────────────────────────────

    public function test_the_kpi_row_matches_the_specification(): void
    {
        $kpis = $this->dashboard()['kpis'];

        $this->assertSame(5, $kpis['opened']['value'], 'Total cases');
        $this->assertSame(2, $kpis['completed']['value'], 'Completed');

        $this->assertSame(
            0,
            $kpis['outstanding']['value'],
            'Outstanding excludes drafts — three drafts must not read as three outstanding cases.',
        );

        $this->assertSame(
            2,
            $kpis['overdue']['value'],
            'Overdue includes drafts: INV-2026-0002 and -0004 are both past their target date.',
        );

        // (16 + 49) ÷ 2 = 32.5 → 33
        $this->assertSame(33, $kpis['average_days_to_close']['value'], 'Avg days to close');
        $this->assertSame(0, $kpis['archived']['value'], 'Archived');
    }

    public function test_outstanding_and_overdue_are_deliberately_different_populations(): void
    {
        $kpis = $this->dashboard()['kpis'];

        // The same three drafts are counted by one and not the other. This
        // is the single most confusable pair on the dashboard, so it is
        // asserted as a relationship and not only as two numbers.
        $this->assertSame(0, $kpis['outstanding']['value']);
        $this->assertGreaterThan(
            $kpis['outstanding']['value'],
            $kpis['overdue']['value'],
            'A register of drafts past their date has overdue work and no outstanding work.',
        );
    }

    // ── §6.2 — financials ────────────────────────────────────────────

    public function test_the_financial_figures_match_the_specification(): void
    {
        $financials = $this->dashboard()['financials'];

        // 32.9 + 20 + 5 + 15 + 7.9 = 80.8m
        $this->assertSame(80_800_000.0, $financials['estimated_exposure']['value'], 'Estimated exposure');
        $this->assertSame(55_000_000.0, $financials['confirmed_loss']['value'], 'Confirmed loss');
        $this->assertSame(2_100_000.0, $financials['recovered']['value'], 'Recovered');
        $this->assertSame(52_900_000.0, $financials['net_loss']['value'], 'Net loss');

        $this->assertSame(
            3.8,
            $financials['recovery_rate']['value'],
            'Recovery rate is of CONFIRMED LOSS and carries a decimal: 2.1 ÷ 55 is 3.8%, and 4% overstates it.',
        );
    }

    public function test_confirmed_loss_by_category_and_top_cases_match(): void
    {
        $financials = $this->dashboard()['financials'];

        $byCategory = collect($financials['by_category'])->pluck('loss', 'category');

        $this->assertSame(50_000_000.0, $byCategory['asset_misappropriation']);
        $this->assertSame(5_000_000.0, $byCategory['other']);

        $top = collect($financials['top_cases'])->values();

        $this->assertSame('INV-2026-0001', $top[0]['reference']);
        $this->assertSame(50_000_000.0, $top[0]['loss']);
        $this->assertSame('INV-2026-0003', $top[1]['reference']);
        $this->assertSame(5_000_000.0, $top[1]['loss']);
    }

    // ── §6.2 — distributions ─────────────────────────────────────────

    public function test_the_risk_donut_counts_completed_cases_only(): void
    {
        $ratings = collect($this->dashboard()['risk_distribution'])->pluck('total', 'rating');

        $this->assertSame(0, $ratings['Low']);
        $this->assertSame(1, $ratings['Moderate']);
        $this->assertSame(1, $ratings['High']);
        $this->assertSame(0, $ratings['Critical']);

        $this->assertSame(
            2,
            $ratings->sum(),
            'Only the two finished cases carry a rating; the three drafts must not appear.',
        );
    }

    public function test_cases_by_category_matches(): void
    {
        // by_category is already keyed category => total.
        $byCategory = $this->dashboard()['by_category'];

        $this->assertSame(3, $byCategory['other']);
        $this->assertSame(1, $byCategory['asset_misappropriation']);
        $this->assertSame(1, $byCategory['regulatory_directive']);
    }

    public function test_the_trend_reports_the_right_months(): void
    {
        $trend = collect($this->dashboard()['trend'])->keyBy('month');

        $this->assertSame(3, $trend['2026-06']['opened'], 'Three reported in June');
        $this->assertSame(2, $trend['2026-07']['opened'], 'Two reported in July');
        $this->assertSame(2, $trend['2026-08']['completed'], 'Two completed in August');
    }

    // ── §6.2 — the ageing empty state ────────────────────────────────

    public function test_ageing_is_empty_when_nothing_is_outstanding(): void
    {
        $ageing = $this->dashboard()['ageing'];

        $total = collect($ageing['buckets'])->sum('total');

        $this->assertSame(
            0,
            $total,
            'Three drafts are not "open cases" — §6.2 expects the empty state on exactly this register.',
        );
    }

    // ── §8.4 — the period filter genuinely re-queries ────────────────

    public function test_narrowing_the_period_drops_the_cases_outside_it(): void
    {
        $wide = $this->dashboard(['from' => self::FROM, 'to' => self::TO]);

        // §8.4's own worked example: FROM 01 Jul 2026 must drop the three
        // June-reported cases from the trend and the category counts.
        $narrow = $this->dashboard(['from' => '2026-07-01', 'to' => self::TO]);

        $this->assertSame(5, $wide['kpis']['opened']['value']);
        $this->assertSame(2, $narrow['kpis']['opened']['value'], 'Only July onwards remains.');

        $wideCategories = $wide['by_category'];
        $narrowCategories = $narrow['by_category'];

        $this->assertSame(3, $wideCategories['other']);
        $this->assertSame(
            null,
            $narrowCategories['other'] ?? null,
            'All three "other" cases were reported in June and must leave the breakdown entirely.',
        );
    }

    public function test_every_period_scoped_widget_moves_with_the_range(): void
    {
        $wide = $this->dashboard(['from' => self::FROM, 'to' => self::TO]);

        // A period before anything happened: every period-scoped figure
        // must empty out. A widget that ignores the filter shows the same
        // number here as above, which is the failure being hunted.
        $empty = $this->dashboard(['from' => '2025-01-01', 'to' => '2025-06-30']);

        $this->assertSame(0, $empty['kpis']['opened']['value'], 'Total cases');
        $this->assertSame(0, $empty['kpis']['completed']['value'], 'Completed');
        $this->assertNull($empty['kpis']['average_days_to_close']['value'], 'Avg days to close');
        $this->assertSame(0.0, $empty['financials']['estimated_exposure']['value'], 'Estimated exposure');
        $this->assertSame(0.0, $empty['financials']['confirmed_loss']['value'], 'Confirmed loss');
        $this->assertSame(0.0, $empty['financials']['recovered']['value'], 'Recovered');
        $this->assertNull($empty['financials']['recovery_rate']['value'], 'Recovery rate');
        $this->assertEmpty($empty['financials']['top_cases'], 'Top cases by loss');
        $this->assertEmpty($empty['by_category'], 'Cases by category');
        $this->assertSame(0, collect($empty['risk_distribution'])->sum('total'), 'Risk distribution');

        // And the wide period is not empty, so the assertions above are
        // measuring the filter rather than an empty database.
        $this->assertSame(5, $wide['kpis']['opened']['value']);
    }

    // ── §4 — the activity timeline ───────────────────────────────────

    /** Put $count comment entries on the first case, dated within the period. */
    private function seedActivity(int $count, string $type = 'comment', string $date = '2026-08-01 09:00:00'): void
    {
        $investigation = Investigation::withoutGlobalScopes()->firstOrFail();

        foreach (range(1, $count) as $i) {
            InvestigationActivity::create([
                'tenant_id' => $this->tenant->id,
                'investigation_id' => $investigation->id,
                'activity_type' => $type,
                'title' => "Entry {$i}",
                'activity_date' => $date,
                'performed_by' => $this->head->id,
            ]);
        }
    }

    public function test_the_timeline_pages_at_twenty(): void
    {
        $this->seedActivity(45);

        $first = $this->dashboard()['activity'];

        $this->assertSame(20, $first['per_page']);
        $this->assertCount(20, $first['rows'], 'A page is twenty rows.');
        $this->assertSame(45, $first['total']);
        $this->assertSame(3, $first['pages']);
        $this->assertSame(1, $first['page']);

        $last = $this->dashboard(['from' => self::FROM, 'to' => self::TO, 'activity_page' => 3])['activity'];

        $this->assertSame(3, $last['page']);
        $this->assertCount(5, $last['rows'], 'The last page carries the remainder.');
    }

    public function test_a_page_past_the_end_clamps_to_the_last_page(): void
    {
        $this->seedActivity(25);

        $feed = $this->dashboard(['from' => self::FROM, 'to' => self::TO, 'activity_page' => 99])['activity'];

        $this->assertSame(2, $feed['page'], 'A page number past the end shows the last page.');
        $this->assertNotEmpty($feed['rows'], 'and never an empty table with working paging.');
    }

    public function test_the_timeline_filters_by_activity_type(): void
    {
        $this->seedActivity(3, 'comment');
        $this->seedActivity(2, 'evidence_collected');

        $all = $this->dashboard()['activity'];
        $this->assertSame(5, $all['total']);
        $this->assertNull($all['activity_type']);

        $filtered = $this->dashboard([
            'from' => self::FROM, 'to' => self::TO, 'activity_type' => 'evidence_collected',
        ])['activity'];

        $this->assertSame(2, $filtered['total']);
        $this->assertSame('evidence_collected', $filtered['activity_type']);
        $this->assertSame(
            ['evidence_collected', 'evidence_collected'],
            array_column($filtered['rows'], 'activity_type'),
        );
    }

    public function test_an_unknown_activity_type_is_ignored_rather_than_returning_nothing(): void
    {
        $this->seedActivity(4);

        $feed = $this->dashboard(['from' => self::FROM, 'to' => self::TO, 'activity_type' => 'nonsense'])['activity'];

        $this->assertNull($feed['activity_type']);
        $this->assertSame(4, $feed['total'], 'A junk filter falls back to all types, not to an empty feed.');
    }

    /**
     * The gap this fixes: the feed used to return the most recent fifteen
     * events regardless of the range, so a dashboard filtered to one
     * quarter showed another quarter's activity beneath its figures.
     */
    public function test_the_timeline_is_scoped_to_the_period(): void
    {
        $this->seedActivity(3, 'comment', '2026-08-01 09:00:00');
        $this->seedActivity(2, 'comment', '2026-02-01 09:00:00');

        $whole = $this->dashboard(['from' => self::FROM, 'to' => self::TO])['activity'];
        $this->assertSame(5, $whole['total']);

        $julyOnwards = $this->dashboard(['from' => '2026-07-01', 'to' => self::TO])['activity'];
        $this->assertSame(3, $julyOnwards['total'], 'February is outside the range and must not appear.');

        $nothing = $this->dashboard(['from' => '2025-01-01', 'to' => '2025-06-30'])['activity'];
        $this->assertSame(0, $nothing['total']);
        $this->assertEmpty($nothing['rows']);
    }

    /**
     * Reading a confidential file is oversight of the case, not news — and
     * listing it would advertise who is reading what.
     */
    public function test_a_confidential_view_never_reaches_the_timeline(): void
    {
        $this->seedActivity(2, 'comment');
        $this->seedActivity(3, 'confidential_view');

        $feed = $this->dashboard()['activity'];

        $this->assertSame(2, $feed['total']);
        $this->assertNotContains('confidential_view', array_column($feed['rows'], 'activity_type'));
        $this->assertNotContains('confidential_view', $feed['types'], 'nor is it offered as a filter.');
    }

    /**
     * The three tiles that are deliberately NOT period-scoped: they answer
     * "what is the state of the register right now", and a state-of-play
     * number that changes with a reporting window is a bug, not a feature.
     */
    public function test_the_state_of_play_tiles_ignore_the_period(): void
    {
        $wide = $this->dashboard(['from' => self::FROM, 'to' => self::TO])['kpis'];
        $empty = $this->dashboard(['from' => '2025-01-01', 'to' => '2025-06-30'])['kpis'];

        foreach (['outstanding', 'overdue', 'archived', 'open_now', 'suspended'] as $tile) {
            $this->assertSame(
                $wide[$tile]['value'],
                $empty[$tile]['value'],
                "{$tile} reports the register as it stands and must not move with the period.",
            );
        }
    }
}
