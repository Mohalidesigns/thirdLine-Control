<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\Metric;
use App\Models\Risk;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LinkageService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RiskTaxonomySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LinkageGraphTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $officer;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->seed(RiskTaxonomySeeder::class);

        $this->officer = User::factory()->create(['email' => 'officer@test.local', 'tenant_id' => $this->tenant->id]);
        $this->officer->assignRole('Control Officer');

        $this->viewer = User::factory()->create(['email' => 'viewer@test.local', 'tenant_id' => $this->tenant->id]);
        $this->viewer->assignRole('Executive Viewer');

        $this->actingAs($this->officer);
    }

    private function makeRisk(string $title = 'Unauthorised payment release'): Risk
    {
        return Risk::create([
            'tenant_id' => $this->tenant->id,
            'code' => Risk::nextReference('RSK', false, 'code'),
            'title' => $title,
            'inherent_likelihood' => 3,
            'inherent_impact' => 3,
            'inherent_rating' => 9,
            'status' => 'active',
        ]);
    }

    private function makeControl(): Control
    {
        return Control::create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => Control::nextReference('CTL', false, 'control_ref'),
            'title' => 'Maker-checker on transfers',
            'type' => 'Preventive',
            'nature' => 'Manual',
            'frequency' => 'Monthly',
            'status' => 'Active',
        ]);
    }

    private function makeMetric(): Metric
    {
        return Metric::create([
            'tenant_id' => $this->tenant->id,
            'metric_type' => 'KRI',
            'code' => 'KRI-'.strtoupper(substr(md5((string) mt_rand()), 0, 6)),
            'name' => 'Attempted fraud cases',
            'data_type' => 'count',
            'direction' => 'lower_is_better',
            'frequency' => 'Monthly',
            'source' => 'manual',
            'aggregation' => 'last',
        ]);
    }

    public function test_it_links_two_records_and_reads_the_edge_from_both_ends(): void
    {
        $risk = $this->makeRisk();
        $control = $this->makeControl();

        app(LinkageService::class)->link('control', $control->id, 'risk', $risk->id, 'mitigates', 4, 'Primary control');

        $fromRisk = app(LinkageService::class)->neighbours('risk', $risk->id);
        $fromControl = app(LinkageService::class)->neighbours('control', $control->id);

        $this->assertCount(1, $fromRisk);
        $this->assertSame('incoming', $fromRisk[0]['direction']);
        $this->assertSame($control->control_ref, $fromRisk[0]['ref']);
        $this->assertSame('Control', $fromRisk[0]['type_label']);

        $this->assertCount(1, $fromControl);
        $this->assertSame('outgoing', $fromControl[0]['direction']);
    }

    public function test_linking_is_idempotent(): void
    {
        $risk = $this->makeRisk();
        $control = $this->makeControl();
        $service = app(LinkageService::class);

        $first = $service->link('control', $control->id, 'risk', $risk->id, 'mitigates');
        $second = $service->link('control', $control->id, 'risk', $risk->id, 'mitigates');

        $this->assertSame($first->id, $second->id);
    }

    public function test_a_record_cannot_be_linked_to_itself(): void
    {
        $risk = $this->makeRisk();

        $this->expectException(ValidationException::class);
        app(LinkageService::class)->link('risk', $risk->id, 'risk', $risk->id);
    }

    public function test_an_unknown_node_type_is_rejected(): void
    {
        $risk = $this->makeRisk();

        $this->expectException(ValidationException::class);
        app(LinkageService::class)->link('risk', $risk->id, 'unicorn', 1);
    }

    public function test_an_unknown_relationship_is_rejected(): void
    {
        $risk = $this->makeRisk();
        $control = $this->makeControl();

        $this->expectException(ValidationException::class);
        app(LinkageService::class)->link('control', $control->id, 'risk', $risk->id, 'destroys');
    }

    public function test_the_graph_walks_two_hops(): void
    {
        $risk = $this->makeRisk();
        $control = $this->makeControl();
        $metric = $this->makeMetric();
        $service = app(LinkageService::class);

        // risk ← control, and control ← metric: the metric is two hops from
        // the risk and must appear at depth 2, not depth 1.
        $service->link('control', $control->id, 'risk', $risk->id, 'mitigates');
        $service->link('metric', $metric->id, 'control', $control->id, 'indicates');

        $oneHop = $service->graph('risk', $risk->id, 1);
        $twoHops = $service->graph('risk', $risk->id, 2);

        $this->assertCount(2, $oneHop['nodes']);
        $this->assertCount(3, $twoHops['nodes']);

        $metricNode = collect($twoHops['nodes'])->firstWhere('type', 'metric');
        $this->assertSame(2, $metricNode['depth']);
        $this->assertSame('metrics.show', $metricNode['route']);

        $root = collect($twoHops['nodes'])->firstWhere('is_root', true);
        $this->assertSame($risk->code, $root['ref']);
    }

    public function test_the_node_cap_is_reported_rather_than_silently_applied(): void
    {
        $risk = $this->makeRisk();
        $service = app(LinkageService::class);

        for ($i = 0; $i < 6; $i++) {
            $service->link('control', $this->makeControl()->id, 'risk', $risk->id, 'mitigates');
        }

        $capped = $service->graph('risk', $risk->id, 2, maxNodes: 4);

        $this->assertTrue($capped['truncated'], 'A truncated graph must say so.');
        $this->assertLessThanOrEqual(4, count($capped['nodes']));

        foreach ($capped['edges'] as $edge) {
            $keys = array_column($capped['nodes'], 'key');
            $this->assertContains($edge['source'], $keys);
            $this->assertContains($edge['target'], $keys);
        }
    }

    public function test_candidates_are_searchable_for_the_picker(): void
    {
        $this->makeRisk('Teller cash misappropriation');
        $this->makeRisk('Unreconciled suspense build-up');

        $matches = app(LinkageService::class)->candidates('risk', 'suspense');

        $this->assertCount(1, $matches);
        $this->assertStringContainsString('suspense', $matches[0]['title']);
    }

    public function test_linking_requires_the_manage_linkage_permission(): void
    {
        $risk = $this->makeRisk();
        $control = $this->makeControl();

        $this->actingAs($this->viewer)
            ->post(route('links.store'), [
                'source_type' => 'control',
                'source_id' => $control->id,
                'target_type' => 'risk',
                'target_id' => $risk->id,
                'relationship' => 'mitigates',
            ])
            ->assertForbidden();

        $this->actingAs($this->officer)
            ->post(route('links.store'), [
                'source_type' => 'control',
                'source_id' => $control->id,
                'target_type' => 'risk',
                'target_id' => $risk->id,
                'relationship' => 'mitigates',
            ])
            ->assertRedirect();
    }

    public function test_the_graph_endpoint_returns_server_computed_adjacency(): void
    {
        $risk = $this->makeRisk();
        $control = $this->makeControl();
        app(LinkageService::class)->link('control', $control->id, 'risk', $risk->id, 'mitigates');

        $this->getJson(route('links.graph', ['type' => 'risk', 'id' => $risk->id]))
            ->assertOk()
            ->assertJsonStructure(['nodes', 'edges', 'truncated', 'hops'])
            ->assertJsonCount(2, 'nodes')
            ->assertJsonCount(1, 'edges');
    }
}
