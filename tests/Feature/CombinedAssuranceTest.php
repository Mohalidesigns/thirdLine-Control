<?php

namespace Tests\Feature;

use App\Models\AssuranceActivity;
use App\Models\AssuranceProvider;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\IntegrationConfig;
use App\Models\Risk;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AssuranceService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Phase 17.4/17.5 — combined assurance map and the internal-audit interlock. */
class CombinedAssuranceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    private string $apiKey = 'slk_test_key_0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        IntegrationConfig::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'target_system' => 'ThirdLine',
            'sync_direction' => 'Bidirectional',
            'master_system' => 'SecondLine',
            'inbound_key_hash' => hash('sha256', $this->apiKey),
            'is_active' => true,
        ]);

        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');

        $this->actingAs($this->officer);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makeRisk(string $code, string $band = 'High'): Risk
    {
        return Risk::create([
            'tenant_id' => $this->tenant->id,
            'code' => $code,
            'title' => "Risk {$code}",
            'category' => 'Operational',
            'current_rating_band' => $band,
            'risk_owner_id' => $this->officer->id,
            'status' => 'Active',
        ]);
    }

    private function makeProvider(string $code, string $line, bool $independent = false): AssuranceProvider
    {
        return AssuranceProvider::create([
            'tenant_id' => $this->tenant->id,
            'code' => $code,
            'name' => "{$code} function",
            'line' => $line,
            'is_independent' => $independent,
            'is_active' => true,
        ]);
    }

    private function makeActivity(AssuranceProvider $provider, string $type, int $id, array $overrides = []): AssuranceActivity
    {
        return AssuranceActivity::create([
            'tenant_id' => $this->tenant->id,
            'provider_id' => $provider->id,
            'subject_type' => $type,
            'subject_id' => $id,
            'coverage_level' => 'full',
            'assurance_type' => 'limited',
            'last_assurance_date' => now()->subMonths(2)->toDateString(),
            'next_assurance_date' => now()->addMonths(10)->toDateString(),
            'period_label' => 'FY2026',
            'conclusion' => 'satisfactory',
            'source' => 'manual',
            ...$overrides,
        ]);
    }

    // ── Gaps ─────────────────────────────────────────────────────────

    public function test_the_map_identifies_a_significant_risk_with_no_independent_assurance(): void
    {
        $covered = $this->makeRisk('RSK-001');
        $gap = $this->makeRisk('RSK-002');
        $lowGap = $this->makeRisk('RSK-003', 'Low');

        $ia = $this->makeProvider('IA', 'internal_audit');
        $this->makeActivity($ia, 'risk', $covered->id);

        // RSK-003 is also unassured but is not significant, so it is not a
        // gap in the sense a board cares about.
        $map = app(AssuranceService::class)->map($this->tenant->id);

        $codes = collect($map['gaps'])->pluck('code');

        $this->assertContains('RSK-002', $codes);
        $this->assertNotContains('RSK-001', $codes);
        $this->assertNotContains('RSK-003', $codes);
        $this->assertSame('No assurance provider covers this risk.', collect($map['gaps'])->firstWhere('code', 'RSK-002')['reason']);
        $this->assertSame($gap->id, collect($map['gaps'])->firstWhere('code', 'RSK-002')['risk_id']);
        $this->assertSame('Low', $lowGap->current_rating_band);
    }

    public function test_coverage_by_management_alone_is_still_a_gap(): void
    {
        $risk = $this->makeRisk('RSK-010');

        // Management assuring its own process is coverage, but it is not
        // independent assurance — and the map keeps the two apart.
        $management = $this->makeProvider('MGT', 'management');
        $this->makeActivity($management, 'risk', $risk->id, ['assurance_type' => 'self_assessment']);

        $map = app(AssuranceService::class)->map($this->tenant->id);

        $gap = collect($map['gaps'])->firstWhere('code', 'RSK-010');

        $this->assertNotNull($gap);
        $this->assertStringContainsString('non-independent', $gap['reason']);
    }

    public function test_out_of_date_assurance_does_not_count_as_cover(): void
    {
        $risk = $this->makeRisk('RSK-011');
        $ia = $this->makeProvider('IA', 'internal_audit');

        $this->makeActivity($ia, 'risk', $risk->id, [
            'last_assurance_date' => now()->subYears(3)->toDateString(),
            'next_assurance_date' => now()->subYear()->toDateString(),
        ]);

        $map = app(AssuranceService::class)->map($this->tenant->id);

        $this->assertNotNull(collect($map['gaps'])->firstWhere('code', 'RSK-011'));
        $this->assertSame(1, collect($map['rows'])->firstWhere('code', 'RSK-011')['stale_count']);
    }

    public function test_assurance_recorded_on_a_control_covers_the_risk_it_mitigates(): void
    {
        $risk = $this->makeRisk('RSK-020');

        $control = Control::create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-020',
            'title' => 'Daily reconciliation',
            'type' => 'Detective',
            'nature' => 'Manual',
            'frequency' => 'Daily',
            'status' => 'Active',
        ]);

        DB::table('risk_control_map')->insert(['risk_id' => $risk->id, 'control_id' => $control->id]);

        $ia = $this->makeProvider('IA', 'internal_audit');
        $this->makeActivity($ia, 'control', $control->id);

        $map = app(AssuranceService::class)->map($this->tenant->id);

        $this->assertEmpty($map['gaps']);
        $this->assertSame(1, collect($map['rows'])->firstWhere('code', 'RSK-020')['independent_count']);
    }

    public function test_a_significant_gap_is_raised_as_an_exception_once_only(): void
    {
        $this->makeRisk('RSK-030', 'Critical');

        $assurance = app(AssuranceService::class);
        $first = $assurance->escalateGaps($this->tenant->id);

        $this->assertSame(1, $first['gaps']);
        $this->assertSame(1, $first['raised']);

        $exception = ControlException::where('source_type', 'Assurance')->firstOrFail();
        $this->assertStringContainsString('RSK-030', $exception->title);
        $this->assertSame('High', $exception->severity);

        // A second nightly run must not raise a duplicate.
        $second = $assurance->escalateGaps($this->tenant->id);
        $this->assertSame(0, $second['raised']);
        $this->assertSame(1, ControlException::where('source_type', 'Assurance')->count());
    }

    // ── Duplication ──────────────────────────────────────────────────

    public function test_the_map_identifies_three_providers_testing_the_same_control(): void
    {
        $control = Control::create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-040',
            'title' => 'Privileged access review',
            'type' => 'Detective',
            'nature' => 'Manual',
            'frequency' => 'Monthly',
            'status' => 'Active',
        ]);

        foreach ([['IA', 'internal_audit'], ['SL', 'second_line'], ['EA', 'external_audit']] as [$code, $line]) {
            $this->makeActivity($this->makeProvider($code, $line), 'control', $control->id);
        }

        // A second control with only two providers must not be flagged.
        $other = Control::create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-041',
            'title' => 'Journal review',
            'type' => 'Detective',
            'nature' => 'Manual',
            'frequency' => 'Monthly',
            'status' => 'Active',
        ]);

        $this->makeActivity(AssuranceProvider::where('code', 'IA')->firstOrFail(), 'control', $other->id);
        $this->makeActivity(AssuranceProvider::where('code', 'SL')->firstOrFail(), 'control', $other->id);

        $map = app(AssuranceService::class)->map($this->tenant->id);

        $this->assertCount(1, $map['duplication']);
        $this->assertSame('CTL-040', $map['duplication'][0]['ref']);
        $this->assertSame(3, $map['duplication'][0]['provider_count']);
        $this->assertSame('Control', $map['duplication'][0]['subject_label']);
    }

    /**
     * The ordinary three-lines shape — management self-assesses, second line
     * tests, internal audit audits — is healthy, not duplicated effort, and
     * must not be reported as duplication on every risk in the register.
     */
    public function test_management_self_assessment_does_not_count_toward_duplication(): void
    {
        $risk = $this->makeRisk('RSK-045');

        $this->makeActivity($this->makeProvider('MGT', 'management'), 'risk', $risk->id, [
            'assurance_type' => 'self_assessment',
        ]);
        $this->makeActivity($this->makeProvider('CTRL', 'second_line'), 'risk', $risk->id);
        $this->makeActivity($this->makeProvider('IA', 'internal_audit'), 'risk', $risk->id);

        // Three providers, but only two of them are testing.
        $this->assertEmpty(app(AssuranceService::class)->map($this->tenant->id)['duplication']);

        // A third tester tips it into genuine duplication.
        $this->makeActivity($this->makeProvider('EA', 'external_audit'), 'risk', $risk->id);

        $duplication = app(AssuranceService::class)->map($this->tenant->id)['duplication'];
        $this->assertCount(1, $duplication);
        $this->assertSame(3, $duplication[0]['provider_count']);
    }

    public function test_the_duplication_threshold_is_tenant_configuration(): void
    {
        $control = Control::create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-050',
            'title' => 'Vendor payment approval',
            'type' => 'Preventive',
            'nature' => 'Manual',
            'frequency' => 'Daily',
            'status' => 'Active',
        ]);

        $this->makeActivity($this->makeProvider('IA', 'internal_audit'), 'control', $control->id);
        $this->makeActivity($this->makeProvider('SL', 'second_line'), 'control', $control->id);

        // Two providers is below the shipped threshold of three…
        $this->assertEmpty(app(AssuranceService::class)->map($this->tenant->id)['duplication']);

        // …but a tenant that considers two duplication says so in settings,
        // not in a code change (R1).
        $this->tenant->update(['settings' => ['assurance' => ['duplication_threshold' => 2]]]);

        $this->assertCount(1, app(AssuranceService::class)->map($this->tenant->id)['duplication']);
    }

    // ── Reliance ─────────────────────────────────────────────────────

    public function test_reliance_cannot_be_placed_on_work_that_has_not_concluded(): void
    {
        $risk = $this->makeRisk('RSK-060');
        $activity = $this->makeActivity(
            $this->makeProvider('IA', 'internal_audit'),
            'risk',
            $risk->id,
            ['conclusion' => 'not_concluded'],
        );

        $this->expectException(ValidationException::class);
        app(AssuranceService::class)->recordReliance($activity, $this->cfh, ['reliance_level' => 'full']);
    }

    public function test_recording_reliance_needs_its_own_permission(): void
    {
        $risk = $this->makeRisk('RSK-061');
        $activity = $this->makeActivity($this->makeProvider('IA', 'internal_audit'), 'risk', $risk->id);

        // The Control Officer maintains the map but does not decide what the
        // board may rely on.
        $this->post(route('assurance.activities.reliance', $activity), [
            'reliance_level' => 'moderate',
            'reliance_rationale' => 'Scope and methodology reviewed.',
        ])->assertForbidden();

        $this->actingAs($this->cfh)
            ->post(route('assurance.activities.reliance', $activity), [
                'reliance_level' => 'moderate',
                'reliance_rationale' => 'Scope and methodology reviewed.',
            ])
            ->assertRedirect();

        $recorded = $activity->fresh();
        $this->assertSame('moderate', $recorded->reliance_level);
        $this->assertSame($this->cfh->id, $recorded->reliance_recorded_by);
    }

    // ── Interlock (17.5) ─────────────────────────────────────────────

    public function test_an_integration_round_trip_preserves_the_reliance_decision(): void
    {
        $risk = $this->makeRisk('RSK-070');
        $this->makeProvider('THIRDLINE-IA', 'internal_audit', true);

        // Third line publishes its coverage and its reliance decision…
        $this->postJson('/api/v1/assurance/activities', [
            'provider_code' => 'THIRDLINE-IA',
            'subject_type' => 'risk',
            'subject_ref' => 'RSK-070',
            'activity_name' => 'FY2026 payments audit',
            'coverage_level' => 'full',
            'assurance_type' => 'reasonable',
            'period_label' => 'FY2026',
            'last_assurance_date' => now()->subMonth()->toDateString(),
            'next_assurance_date' => now()->addMonths(11)->toDateString(),
            'conclusion' => 'satisfactory',
            'reliance_level' => 'full',
            'reliance_rationale' => 'Second-line testing reperformed on a sample basis.',
            'external_ref' => 'TL-ENG-2026-014',
        ], ['X-Api-Key' => $this->apiKey])->assertCreated();

        $activity = AssuranceActivity::withoutGlobalScopes()->where('external_ref', 'TL-ENG-2026-014')->firstOrFail();

        $this->assertSame('full', $activity->reliance_level);
        $this->assertSame('integration', $activity->source);
        $this->assertSame($risk->id, $activity->subject_id);

        // …and reads it back out unchanged.
        $response = $this->getJson('/api/v1/assurance/coverage', ['X-Api-Key' => $this->apiKey])->assertOk();

        $returned = collect($response->json('data'))->firstWhere('external_ref', 'TL-ENG-2026-014');

        $this->assertSame('full', $returned['reliance_level']);
        $this->assertSame('Second-line testing reperformed on a sample basis.', $returned['reliance_rationale']);
        $this->assertSame('RSK-070', $returned['subject_ref']);
        $this->assertSame('THIRDLINE-IA', $returned['provider_code']);

        // Re-posting the same record updates it rather than duplicating.
        $this->postJson('/api/v1/assurance/activities', [
            'provider_code' => 'THIRDLINE-IA',
            'subject_type' => 'risk',
            'subject_ref' => 'RSK-070',
            'period_label' => 'FY2026',
            'coverage_level' => 'partial',
            'conclusion' => 'needs_improvement',
            'reliance_level' => 'limited',
            'external_ref' => 'TL-ENG-2026-014',
        ], ['X-Api-Key' => $this->apiKey])->assertCreated();

        $this->assertSame(1, AssuranceActivity::withoutGlobalScopes()->where('external_ref', 'TL-ENG-2026-014')->count());
        $this->assertSame('limited', $activity->fresh()->reliance_level);
    }

    public function test_an_inbound_record_cannot_conjure_an_assurance_provider(): void
    {
        $this->makeRisk('RSK-071');

        // An integration key must not be able to manufacture board-level
        // comfort by inventing a provider nobody registered.
        $this->postJson('/api/v1/assurance/activities', [
            'provider_code' => 'GHOST-AUDITOR',
            'subject_type' => 'risk',
            'subject_ref' => 'RSK-071',
            'coverage_level' => 'full',
            'conclusion' => 'satisfactory',
            'reliance_level' => 'full',
        ], ['X-Api-Key' => $this->apiKey])->assertStatus(422);

        $this->assertSame(0, AssuranceActivity::withoutGlobalScopes()->count());
        $this->assertSame(0, AssuranceProvider::withoutGlobalScopes()->count());
    }

    public function test_an_audit_finding_becomes_a_control_exception(): void
    {
        Control::create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-080',
            'title' => 'Change management approval',
            'type' => 'Preventive',
            'nature' => 'Manual',
            'frequency' => 'Continuous',
            'status' => 'Active',
        ]);

        $this->postJson('/api/v1/assurance/findings', [
            'external_ref' => 'TL-FND-2026-003',
            'title' => 'Emergency changes bypass approval',
            'description' => 'Nine of forty emergency changes had no post-implementation approval.',
            'severity' => 'High',
            'control_ref' => 'CTL-080',
        ], ['X-Api-Key' => $this->apiKey])->assertCreated();

        $exception = ControlException::withoutGlobalScopes()->where('external_ref', 'TL-FND-2026-003')->firstOrFail();

        $this->assertSame('Internal Audit', $exception->source_type);
        $this->assertSame('High', $exception->severity);
        $this->assertSame('Open', $exception->status);
        $this->assertNotNull($exception->control_id);
        $this->assertStringStartsWith('EXC-', $exception->reference);

        // A retry from the audit system updates rather than doubling the
        // exception count.
        $this->postJson('/api/v1/assurance/findings', [
            'external_ref' => 'TL-FND-2026-003',
            'title' => 'Emergency changes bypass approval',
            'severity' => 'Critical',
            'control_ref' => 'CTL-080',
        ], ['X-Api-Key' => $this->apiKey])->assertOk();

        $this->assertSame(1, ControlException::withoutGlobalScopes()->where('external_ref', 'TL-FND-2026-003')->count());
        $this->assertSame('Critical', $exception->fresh()->severity);
    }

    public function test_the_gaps_endpoint_is_scoped_to_the_key_holder(): void
    {
        $this->makeRisk('RSK-090', 'Critical');

        $this->getJson('/api/v1/assurance/gaps')->assertStatus(401);

        $this->getJson('/api/v1/assurance/gaps', ['X-Api-Key' => $this->apiKey])
            ->assertOk()
            ->assertJsonPath('data.0.code', 'RSK-090')
            ->assertJsonPath('meta.duplication_threshold', 3);
    }

    public function test_the_third_party_register_is_readable_over_the_interlock(): void
    {
        Vendor::create([
            'tenant_id' => $this->tenant->id,
            'reference' => 'TP-001',
            'legal_name' => 'Interswitch Limited',
            'category' => 'Payment processing',
            'criticality' => 'Critical',
            'jurisdiction' => 'NG',
            'spend_currency' => 'NGN',
            'annual_spend_minor' => 60_000_000_000,
            'data_access_classification' => 'personal',
            'status' => 'Active',
        ]);

        $this->getJson('/api/v1/third-parties', ['X-Api-Key' => $this->apiKey])
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'TP-001')
            ->assertJsonPath('data.0.criticality', 'Critical')
            ->assertJsonPath('data.0.due_diligence', null);
    }

    public function test_the_assurance_map_page_renders(): void
    {
        $this->makeRisk('RSK-100');
        $this->makeProvider('IA', 'internal_audit');

        $this->get(route('assurance.map'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Assurance/Map')->has('map.providers', 1));
    }

    /**
     * The map is a matrix, so a query per cell would be catastrophic — it
     * has to resolve in a fixed number of queries regardless of how many
     * risks and providers it draws (R6, no N+1).
     */
    public function test_the_assurance_map_stays_within_the_query_budget(): void
    {
        $providers = collect([
            ['MGT', 'management'], ['CTRL', 'second_line'], ['COMP', 'second_line'],
            ['IA', 'internal_audit'], ['EA', 'external_audit'],
        ])->map(fn (array $spec) => $this->makeProvider($spec[0], $spec[1]));

        for ($i = 1; $i <= 40; $i++) {
            $risk = $this->makeRisk('RSK-B'.str_pad((string) $i, 3, '0', STR_PAD_LEFT));

            foreach ($providers->take(3) as $provider) {
                $this->makeActivity($provider, 'risk', $risk->id);
            }
        }

        DB::enableQueryLog();
        $this->get(route('assurance.map'))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            40,
            $queries,
            "Assurance map ran {$queries} queries at 40 risks × 5 providers — budget is <40; look for an N+1.",
        );
    }
}
