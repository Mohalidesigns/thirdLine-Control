<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Database\Seeders\ActivityLogSeeder;
use Database\Seeders\InvestigationDemoSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolvesDemoTenantTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The bug: a real tenant created through the app before `db:seed` runs
     * sorts ahead of the demo one, so `Tenant::first()` hands the demo
     * seeders the customer's tenant.
     */
    public function test_demo_tenant_is_resolved_by_name_not_by_insertion_order(): void
    {
        $decoy = Tenant::create(['name' => 'A Real Customer Plc', 'slug' => 'real-customer']);

        $this->seed(RolePermissionSeeder::class);
        (new TenantSeeder)->run();

        $demo = Tenant::where('name', TenantSeeder::DEMO_TENANT_NAME)->firstOrFail();

        // Precondition: the decoy really is what Tenant::first() would return.
        $this->assertSame($decoy->id, Tenant::first()->id, 'decoy must sort first for this test to mean anything');

        foreach ([new ActivityLogSeeder, new InvestigationDemoSeeder] as $seeder) {
            $resolved = (fn () => $this->demoTenant())->call($seeder);
            $this->assertSame($demo->id, $resolved->id, get_class($seeder).' resolved the wrong tenant');
        }
    }

    public function test_falls_back_to_the_only_tenant_when_no_demo_tenant_exists(): void
    {
        $only = Tenant::create(['name' => 'Solo Plc', 'slug' => 'solo']);

        $resolved = (fn () => $this->demoTenant())->call(new ActivityLogSeeder);
        $this->assertSame($only->id, $resolved->id);
    }
}
