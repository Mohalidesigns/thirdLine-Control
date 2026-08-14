<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\GhgDataPoint;
use App\Models\MaterialityAssessment;
use App\Models\SustainabilityFiling;
use App\Models\SustainabilityTopic;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SustainabilityService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SustainabilityTopicSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Phase 17.3 — IFRS S1/S2 sustainability controls. */
class SustainabilityReportingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(SustainabilityTopicSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->owner = $this->makeUser('owner@test.local', 'Control Owner');

        $this->actingAs($this->officer);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makeDataPoint(array $overrides = []): GhgDataPoint
    {
        return GhgDataPoint::create([
            'tenant_id' => $this->tenant->id,
            'scope' => '1',
            'category' => 'Stationary combustion — branch generators',
            'period_label' => '2026',
            'activity_data' => 120000,
            'activity_unit' => 'litres diesel',
            'emission_factor' => 0.002687,
            'emission_factor_source' => 'DEFRA 2025 conversion factors',
            'data_quality' => 'calculated',
            'methodology' => 'not_applicable',
            'status' => 'Draft',
            ...$overrides,
        ]);
    }

    // ── Filing stage arithmetic ──────────────────────────────────────

    public function test_the_three_filing_stages_compute_from_the_fiscal_year_start(): void
    {
        $stages = app(SustainabilityService::class)->computeStages('2028-01-01');

        $this->assertCount(3, $stages);

        // −3, +3 and +6 months from a 1 January year start.
        $this->assertSame('2027-10-01', $stages[0]['due_at']);
        $this->assertSame('pre_reporting_1', $stages[0]['key']);
        $this->assertSame('2028-04-01', $stages[1]['due_at']);
        $this->assertSame('2028-07-01', $stages[2]['due_at']);
    }

    public function test_a_non_december_year_end_gets_its_own_deadlines(): void
    {
        // A 1 July year start — the platform never assumes December (R7).
        $stages = app(SustainabilityService::class)->computeStages('2028-07-01');

        $this->assertSame('2028-04-01', $stages[0]['due_at']);
        $this->assertSame('2028-10-01', $stages[1]['due_at']);
        $this->assertSame('2029-01-01', $stages[2]['due_at']);
    }

    public function test_a_tenant_can_override_the_stage_schedule_without_a_deploy(): void
    {
        // The offsets are data, not code (R1).
        $this->tenant->update([
            'settings' => [
                'sustainability' => [
                    'filing_stage_offsets' => [
                        ['key' => 'single_stage', 'label' => 'Single filing', 'offset_months' => 9],
                    ],
                ],
            ],
        ]);

        $stages = app(SustainabilityService::class)->computeStages('2028-01-01', null, $this->tenant->id);

        $this->assertCount(1, $stages);
        $this->assertSame('2028-10-01', $stages[0]['due_at']);
    }

    public function test_a_filing_materialises_its_stages_and_ships_unverified(): void
    {
        $entity = Entity::create([
            'tenant_id' => $this->tenant->id, 'reference' => 'ENT-001',
            'legal_name' => 'Test Bank Nigeria', 'entity_type' => 'bank', 'jurisdiction' => 'NG',
        ]);

        $this->actingAs($this->cfh)
            ->post(route('sustainability.filings.store'), [
                'entity_id' => $entity->id,
                'reporting_class' => 'pie',
                'adoption_year' => 2028,
                'fiscal_year_start' => '2028-01-01',
                'period_label' => 'FY2028',
            ])
            ->assertRedirect();

        $filing = SustainabilityFiling::firstOrFail();

        $this->assertSame('unverified', $filing->verification_status);
        $this->assertCount(3, $filing->stages);
        $this->assertSame('2027-10-01', $filing->stages->first()->due_at->toDateString());

        // The schedule the deadlines came from is copied onto the filing, so
        // a later change to the tenant default cannot silently restate a
        // deadline that has already been communicated.
        $this->assertCount(3, $filing->filing_stage_offsets);
    }

    public function test_an_overdue_stage_is_marked_late(): void
    {
        $filing = app(SustainabilityService::class)->createFiling([
            'tenant_id' => $this->tenant->id,
            'fiscal_year_start' => now()->subYear()->toDateString(),
            'period_label' => 'FY'.now()->subYear()->year,
        ], $this->officer);

        $late = app(SustainabilityService::class)->refreshFilingStages($this->tenant->id);

        $this->assertGreaterThan(0, $late);
        $this->assertGreaterThan(0, $filing->stages()->where('status', 'Late')->count());
    }

    public function test_filing_a_stage_records_who_filed_it(): void
    {
        $filing = app(SustainabilityService::class)->createFiling([
            'tenant_id' => $this->tenant->id,
            'fiscal_year_start' => '2028-01-01',
            'period_label' => 'FY2028',
        ], $this->officer);

        $stage = $filing->stages()->first();

        $this->actingAs($this->cfh)
            ->post(route('sustainability.filings.stages.submit', [$filing, $stage]), [
                'submission_reference' => 'FRC/SUS/2027/0041',
            ])
            ->assertRedirect();

        $filed = $stage->fresh();
        $this->assertSame('Submitted', $filed->status);
        $this->assertSame($this->cfh->id, $filed->submitted_by);
        $this->assertSame('In Progress', $filing->fresh()->status);
    }

    // ── Materiality ──────────────────────────────────────────────────

    public function test_the_assessor_cannot_approve_their_own_materiality_determination(): void
    {
        $topic = SustainabilityTopic::where('code', 'S2-GHG-3')->firstOrFail();

        $this->post(route('sustainability.materiality.store'), [
            'topic_id' => $topic->id,
            'period_label' => '2026',
            'basis' => 'financial',
            'financial_materiality_score' => 80,
            'is_material' => true,
            'rationale' => 'Financed emissions dominate the loan book.',
        ])->assertRedirect();

        $assessment = MaterialityAssessment::firstOrFail();
        $this->assertSame('Submitted', $assessment->status);
        $this->assertSame($this->officer->id, $assessment->assessed_by);

        // The officer lacks 'approve materiality' entirely; the head of
        // control holds it — but not for their own assessment.
        $this->post(route('sustainability.materiality.approve', $assessment))->assertForbidden();

        $this->actingAs($this->cfh)
            ->post(route('sustainability.materiality.approve', $assessment))
            ->assertRedirect();

        $this->assertSame('Approved', $assessment->fresh()->status);
    }

    public function test_a_double_materiality_election_must_score_both_dimensions(): void
    {
        $topic = SustainabilityTopic::where('code', 'S2-GHG-1')->firstOrFail();

        $this->post(route('sustainability.materiality.store'), [
            'topic_id' => $topic->id,
            'period_label' => '2026',
            'basis' => 'double',
            'financial_materiality_score' => 70,
            'is_material' => true,
            'rationale' => 'Elected double materiality.',
        ])->assertSessionHasErrors('impact_materiality_score');
    }

    // ── GHG data ─────────────────────────────────────────────────────

    public function test_the_preparer_cannot_verify_their_own_figure(): void
    {
        $point = $this->makeDataPoint();
        $sustainability = app(SustainabilityService::class);

        $sustainability->prepareDataPoint($point, $this->officer, []);

        // 120,000 litres × 0.002687 = 322.44 tCO2e.
        $this->assertEqualsWithDelta(322.44, $point->fresh()->tco2e, 0.001);

        $this->expectException(ValidationException::class);
        $sustainability->verifyDataPoint($point->fresh(), $this->officer);
    }

    public function test_the_preparer_cannot_verify_their_own_figure_over_http(): void
    {
        $point = $this->makeDataPoint();

        // The head of control both prepares and tries to verify, holding
        // every permission there is (R2 — no admin bypass).
        $this->actingAs($this->cfh);
        app(SustainabilityService::class)->prepareDataPoint($point, $this->cfh, []);

        $this->post(route('sustainability.ghg.verify', $point))->assertForbidden();
        $this->assertSame('Prepared', $point->fresh()->status);

        $verifier = $this->makeUser('verifier@test.local', 'Control Function Head');

        $this->actingAs($verifier)
            ->post(route('sustainability.ghg.verify', $point), ['note' => 'Traced to the fuel purchase ledger.'])
            ->assertRedirect();

        $this->assertSame('Verified', $point->fresh()->status);
        $this->assertSame($verifier->id, $point->fresh()->verified_by);
    }

    public function test_only_scope_three_may_be_deferred(): void
    {
        $sustainability = app(SustainabilityService::class);

        $scope3 = $this->makeDataPoint(['scope' => '3', 'category' => 'Category 15: Investments']);
        $deferred = $sustainability->deferDataPoint($scope3, 'Transition relief taken in the first reporting period.');
        $this->assertTrue($deferred->is_deferred);
        $this->assertSame('Deferred', $deferred->status);

        // Scope 1 carries no such relief.
        $scope1 = $this->makeDataPoint();
        $this->expectException(ValidationException::class);
        $sustainability->deferDataPoint($scope1, 'Inconvenient.');
    }

    public function test_lineage_gaps_are_named_rather_than_silently_tolerated(): void
    {
        $point = $this->makeDataPoint(['emission_factor_source' => null]);

        $gaps = $point->lineageGaps();

        $this->assertContains('factor source', $gaps);
        $this->assertContains('control', $gaps);
        $this->assertContains('evidence', $gaps);
        $this->assertFalse($point->isAssurable());
    }

    public function test_the_inventory_keeps_verified_and_unverified_totals_apart(): void
    {
        $sustainability = app(SustainabilityService::class);

        $verified = $this->makeDataPoint();
        $sustainability->prepareDataPoint($verified, $this->officer, []);
        $sustainability->verifyDataPoint($verified->fresh(), $this->cfh);

        $unverified = $this->makeDataPoint(['scope' => '2', 'category' => 'Purchased electricity']);
        $sustainability->prepareDataPoint($unverified, $this->officer, []);

        $inventory = $sustainability->inventory('2026');

        $this->assertEqualsWithDelta(322.44, $inventory['verified_total'], 0.01);
        $this->assertEqualsWithDelta(322.44, $inventory['unverified_total'], 0.01);
        $this->assertEqualsWithDelta(322.44, $inventory['scopes']['1']['verified_tco2e'], 0.01);
        $this->assertSame(0.0, $inventory['scopes']['1']['unverified_tco2e']);
        $this->assertEqualsWithDelta(322.44, $inventory['scopes']['2']['unverified_tco2e'], 0.01);
    }

    public function test_readiness_names_everything_that_would_be_excluded_from_a_filing(): void
    {
        $topic = SustainabilityTopic::where('code', 'S2-GHG-1')->firstOrFail();

        MaterialityAssessment::create([
            'tenant_id' => $this->tenant->id,
            'topic_id' => $topic->id,
            'period_label' => '2026',
            'basis' => 'financial',
            'is_material' => true,
            'rationale' => 'Direct emissions from branch generators.',
            'status' => 'Submitted',
            'assessed_by' => $this->officer->id,
        ]);

        $readiness = app(SustainabilityService::class)->readiness('2026');

        $this->assertFalse($readiness['ready']);

        $reasons = collect($readiness['excluded'])->pluck('reason')->implode(' ');

        // Two independent reasons: the shipped topic has not been verified
        // against the standard (R10), and the determination has not been
        // approved by a second person (R2).
        $this->assertStringContainsString('not been verified against the standard', $reasons);
        $this->assertStringContainsString('not Approved', $reasons);
    }

    public function test_shipped_topics_ship_unverified_with_no_invented_citations(): void
    {
        $topics = SustainabilityTopic::global()->get();

        $this->assertGreaterThan(15, $topics->count());
        $this->assertSame(0, $topics->where('verification_status', 'verified')->count());
        // No paragraph references are guessed — a fabricated citation is
        // worse than none (R10).
        $this->assertSame(0, $topics->whereNotNull('standard_reference')->count());
    }

    public function test_the_sustainability_pages_render(): void
    {
        $this->get(route('sustainability.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Sustainability/Index')->has('topics'));

        $this->get(route('sustainability.ghg'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Sustainability/Ghg'));

        $this->get(route('sustainability.filings'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Sustainability/Filings'));
    }

    public function test_a_control_owner_prepares_but_never_verifies(): void
    {
        $this->actingAs($this->owner);

        $this->post(route('sustainability.ghg.store'), [
            'scope' => '1',
            'category' => 'Fleet fuel',
            'period_label' => '2026',
            'activity_data' => 5000,
            'activity_unit' => 'litres',
            'emission_factor' => 0.00231,
            'emission_factor_source' => 'DEFRA 2025',
            'data_quality' => 'calculated',
            'methodology' => 'not_applicable',
        ])->assertRedirect();

        $point = GhgDataPoint::where('category', 'Fleet fuel')->firstOrFail();
        $this->assertSame('Prepared', $point->status);

        $this->post(route('sustainability.ghg.verify', $point))->assertForbidden();
    }
}
