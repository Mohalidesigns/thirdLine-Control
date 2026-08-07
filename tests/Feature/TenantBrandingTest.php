<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\User;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantBrandingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin->assignRole('System Administrator');

        $this->officer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->officer->assignRole('Control Officer');
    }

    public function test_a_brand_colour_failing_contrast_is_rejected(): void
    {
        // Pale yellow on white — nowhere near 4.5:1.
        $this->actingAs($this->admin)
            ->post(route('admin.branding.update'), ['primary_colour' => '#FFEE99'])
            ->assertSessionHasErrors('primary_colour');

        $this->assertDatabaseMissing('tenant_brandings', ['tenant_id' => $this->tenant->id]);
    }

    public function test_a_compliant_brand_colour_is_accepted_and_audited(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.branding.update'), [
                'primary_colour' => '#1A365D',
                'accent_colour' => '#C9A227',
                'product_name' => 'FirstControl',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tenant_brandings', [
            'tenant_id' => $this->tenant->id,
            'primary_colour' => '#1A365D',
            'product_name' => 'FirstControl',
        ]);

        $this->assertDatabaseHas('audit_trails', [
            'entity_type' => TenantBranding::class,
            'action' => 'branding_updated',
        ]);
    }

    public function test_branding_is_shared_with_the_frontend(): void
    {
        TenantBranding::create([
            'tenant_id' => $this->tenant->id,
            'primary_colour' => '#1A365D',
            'product_name' => 'FirstControl',
        ]);

        $this->actingAs($this->admin)
            ->get(route('notifications.index'))
            ->assertInertia(fn ($page) => $page
                ->where('branding.primary_colour', '#1A365D')
                ->where('branding.product_name', 'FirstControl'));
    }

    public function test_non_admins_cannot_change_branding(): void
    {
        $this->actingAs($this->officer)
            ->post(route('admin.branding.update'), ['product_name' => 'Rogue'])
            ->assertForbidden();
    }
}
