<?php

namespace Tests\Feature;

use App\Models\ControlEntity;
use App\Models\ControlUnit;
use App\Models\Investigation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ConsequenceService;
use App\Services\InvestigationDashboardService;
use App\Services\InvestigationService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The caseload dashboard (§E.4).
 *
 * Three properties matter more than any individual number: it is scoped to
 * the reader's own visibility before aggregation, it is isolated by tenant,
 * and it carries no subject PII anywhere. A count that leaks the existence
 * of a case is a leak.
 */
class InvestigationDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Tenant $otherTenant;

    private User $head;

    private User $officer;

    private User $outsider;

    private User $board;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->otherTenant = Tenant::create(['name' => 'Other Bank', 'status' => 'active']);

        $this->head = $this->makeUser('head@test.local', 'Control Function Head', $this->tenant);
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer', $this->tenant);
        $this->outsider = $this->makeUser('outsider@test.local', 'Control Officer', $this->tenant);
        $this->board = $this->makeUser('board@test.local', 'Executive Viewer', $this->tenant);
    }

    private function makeUser(string $email, string $role, Tenant $tenant): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $tenant->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function dashboard(User $user, array $filters = []): array
    {
        $this->actingAs($user);

        return app(InvestigationDashboardService::class)->build($user, $filters);
    }

    private function open(array $overrides = [], ?User $actor = null): Investigation
    {
        $actor ??= $this->officer;
        $this->actingAs($actor);

        return app(InvestigationService::class)->open([
            'title' => 'Cash difference',
            'category' => 'fraud',
            'source' => 'management_directive',
            'priority' => 'High',
            ...$overrides,
        ], $actor);
    }

    // ── Scoping ──────────────────────────────────────────────────────

    public function test_a_confidential_case_is_excluded_from_another_users_aggregates(): void
    {
        $this->open(['is_confidential' => true, 'confirmed_financial_loss' => 5000000]);

        $mine = $this->dashboard($this->officer);
        $theirs = $this->dashboard($this->outsider);

        $this->assertSame(1, $mine['kpis']['open_now']['value']);
        $this->assertSame(0, $theirs['kpis']['open_now']['value']);
        $this->assertSame(0.0, (float) $theirs['financials']['confirmed_loss']['value']);
        $this->assertSame([], $theirs['financials']['top_cases']);
    }

    public function test_tenant_isolation(): void
    {
        $this->open();

        $foreignOfficer = $this->makeUser('foreign@test.local', 'Control Officer', $this->otherTenant);

        $this->assertSame(0, $this->dashboard($foreignOfficer)['kpis']['open_now']['value']);
    }

    public function test_the_board_tier_reads_the_dashboard_and_never_the_register(): void
    {
        $investigation = $this->open();

        $this->assertTrue($this->board->can('view investigation-dashboard'));
        $this->assertFalse(
            $this->board->can('view investigations'),
            'The Executive Viewer sees the shape of the caseload, never a case file.',
        );

        $this->actingAs($this->board)->get(route('investigations.dashboard'))->assertOk();
        $this->actingAs($this->board)->get(route('investigations.index'))->assertForbidden();
        // 404, not 403: route-model binding runs inside the web group and
        // therefore before the permission middleware, and the visibility
        // scope has already made the record invisible. Not confirming a
        // case exists is the stronger of the two answers.
        $this->actingAs($this->board)->get(route('investigations.show', $investigation->id))->assertNotFound();
    }

    // ── No PII, anywhere ─────────────────────────────────────────────

    public function test_no_widget_payload_carries_subject_pii(): void
    {
        $investigation = $this->open(['confirmed_financial_loss' => 4200000]);

        app(InvestigationService::class)->addSubject($investigation, [
            'subject_type' => 'staff',
            'name' => 'Adebayo Olumide',
            'staff_id' => 'STF-9931',
            'account_number' => '0123456789',
            'role_in_case' => 'primary_subject',
        ], $this->officer);

        $payload = json_encode($this->dashboard($this->officer));

        $this->assertStringNotContainsString('Adebayo Olumide', $payload);
        $this->assertStringNotContainsString('STF-9931', $payload);
        $this->assertStringNotContainsString('0123456789', $payload);

        // The outcome is counted; the person it belongs to is not named.
        $this->assertArrayHasKey('pending', $this->dashboard($this->officer)['consequences']['subject_outcomes']);
    }

    public function test_top_cases_by_loss_returns_references_and_titles_only(): void
    {
        $this->open(['confirmed_financial_loss' => 4200000]);

        $top = $this->dashboard($this->officer)['financials']['top_cases'];

        $this->assertCount(1, $top);
        $this->assertSame(
            ['id', 'reference', 'title', 'category', 'loss', 'recovered', 'currency'],
            array_keys($top[0]),
        );
    }

    // ── The arithmetic ───────────────────────────────────────────────

    public function test_the_previous_period_comparison_is_the_same_length_immediately_before(): void
    {
        // One this month, two in the equivalent window before it.
        $this->open(['reported_date' => now()->startOfMonth()->addDays(2)->toDateString()]);
        $this->open(['reported_date' => now()->subMonthNoOverflow()->startOfMonth()->addDays(2)->toDateString()]);
        $this->open(['reported_date' => now()->subMonthNoOverflow()->startOfMonth()->addDays(5)->toDateString()]);

        $data = $this->dashboard($this->officer, ['period' => 'current_month']);

        $this->assertSame(1, $data['kpis']['opened']['value']);
        $this->assertSame(2, $data['kpis']['opened']['previous']);
        $this->assertSame(-50, $data['kpis']['opened']['change']);
    }

    public function test_a_suspended_case_gets_its_own_ageing_bucket_and_leaves_the_average_alone(): void
    {
        $service = app(InvestigationService::class);

        $old = $this->open(['reported_date' => now()->subDays(120)->toDateString()]);
        $service->transition($old, 'reported', $this->officer);
        $service->transition($old->refresh(), 'under_investigation', $this->officer);
        $service->transition($old->refresh(), 'suspended', $this->officer);

        $data = $this->dashboard($this->officer);
        $buckets = collect($data['ageing']['buckets'])->keyBy('bucket');

        $this->assertSame(1, $buckets['Suspended']['total']);
        $this->assertSame(
            0,
            $buckets['90+']['total'],
            'Six months waiting on a police report is not six months of nobody working.',
        );
        $this->assertSame(1, $data['kpis']['suspended']['value']);
    }

    public function test_the_twelve_month_trend_groups_by_month_on_this_driver(): void
    {
        $this->open(['reported_date' => now()->toDateString()]);
        $this->open(['reported_date' => now()->subMonthsNoOverflow(2)->toDateString()]);

        $trend = $this->dashboard($this->officer)['trend'];

        $this->assertCount(12, $trend);
        $this->assertSame(1, collect($trend)->firstWhere('month', now()->format('Y-m'))['opened']);
        $this->assertSame(1, collect($trend)->firstWhere('month', now()->subMonthsNoOverflow(2)->format('Y-m'))['opened']);
    }

    public function test_the_by_desk_cut_answers_which_branch_is_generating_cases(): void
    {
        $unit = ControlUnit::create([
            'tenant_id' => $this->tenant->id, 'code' => 'ICU', 'name' => 'ICU', 'domain' => 'branch',
        ]);

        $branch = ControlEntity::create([
            'tenant_id' => $this->tenant->id, 'control_unit_id' => $unit->id,
            'reference' => 'CE-042', 'name' => 'Branch 042', 'entity_kind' => 'branch',
        ]);

        $this->open(['control_entity_id' => $branch->id, 'confirmed_financial_loss' => 1500000]);
        $this->open(['control_entity_id' => $branch->id]);

        $byDesk = $this->dashboard($this->officer)['by_control_entity'];

        $this->assertCount(1, $byDesk);
        $this->assertSame('Branch 042', $byDesk[0]['name']);
        $this->assertSame(2, $byDesk[0]['total']);
        $this->assertSame(1500000.0, $byDesk[0]['loss']);
    }

    public function test_the_recovery_rate_is_recovered_over_confirmed_loss(): void
    {
        $investigation = $this->open(['confirmed_financial_loss' => 1000000]);

        $subject = app(InvestigationService::class)->addSubject($investigation, [
            'subject_type' => 'staff', 'name' => 'A. Teller', 'role_in_case' => 'primary_subject',
        ], $this->officer);

        $action = app(ConsequenceService::class)->recommend($investigation, [
            'action_type' => 'restitution_recovery', 'investigation_subject_id' => $subject->id,
        ], $this->officer);

        app(ConsequenceService::class)->implement(
            app(ConsequenceService::class)->approve($action, $this->head),
            $this->officer,
            ['amount_recovered' => 250000],
        );

        $financials = $this->dashboard($this->officer)['financials'];

        // Spec §6.2 — the rate carries one decimal now, because ₦2.1m
        // against ₦55m is 3.8% and rounding that to a whole number
        // overstates a recovery rate by a sixth. A clean quarter still
        // reads 25.0.
        $this->assertSame(25.0, $financials['recovery_rate']['value']);
        $this->assertSame(750000.0, (float) $financials['net_loss']['value']);
    }

    public function test_the_activity_feed_never_advertises_who_read_a_confidential_file(): void
    {
        $investigation = $this->open(['is_confidential' => true]);

        app(InvestigationService::class)->recordAccess($investigation, $this->head);

        // Spec §4 — the feed is paginated now, so it arrives as
        // {rows, page, pages, total, …} rather than a bare list.
        $feed = $this->dashboard($this->officer)['activity'];

        $this->assertNotContains('confidential_view', array_column($feed['rows'], 'activity_type'));
    }

    public function test_an_archived_case_leaves_every_count(): void
    {
        $service = app(InvestigationService::class);

        $investigation = $this->open();
        $service->transition($investigation, 'reported', $this->officer);
        $service->transition($investigation->refresh(), 'under_investigation', $this->officer);
        $service->transition($investigation->refresh(), 'pending_review', $this->officer);
        $service->addFinding($investigation->refresh(), [
            'title' => 'The matter duplicates an earlier case',
            'severity' => 'Low',
        ], $this->officer);
        $service->complete($investigation->refresh(), $this->officer, [
            'risk_rating' => 'Low',
            'conclusion' => 'Duplicate of an earlier matter; no further action.',
        ]);
        $service->archive($investigation->refresh(), $this->head, 'Duplicate of an earlier matter.');

        $data = $this->dashboard($this->officer);

        $this->assertSame(0, $data['kpis']['open_now']['value']);
        $this->assertSame(0, $data['kpis']['completed']['value']);
    }

    public function test_the_csv_export_carries_the_same_visibility_and_no_pii(): void
    {
        $investigation = $this->open(['is_confidential' => true]);

        app(InvestigationService::class)->addSubject($investigation, [
            'subject_type' => 'staff', 'name' => 'Adebayo Olumide', 'staff_id' => 'STF-9931',
            'role_in_case' => 'primary_subject',
        ], $this->officer);

        $this->actingAs($this->officer);
        $mine = app(InvestigationDashboardService::class)->exportRows($this->officer);

        $this->actingAs($this->outsider);
        $theirs = app(InvestigationDashboardService::class)->exportRows($this->outsider);

        $this->assertCount(2, $mine, 'Header plus the one case I can see.');
        $this->assertCount(1, $theirs, 'Header only — an export is not a back door.');
        $this->assertStringNotContainsString('Adebayo Olumide', json_encode($mine));
    }
}
