<?php

namespace Database\Seeders\Concerns;

use App\Models\Tenant;
use Database\Seeders\TenantSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Resolves the tenant the demo and reference-data seeders write into.
 *
 * The seeders used to take `Tenant::first()`, which silently picks the
 * wrong tenant as soon as an installation holds more than one (e.g. a
 * real tenant created through the app before `db:seed` ran). The demo
 * users are created under the tenant seeded by {@see TenantSeeder}, and
 * the demo seeders log in as those users, so the tenant scope then hides
 * every record written under the other tenant. Resolve by name instead,
 * falling back to the only/first tenant on installs without a demo one.
 */
trait ResolvesDemoTenant
{
    protected function demoTenant(): ?Tenant
    {
        return Tenant::where('name', TenantSeeder::DEMO_TENANT_NAME)->first()
            ?? Tenant::first();
    }

    protected function demoTenantOrFail(): Tenant
    {
        return $this->demoTenant()
            ?? throw (new ModelNotFoundException)->setModel(Tenant::class);
    }
}
