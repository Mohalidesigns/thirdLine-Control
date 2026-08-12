<?php

namespace Tests\Feature;

use App\Exceptions\ResidencyViolationException;
use App\Models\AuditTrail;
use App\Models\Control;
use App\Models\IntegrationConfig;
use App\Models\Tenant;
use App\Models\User;
use App\Services\IntegrationService;
use App\Services\ResidencyGuard;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResidencyGuardProbeJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void {}
}

class ResidencyGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Guarded Bank', 'status' => 'active', 'data_residency' => 'NG']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('Control Function Head');
    }

    public function test_filesystem_layer_blocks_a_disk_outside_the_declared_country(): void
    {
        config(['residency.disk_regions.local' => 'EU']);

        $this->actingAs($this->user);

        $this->expectException(ResidencyViolationException::class);

        app(ResidencyGuard::class)->assertStorageAllowed('local', $this->tenant);
    }

    public function test_queue_layer_blocks_a_broker_outside_the_declared_country(): void
    {
        config(['residency.queue_regions.sync' => 'EU']);

        $this->actingAs($this->user);

        $this->expectException(ResidencyViolationException::class);

        dispatch(new ResidencyGuardProbeJob);
    }

    public function test_backup_layer_blocks_a_backup_disk_outside_the_declared_country(): void
    {
        config(['residency.disk_regions.local' => 'EU', 'residency.backup_disk' => 'local']);

        $this->actingAs($this->user);

        $this->expectException(ResidencyViolationException::class);

        $this->artisan('atheris:backup');
    }

    public function test_backup_writes_to_a_compliant_disk(): void
    {
        Storage::fake('local');

        $this->artisan('atheris:backup')->assertSuccessful();

        $this->assertNotEmpty(Storage::disk('local')->files('backups'));
    }

    public function test_integration_layer_blocks_an_endpoint_mapped_to_a_foreign_country(): void
    {
        config(['residency.endpoint_regions' => ['thirdline.example.eu' => 'EU']]);

        $this->actingAs($this->user);

        IntegrationConfig::create([
            'tenant_id' => $this->tenant->id,
            'target_system' => 'ThirdLine',
            'base_url' => 'https://thirdline.example.eu',
            'sync_direction' => 'Push',
            'is_active' => true,
        ]);

        $control = Control::create([
            'tenant_id' => $this->tenant->id, 'control_ref' => 'CTL-900',
            'title' => 'Guarded control', 'type' => 'Preventive', 'nature' => 'Manual',
            'frequency' => 'Monthly', 'status' => 'Active',
        ]);

        $log = app(IntegrationService::class)->publishControl($control);

        $this->assertSame('Failed', $log->status);
        $this->assertStringContainsString('Residency guard', $log->error_message);
    }

    public function test_a_block_is_audited_against_the_tenant(): void
    {
        config(['residency.disk_regions.local' => 'EU']);

        $this->actingAs($this->user);

        try {
            app(ResidencyGuard::class)->assertStorageAllowed('local', $this->tenant);
            $this->fail('Expected a residency violation.');
        } catch (ResidencyViolationException) {
        }

        $this->assertDatabaseHas('audit_trails', [
            'tenant_id' => $this->tenant->id,
            'action' => 'residency-blocked',
        ]);
    }

    public function test_the_declaration_cannot_be_changed_at_runtime(): void
    {
        $this->expectException(ResidencyViolationException::class);

        $this->tenant->update(['data_residency' => 'GH']);
    }

    public function test_reprovisioning_may_change_the_declaration_and_is_audited(): void
    {
        app()->instance('residency.reprovisioning', true);

        $this->tenant->update(['data_residency' => 'GH']);

        app()->forgetInstance('residency.reprovisioning');

        $this->assertSame('GH', $this->tenant->fresh()->data_residency);

        $this->assertTrue(
            AuditTrail::where('entity_type', $this->tenant->getMorphClass())
                ->where('entity_id', $this->tenant->id)
                ->where('action', 'updated')
                ->exists(),
        );
    }
}
