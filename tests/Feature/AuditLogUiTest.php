<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\Control;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogUiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin->assignRole('System Administrator');

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->owner->assignRole('Control Owner');
    }

    public function test_the_audit_log_requires_the_view_permission(): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.audit-log'))
            ->assertForbidden();
    }

    public function test_the_audit_log_lists_and_filters_entries(): void
    {
        AuditTrail::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->admin->id,
            'entity_type' => Control::class, 'entity_id' => 1, 'action' => 'approved',
        ]);
        AuditTrail::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->admin->id,
            'entity_type' => Control::class, 'entity_id' => 2, 'action' => 'retired',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.audit-log', ['action' => 'approved']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/AuditLog')
                ->has('entries.data', 1)
                ->where('entries.data.0.action', 'approved'));
    }

    public function test_export_requires_the_export_permission_and_is_itself_audited(): void
    {
        // Control Owner lacks 'export audit log'.
        $this->actingAs($this->owner)
            ->get(route('admin.audit-log.export'))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get(route('admin.audit-log.export'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $this->assertDatabaseHas('audit_trails', ['action' => 'audit_log_exported']);
    }

    public function test_record_activity_endpoint_respects_the_record_view_policy(): void
    {
        $officer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $officer->assignRole('Control Officer');

        $control = Control::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-901',
            'title' => 'Audited control',
            'type' => 'Preventive',
            'nature' => 'Manual',
            'frequency' => 'Monthly',
            'status' => 'Active',
            'owner_id' => $this->owner->id,
            'created_by' => $officer->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('audit-trail.entity', ['control', $control->id]))
            ->assertOk()
            ->assertJsonStructure(['entries']);

        // Unknown alias 404s rather than exposing arbitrary models.
        $this->actingAs($this->admin)
            ->get(route('audit-trail.entity', ['user', $this->admin->id]))
            ->assertNotFound();
    }
}
