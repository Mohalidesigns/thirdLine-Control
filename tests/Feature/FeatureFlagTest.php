<?php

namespace Tests\Feature;

use App\Models\FeatureFlag;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin->assignRole('System Administrator');

        $this->officer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->officer->assignRole('Control Officer');
    }

    private function features(): FeatureService
    {
        $service = app(FeatureService::class);
        $service->flush();

        return $service;
    }

    public function test_unknown_flag_is_disabled_by_default(): void
    {
        $this->assertFalse($this->features()->enabled('nonexistent', $this->admin));
    }

    public function test_global_flag_applies_to_all_tenants(): void
    {
        FeatureFlag::create(['tenant_id' => null, 'key' => 'sso', 'is_enabled' => true]);

        $this->assertTrue($this->features()->enabled('sso', $this->admin));
    }

    public function test_tenant_override_beats_global_default(): void
    {
        FeatureFlag::create(['tenant_id' => null, 'key' => 'sso', 'is_enabled' => true]);
        FeatureFlag::create(['tenant_id' => $this->tenant->id, 'key' => 'sso', 'is_enabled' => false]);

        $this->assertFalse($this->features()->enabled('sso', $this->admin));
    }

    public function test_rollout_percentage_is_deterministic_per_user(): void
    {
        FeatureFlag::create([
            'tenant_id' => null, 'key' => 'gradual', 'is_enabled' => true, 'rollout_percentage' => 50,
        ]);

        $service = $this->features();
        $first = $service->enabled('gradual', $this->admin);

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($first, $service->enabled('gradual', $this->admin));
        }
    }

    public function test_zero_rollout_admits_nobody(): void
    {
        FeatureFlag::create([
            'tenant_id' => null, 'key' => 'dark', 'is_enabled' => true, 'rollout_percentage' => 0,
        ]);

        $this->assertFalse($this->features()->enabled('dark', $this->admin));
        $this->assertFalse($this->features()->enabled('dark', $this->officer));
    }

    public function test_feature_middleware_hides_gated_routes(): void
    {
        Route::middleware(['web', 'auth', 'feature:gated-module'])
            ->get('/gated-module-test', fn () => 'ok');

        $this->actingAs($this->admin)->get('/gated-module-test')->assertNotFound();

        FeatureFlag::create(['tenant_id' => null, 'key' => 'gated-module', 'is_enabled' => true]);
        app(FeatureService::class)->flush();

        $this->actingAs($this->admin)->get('/gated-module-test')->assertOk();
    }

    public function test_admin_can_toggle_a_flag_and_it_is_audited(): void
    {
        FeatureFlag::create(['tenant_id' => null, 'key' => 'sso', 'is_enabled' => true, 'description' => 'SSO']);

        $this->actingAs($this->admin)
            ->put(route('admin.feature-flags.update', 'sso'), [
                'scope' => 'tenant', 'is_enabled' => false, 'rollout_percentage' => 100,
            ])
            ->assertRedirect();

        $this->assertFalse($this->features()->enabled('sso', $this->admin));
        $this->assertDatabaseHas('audit_trails', ['entity_type' => FeatureFlag::class, 'action' => 'created']);
    }

    public function test_non_admin_cannot_toggle_flags(): void
    {
        $this->actingAs($this->officer)
            ->put(route('admin.feature-flags.update', 'sso'), [
                'scope' => 'global', 'is_enabled' => false, 'rollout_percentage' => 100,
            ])
            ->assertForbidden();
    }
}
