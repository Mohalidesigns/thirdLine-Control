<?php

namespace Tests\Feature;

use App\Models\Risk;
use App\Models\RiskAssessmentScale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\HeatmapService;
use App\Services\RiskAssessmentService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RiskTaxonomySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiskHeatmapTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->seed(RiskTaxonomySeeder::class);

        $this->officer = User::factory()->create(['email' => 'officer@test.local', 'tenant_id' => $this->tenant->id]);
        $this->officer->assignRole('Control Officer');

        $this->actingAs($this->officer);
    }

    private function makeRisk(int $likelihood, int $impact, array $overrides = []): Risk
    {
        return Risk::create([
            'tenant_id' => $this->tenant->id,
            'code' => Risk::nextReference('RSK', false, 'code'),
            'title' => "Risk L{$likelihood} I{$impact}",
            'inherent_likelihood' => $likelihood,
            'inherent_impact' => $impact,
            'inherent_rating' => $likelihood * $impact,
            'residual_likelihood' => $likelihood,
            'residual_impact' => $impact,
            'residual_rating' => $likelihood * $impact,
            'status' => 'active',
            ...$overrides,
        ]);
    }

    public function test_the_default_grid_comes_from_the_seeded_five_by_five_scales(): void
    {
        $this->makeRisk(4, 5);

        $heatmap = app(HeatmapService::class)->build($this->tenant->id, 'residual');

        $this->assertCount(5, $heatmap['likelihood']);
        $this->assertCount(5, $heatmap['impact']);
        $this->assertCount(25, $heatmap['cells']);
        $this->assertSame(1, $heatmap['plotted']);
    }

    /**
     * The phase brief's requirement: a tenant switching to a 4×4 matrix
     * changes rows in risk_assessment_scales, and nothing else.
     */
    public function test_a_custom_four_by_four_scale_needs_no_code_change(): void
    {
        RiskAssessmentScale::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('scale_type', ['likelihood', 'impact'])
            ->delete();

        foreach (['likelihood', 'impact'] as $type) {
            foreach (['Remote', 'Occasional', 'Probable', 'Frequent'] as $index => $label) {
                RiskAssessmentScale::withoutGlobalScopes()->create([
                    'tenant_id' => $this->tenant->id,
                    'scale_type' => $type,
                    'level' => $index + 1,
                    'label' => $label,
                    'colour_hex' => '#e1e0d9',
                    'sort_order' => $index + 1,
                ]);
            }
        }

        // Re-band for the smaller grid (max score 16) — data, not code.
        RiskAssessmentScale::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('scale_type', 'rating_band')
            ->delete();

        foreach ([[1, 'Low', 4], [2, 'Moderate', 8], [3, 'High', 12], [4, 'Critical', null]] as [$level, $label, $to]) {
            RiskAssessmentScale::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id,
                'scale_type' => 'rating_band',
                'level' => $level,
                'label' => $label,
                'score_from' => null,
                'score_to' => $to,
                'colour_hex' => '#0ca30c',
                'sort_order' => $level,
            ]);
        }

        $this->makeRisk(4, 4);
        $this->makeRisk(1, 2);

        $heatmap = app(HeatmapService::class)->build($this->tenant->id, 'residual');

        $this->assertCount(4, $heatmap['likelihood']);
        $this->assertCount(4, $heatmap['impact']);
        $this->assertCount(16, $heatmap['cells']);
        $this->assertSame('Remote', $heatmap['likelihood'][0]['label']);

        $topCell = collect($heatmap['cells'])->firstWhere(fn ($cell) => $cell['likelihood'] === 4 && $cell['impact'] === 4);
        $this->assertSame('Critical', $topCell['band']);
        $this->assertSame(1, $topCell['count']);

        $this->assertSame(16, app(RiskAssessmentService::class)->maxScore($this->tenant->id));
        $this->assertSame('Low', app(RiskAssessmentService::class)->bandFor(4, $this->tenant->id));
    }

    public function test_every_cell_carries_a_band_label_beside_its_colour(): void
    {
        $heatmap = app(HeatmapService::class)->build($this->tenant->id, 'residual');

        foreach ($heatmap['cells'] as $cell) {
            $this->assertNotEmpty($cell['band'], 'A cell must never rely on colour alone.');
            $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $cell['colour']);
        }

        $this->assertCount(4, $heatmap['bands']);
    }

    public function test_the_movement_view_pairs_residual_with_target(): void
    {
        $this->makeRisk(5, 5, ['target_likelihood' => 2, 'target_impact' => 3, 'target_rating' => 6]);
        $this->makeRisk(3, 3);

        $heatmap = app(HeatmapService::class)->build($this->tenant->id, 'movement');

        $this->assertCount(1, $heatmap['movement']);
        $this->assertSame(5, $heatmap['movement'][0]['from']['likelihood']);
        $this->assertSame(2, $heatmap['movement'][0]['to']['likelihood']);
        $this->assertSame(19.0, (float) $heatmap['movement'][0]['gap']);
    }

    public function test_filters_narrow_the_plotted_set(): void
    {
        $this->makeRisk(4, 4, ['appetite_breached' => true]);
        $this->makeRisk(2, 2);

        $all = app(HeatmapService::class)->build($this->tenant->id, 'residual');
        $breaching = app(HeatmapService::class)->build($this->tenant->id, 'residual', ['breach_only' => 1]);

        $this->assertSame(2, $all['plotted']);
        $this->assertSame(1, $breaching['plotted']);
    }

    public function test_the_heatmap_page_renders_for_an_authorised_user(): void
    {
        $this->makeRisk(3, 3);

        $this->get(route('risks.heatmap'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Risks/Heatmap')->has('heatmap.cells', 25));
    }

    public function test_a_cell_drills_through_to_the_risks_behind_it(): void
    {
        $risk = $this->makeRisk(3, 3);

        $this->get(route('risks.heatmap.cell', ['likelihood' => 3, 'impact' => 3]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Risks/CellDrilldown')->has('risks.data', 1));

        $this->assertSame(3, $risk->residual_likelihood);
    }
}
