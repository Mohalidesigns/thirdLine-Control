<?php

namespace Tests\Feature;

use App\Dashboards\WidgetRegistry;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\Dashboard;
use App\Models\OrganisationUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DashboardBuilderService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemDashboardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DashboardBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    private User $owner;

    private OrganisationUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active', 'data_residency' => 'NG']);

        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->owner = $this->makeUser('owner@test.local', 'Control Owner');

        $this->actingAs($this->cfh);

        $this->unit = OrganisationUnit::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Operations', 'type' => 'Department', 'code' => 'OPS',
        ]);

        $this->makeControl('CTL-001', 'Effective');
        $this->makeControl('CTL-002', 'Ineffective');

        $this->makeException('Critical');
        $this->makeException('Critical');
        $this->makeException('High');
        $this->makeException('Low');
    }

    private function makeUser(string $email, string $role, ?Tenant $tenant = null): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => ($tenant ?? $this->tenant)->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makeControl(string $ref, string $operating, ?Tenant $tenant = null): Control
    {
        return Control::withoutGlobalScopes()->create([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'control_ref' => $ref,
            'title' => 'Dual authorisation '.$ref,
            'type' => 'Preventive',
            'nature' => 'Manual',
            'frequency' => 'Monthly',
            'is_key_control' => true,
            'status' => 'Active',
            'operating_effectiveness' => $operating,
            'design_effectiveness' => 'Adequate',
            'overall_rating' => $operating === 'Effective' ? 'Strong' : 'Weak',
            'unit_id' => $tenant ? null : $this->unit->id,
        ]);
    }

    private function makeException(string $severity, ?Tenant $tenant = null): ControlException
    {
        return ControlException::withoutGlobalScopes()->create([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'reference' => 'EXC-'.uniqid(),
            'source_type' => 'Manual',
            'title' => "A {$severity} failure",
            'severity' => $severity,
            'status' => 'Open',
            'date_raised' => now()->subDays(10),
            'age_days' => 10,
            'unit_id' => $tenant ? null : $this->unit->id,
        ]);
    }

    // ── The registry ─────────────────────────────────────────────────────

    public function test_every_registered_source_resolves_without_error(): void
    {
        $registry = app(WidgetRegistry::class);

        $this->assertNotEmpty($registry->keys());

        foreach ($registry->all() as $key => $source) {
            $payload = $registry->resolve($this->cfh, $source, []);

            $this->assertNotSame('error', $payload['kind'] ?? null, "Source '{$key}' failed to resolve.");
            $this->assertArrayHasKey('kind', $payload, "Source '{$key}' returned no kind.");
        }
    }

    /**
     * The negative half of the permission model, per source: a viewer without
     * the declared permission is refused rather than served a filtered view.
     */
    public function test_every_permissioned_source_refuses_a_user_without_its_permission(): void
    {
        $registry = app(WidgetRegistry::class);
        $checked = 0;

        foreach ($registry->all() as $key => $source) {
            if ($source->permission === null || $this->owner->can($source->permission)) {
                continue;
            }

            $checked++;

            $dashboard = Dashboard::create([
                'name' => 'Probe '.$key,
                'slug' => 'probe-'.str_replace('_', '-', $key),
                'owner_id' => $this->cfh->id,
                'visibility' => 'tenant',
            ]);

            $widget = $dashboard->widgets()->create([
                'widget_type' => 'stat',
                'title' => $key,
                'data_source_key' => $key,
            ]);

            $payload = $registry->resolveWidget($this->owner, $widget);

            $this->assertSame('denied', $payload['kind'], "Source '{$key}' leaked to a user without '{$source->permission}'.");
        }

        $this->assertGreaterThan(5, $checked, 'Expected several sources to be out of a Control Owner’s reach.');
    }

    public function test_a_tile_can_narrow_permission_further_but_never_widen_it(): void
    {
        $dashboard = Dashboard::create([
            'name' => 'Narrowed', 'slug' => 'narrowed',
            'owner_id' => $this->cfh->id, 'visibility' => 'tenant',
        ]);

        $widget = $dashboard->widgets()->create([
            'widget_type' => 'stat',
            'title' => 'Open exceptions',
            'data_source_key' => 'open_exceptions',
            'permission_required' => 'approve controls',
        ]);

        $registry = app(WidgetRegistry::class);

        // The officer may read exceptions but may not approve controls.
        $this->assertSame('denied', $registry->resolveWidget($this->officer, $widget)['kind']);
        $this->assertSame('scalar', $registry->resolveWidget($this->cfh, $widget)['kind']);
    }

    public function test_widget_data_is_scoped_to_the_viewers_tenant(): void
    {
        $other = Tenant::create(['name' => 'Other Bank', 'status' => 'active', 'data_residency' => 'NG']);
        $this->makeException('Critical', $other);
        $this->makeException('Critical', $other);

        $registry = app(WidgetRegistry::class);
        $payload = $registry->resolve($this->cfh, $registry->find('open_exceptions'));

        // Four in this tenant, two in the other. The other tenant's must not appear.
        $this->assertSame(4, $payload['value']);
    }

    public function test_an_unknown_data_source_key_resolves_to_an_error_not_a_query(): void
    {
        $dashboard = Dashboard::create([
            'name' => 'Broken', 'slug' => 'broken',
            'owner_id' => $this->cfh->id, 'visibility' => 'private',
        ]);

        $widget = $dashboard->widgets()->create([
            'widget_type' => 'stat', 'title' => 'Gone', 'data_source_key' => 'no_such_source',
        ]);

        $this->assertSame('error', app(WidgetRegistry::class)->resolveWidget($this->cfh, $widget)['kind']);
    }

    // ── Drill-down parity ────────────────────────────────────────────────

    /**
     * A drill-down must land on exactly the records its number counted. This
     * runs the aggregate, then follows its own drill target and compares the
     * paginated total — any drift between the two shows up here.
     */
    public function test_severity_drill_down_matches_the_aggregate_it_came_from(): void
    {
        $registry = app(WidgetRegistry::class);
        $payload = $registry->resolve($this->cfh, $registry->find('exceptions_by_severity'));

        foreach ($payload['categories'] as $index => $category) {
            $expected = $payload['series'][0]['values'][$index];
            $drill = $category['drill'];

            $response = $this->actingAs($this->cfh)->get(route($drill['route'], $drill['params']));
            $response->assertOk();

            $total = $response->viewData('page')['props']['exceptions']['total'];

            $this->assertSame(
                $expected,
                $total,
                "Drill-down for {$category['label']} returned {$total} records for an aggregate of {$expected}.",
            );
        }
    }

    public function test_control_health_drill_down_matches_the_aggregate_it_came_from(): void
    {
        $registry = app(WidgetRegistry::class);
        $payload = $registry->resolve($this->cfh, $registry->find('control_health'));

        foreach ($payload['categories'] as $index => $category) {
            if (! $category['drill']) {
                continue;
            }

            $expected = $payload['series'][0]['values'][$index];

            $response = $this->actingAs($this->cfh)->get(route($category['drill']['route'], $category['drill']['params']));
            $response->assertOk();

            $this->assertSame($expected, $response->viewData('page')['props']['controls']['total']);
        }
    }

    // ── Composition ──────────────────────────────────────────────────────

    public function test_a_widget_cannot_name_an_unregistered_data_source(): void
    {
        $this->expectException(ValidationException::class);

        app(DashboardBuilderService::class)->create([
            'name' => 'Hand-rolled',
            'widgets' => [[
                'widget_type' => 'stat',
                'title' => 'Anything',
                'data_source_key' => 'select * from users',
            ]],
        ], $this->cfh);
    }

    public function test_sharing_a_dashboard_beyond_yourself_needs_the_publish_permission(): void
    {
        $this->actingAs($this->officer);

        $this->expectException(ValidationException::class);

        app(DashboardBuilderService::class)->create(
            ['name' => 'Team board', 'visibility' => 'tenant'],
            $this->officer,
        );
    }

    public function test_a_private_dashboard_is_invisible_to_everyone_else(): void
    {
        $mine = app(DashboardBuilderService::class)->create(['name' => 'Mine'], $this->officer);

        $this->assertTrue($this->officer->can('view', $mine));
        $this->assertFalse($this->cfh->can('view', $mine));

        $this->actingAs($this->cfh)->get(route('dashboards.show', $mine->slug))->assertForbidden();
    }

    public function test_a_shipped_dashboard_cannot_be_edited_or_deleted(): void
    {
        $this->seed(SystemDashboardSeeder::class);

        $system = Dashboard::withoutGlobalScopes()->where('is_system', true)->firstOrFail();

        $this->assertFalse($this->cfh->can('update', $system));
        $this->assertFalse($this->cfh->can('delete', $system));

        $this->actingAs($this->cfh)
            ->delete(route('dashboards.destroy', $system->slug))
            ->assertForbidden();
    }

    public function test_a_widget_cap_stops_a_dashboard_becoming_unloadable(): void
    {
        config(['dashboards.max_widgets' => 2]);

        $dashboard = app(DashboardBuilderService::class)->create(['name' => 'Capped'], $this->cfh);
        $builder = app(DashboardBuilderService::class);

        $builder->addWidget($dashboard, ['widget_type' => 'stat', 'title' => 'One', 'data_source_key' => 'open_exceptions'], $this->cfh);
        $builder->addWidget($dashboard, ['widget_type' => 'stat', 'title' => 'Two', 'data_source_key' => 'overdue_tests'], $this->cfh);

        $this->expectException(ValidationException::class);
        $builder->addWidget($dashboard, ['widget_type' => 'stat', 'title' => 'Three', 'data_source_key' => 'key_control_count'], $this->cfh);
    }

    // ── Performance ──────────────────────────────────────────────────────

    /**
     * The N+1 assertion that actually means something: render the whole
     * executive dashboard, add an order of magnitude more rows, render it
     * again, and require the query count NOT to move.
     *
     * A ceiling on the absolute count would only encode whatever the code
     * does today. Invariance to row count is the property that keeps this
     * page usable on a slow connection as a register grows (R6).
     */
    public function test_dashboard_query_count_does_not_grow_with_the_number_of_records(): void
    {
        $this->seed(SystemDashboardSeeder::class);

        $dashboard = Dashboard::withoutGlobalScopes()->where('slug', 'executive-board')->firstOrFail();
        $this->assertGreaterThan(5, $dashboard->widgets()->count());

        // One warm-up render first: the permission registrar and the model
        // caches load on first use, and that fixed cost is not what this test
        // is about.
        $this->countQueriesRendering($dashboard);
        $baseline = $this->countQueriesRendering($dashboard);

        for ($i = 0; $i < 30; $i++) {
            $this->makeException(['Critical', 'High', 'Medium', 'Low'][$i % 4]);
            $this->makeControl('CTL-BULK-'.$i, $i % 2 ? 'Effective' : 'Ineffective');
        }

        $grown = $this->countQueriesRendering($dashboard);

        $this->assertSame(
            $baseline,
            $grown,
            "Rendering took {$baseline} queries over 4 exceptions and {$grown} over 34 — that is an N+1.",
        );
    }

    private function countQueriesRendering(Dashboard $dashboard): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        app(DashboardBuilderService::class)->render($dashboard, $this->cfh);

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_the_landing_dashboard_falls_back_when_no_configurable_board_exists(): void
    {
        $this->actingAs($this->cfh)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard'));
    }

    public function test_the_landing_dashboard_uses_the_role_default_when_one_is_seeded(): void
    {
        $this->seed(SystemDashboardSeeder::class);

        $this->actingAs($this->cfh)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboards/Show'));
    }
}
