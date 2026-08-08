<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\EffectivenessRating;
use App\Models\Risk;
use App\Models\RiskAssessmentScale;
use App\Models\Tenant;
use App\Models\TestInstance;
use App\Models\User;
use App\Services\ResidualRiskService;
use App\Services\RiskAssessmentService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RiskTaxonomySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RiskAssessmentTest extends TestCase
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

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->seed(RiskTaxonomySeeder::class);

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

    private function makeRisk(int $likelihood = 4, int $impact = 5): Risk
    {
        return Risk::create([
            'tenant_id' => $this->tenant->id,
            'code' => Risk::nextReference('RSK', false, 'code'),
            'title' => 'Unauthorised payment release',
            'inherent_likelihood' => $likelihood,
            'inherent_impact' => $impact,
            'inherent_rating' => $likelihood * $impact,
            'risk_owner_id' => $this->owner->id,
            'status' => 'active',
        ]);
    }

    private function makeControl(string $rating = 'Effective'): Control
    {
        $control = Control::create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => Control::nextReference('CTL', false, 'control_ref'),
            'title' => 'Maker-checker on transfers',
            'type' => 'Preventive',
            'nature' => 'Manual',
            'frequency' => 'Monthly',
            'status' => 'Active',
            'owner_id' => $this->owner->id,
        ]);

        $instance = TestInstance::create([
            'tenant_id' => $this->tenant->id,
            'control_id' => $control->id,
            'reference' => TestInstance::nextReference('TST'),
            'period_label' => '2026-Q3',
            'period_start' => now()->startOfQuarter()->toDateString(),
            'period_end' => now()->endOfQuarter()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'assigned_tester_id' => $this->officer->id,
            'status' => 'Reviewed',
        ]);

        EffectivenessRating::create([
            'tenant_id' => $this->tenant->id,
            'control_id' => $control->id,
            'test_instance_id' => $instance->id,
            'period_label' => '2026-Q3',
            'design_effectiveness' => $rating,
            'operating_effectiveness' => $rating,
            'overall_rating' => $rating,
            'status' => 'Published',
            'rated_by' => $this->officer->id,
            'approved_by' => $this->cfh->id,
            'approved_at' => now(),
        ]);

        return $control;
    }

    // ── Backward compatibility with the phase 0-6 engine ─────────────

    public function test_residual_matches_the_legacy_residual_risk_service(): void
    {
        $risk = $this->makeRisk();
        $control = $this->makeControl('Effective');
        $risk->controls()->attach($control->id, ['contribution_weight' => 1, 'mapped_at' => now()]);

        app(ResidualRiskService::class)->recompute($risk);
        $legacy = $risk->fresh()->residual_rating;

        app(RiskAssessmentService::class)->recomputeResidual($risk->fresh());

        $this->assertSame(
            (float) $legacy,
            (float) $risk->fresh()->residual_rating,
            'Phase 10 must not change the residual number the phase 0-6 engine produces.',
        );
    }

    public function test_the_twenty_percent_of_inherent_floor_still_holds(): void
    {
        $service = app(RiskAssessmentService::class);

        foreach ([['Effective', 1], ['Partially Effective', 1], ['Ineffective', 1], ['Effective', 5]] as [$rating, $weight]) {
            $risk = $this->makeRisk(5, 5);
            $control = $this->makeControl($rating);
            $risk->controls()->attach($control->id, ['contribution_weight' => $weight, 'mapped_at' => now()]);

            $service->recomputeResidual($risk->fresh());

            $this->assertGreaterThanOrEqual(
                $risk->inherent_rating * 0.2,
                (float) $risk->fresh()->residual_rating,
                "Residual fell below the 20% floor for a {$rating} control.",
            );
        }
    }

    public function test_a_risk_with_no_control_keeps_its_inherent_position(): void
    {
        $risk = $this->makeRisk(3, 4);

        app(RiskAssessmentService::class)->recomputeResidual($risk);

        $this->assertSame(12.0, (float) $risk->fresh()->residual_rating);
    }

    // ── Multi-dimensional impact ─────────────────────────────────────

    public function test_multi_dimensional_impact_keeps_the_driving_dimension_visible(): void
    {
        $risk = $this->makeRisk();

        $assessment = app(RiskAssessmentService::class)->record($risk, [
            'assessment_type' => 'inherent',
            'likelihood_level' => 3,
            'impact_level' => 2,
            'impact_dimensions' => [
                'financial' => 2,
                'regulatory' => 5,
                'reputational' => 3,
                'people' => 1,
            ],
        ], $this->officer);

        $this->assertSame('regulatory', $assessment->driving_dimension);
        $this->assertGreaterThanOrEqual(
            4,
            $assessment->impact_level,
            'A Critical regulatory impact must not be averaged away.',
        );
    }

    // ── Second-line review (R2) ──────────────────────────────────────

    public function test_a_high_scoring_assessment_needs_second_line_review(): void
    {
        $risk = $this->makeRisk();
        $service = app(RiskAssessmentService::class);

        $assessment = $service->record($risk, [
            'assessment_type' => 'residual',
            'likelihood_level' => 5,
            'impact_level' => 5,
        ], $this->officer);

        $this->assertTrue($service->requiresSecondLineReview($assessment));
        $this->assertSame('Draft', $assessment->status);
    }

    public function test_the_assessor_cannot_publish_their_own_high_scoring_assessment(): void
    {
        $risk = $this->makeRisk();
        $service = app(RiskAssessmentService::class);

        $assessment = $service->record($risk, [
            'assessment_type' => 'residual',
            'likelihood_level' => 5,
            'impact_level' => 5,
        ], $this->officer);

        $service->submitForReview($assessment);

        $this->expectException(ValidationException::class);
        $service->publish($assessment->fresh(), $this->officer);
    }

    public function test_a_system_administrator_gets_no_bypass_on_second_line_review(): void
    {
        $admin = $this->makeUser('admin@test.local', 'System Administrator');
        $risk = $this->makeRisk();
        $service = app(RiskAssessmentService::class);

        // The administrator is the assessor here — the rule is about who made
        // the assessment, not what role they hold.
        $assessment = $service->record($risk, [
            'assessment_type' => 'residual',
            'likelihood_level' => 5,
            'impact_level' => 5,
        ], $admin);

        $this->expectException(ValidationException::class);
        $service->publish($assessment->fresh(), $admin);
    }

    public function test_a_low_scoring_assessment_publishes_without_review(): void
    {
        $risk = $this->makeRisk(1, 2);
        $service = app(RiskAssessmentService::class);

        $assessment = $service->record($risk, [
            'assessment_type' => 'residual',
            'likelihood_level' => 1,
            'impact_level' => 2,
        ], $this->officer);

        $this->assertFalse($service->requiresSecondLineReview($assessment));

        $service->publish($assessment, $this->officer);

        $this->assertSame('Published', $assessment->fresh()->status);
        $this->assertSame('Low', $assessment->fresh()->rating);
    }

    public function test_publishing_pushes_the_position_onto_the_risk(): void
    {
        $risk = $this->makeRisk();
        $service = app(RiskAssessmentService::class);

        $target = $service->record($risk, [
            'assessment_type' => 'target',
            'likelihood_level' => 2,
            'impact_level' => 3,
        ], $this->officer);

        $service->publish($target, $this->cfh);
        $risk->refresh();

        $this->assertSame(2, $risk->target_likelihood);
        $this->assertSame(3, $risk->target_impact);
        $this->assertSame(6.0, (float) $risk->target_rating);
    }

    // ── Quantitative path ────────────────────────────────────────────

    public function test_quantitative_inputs_run_the_simulation_and_are_reproducible(): void
    {
        $risk = $this->makeRisk();
        $service = app(RiskAssessmentService::class);

        $assessment = $service->record($risk, [
            'assessment_type' => 'inherent',
            'likelihood_level' => 4,
            'impact_level' => 5,
            'loss_min_minor' => 5_000_000_00,
            'loss_likely_minor' => 45_000_000_00,
            'loss_max_minor' => 250_000_000_00,
            'likelihood_percent' => 35,
            'currency' => 'NGN',
        ], $this->officer);

        $assessment->refresh();

        $this->assertNotNull($assessment->expected_loss_minor);
        $this->assertNotNull($assessment->var_95_minor);
        $this->assertNotNull($assessment->var_99_minor);
        $this->assertIsArray($assessment->loss_curve);
        $this->assertSame('monte_carlo', $assessment->method);

        $rerun = $service->simulate($assessment->fresh(), 4242);
        $again = $service->simulate($assessment->fresh(), 4242);

        $this->assertSame($rerun['var_99_minor'], $again['var_99_minor']);
    }

    // ── Bands are data, not code ─────────────────────────────────────

    public function test_band_cut_offs_come_from_the_seeded_scale_rows(): void
    {
        $service = app(RiskAssessmentService::class);
        $tenantId = $this->tenant->id;

        $this->assertSame('Low', $service->bandFor(4, $tenantId));
        $this->assertSame('Moderate', $service->bandFor(9, $tenantId));
        $this->assertSame('High', $service->bandFor(15, $tenantId));
        $this->assertSame('Critical', $service->bandFor(20, $tenantId));

        // Re-band the register in data alone and the answer changes.
        RiskAssessmentScale::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('scale_type', 'rating_band')
            ->where('level', 1)
            ->update(['score_to' => 10]);

        $this->assertSame('Low', $service->bandFor(9, $tenantId));
    }
}
