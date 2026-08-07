<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\User;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PwaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('Control Owner');
    }

    public function test_the_manifest_is_served_and_reflects_branding(): void
    {
        TenantBranding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'product_name' => 'FirstControl',
            'primary_colour' => '#1A365D',
        ]);

        $this->get(route('pwa.manifest'))
            ->assertOk()
            ->assertJsonPath('name', 'FirstControl')
            ->assertJsonPath('theme_color', '#1A365D')
            ->assertJsonPath('display', 'standalone');
    }

    public function test_the_offline_fallback_page_renders(): void
    {
        $this->get(route('pwa.offline'))
            ->assertOk()
            ->assertSee("You're offline", false);
    }

    public function test_a_user_can_toggle_low_bandwidth_mode(): void
    {
        $this->assertFalse($this->user->fresh()->low_bandwidth_mode);

        $this->actingAs($this->user)
            ->post(route('settings.low-bandwidth'))
            ->assertRedirect();

        $this->assertTrue($this->user->fresh()->low_bandwidth_mode);

        $this->actingAs($this->user)
            ->get(route('notifications.index'))
            ->assertInertia(fn ($page) => $page->where('lowBandwidth', true));
    }
}
