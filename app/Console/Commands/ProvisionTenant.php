<?php

namespace App\Console\Commands;

use App\Models\Entity;
use App\Models\OrganisationUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ContentPackInstaller;
use Database\Seeders\ExchangeRateSeeder;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\NotificationEventSeeder;
use Database\Seeders\PublicHolidaySeeder;
use Database\Seeders\RatingMatrixSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Stands up a new tenant end to end (16.3): reference data, roles, the
 * tenant record with its residency declaration, a head-office unit, the
 * root legal entity, the first administrator and any chosen content
 * packs. The residency declaration is set here and only here — runtime
 * edits are blocked by the model (16.2).
 */
class ProvisionTenant extends Command
{
    protected $signature = 'tenant:provision
        {name : Legal name of the tenant}
        {--admin-email= : Email for the first System Administrator}
        {--residency=NG : ISO-3166 alpha-2 data-residency country}
        {--currency=NGN : ISO-4217 functional currency}
        {--timezone=Africa/Lagos : Tenant timezone}
        {--locale=en-NG : Tenant locale}
        {--entity-type=bank : Legal entity type of the root entity}
        {--pack=* : Content pack codes to install (e.g. CBN-CG-2023)}';

    protected $description = 'Provision a new tenant with roles, reference data, a root entity and content packs';

    public function handle(ContentPackInstaller $installer): int
    {
        $email = $this->option('admin-email') ?: Str::slug($this->argument('name')).'-admin@atheris.local';

        if (Tenant::where('name', $this->argument('name'))->exists()) {
            $this->error('A tenant with that name already exists.');

            return self::FAILURE;
        }

        // Global reference data — idempotent, shared across tenants.
        $this->call('db:seed', ['--class' => RolePermissionSeeder::class, '--force' => true]);

        foreach ([FeatureFlagSeeder::class, NotificationEventSeeder::class, RatingMatrixSeeder::class,
            ExchangeRateSeeder::class, PublicHolidaySeeder::class] as $seeder) {
            $this->call('db:seed', ['--class' => $seeder, '--force' => true]);
        }

        // Provisioning is the one context allowed to declare residency.
        app()->instance('residency.reprovisioning', true);

        $tenant = Tenant::create([
            'name' => $this->argument('name'),
            'data_residency' => strtoupper($this->option('residency')),
            'status' => 'active',
            'locale' => $this->option('locale'),
            'timezone' => $this->option('timezone'),
            'currency' => strtoupper($this->option('currency')),
        ]);

        $headOffice = OrganisationUnit::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Head Office',
            'type' => 'Head Office',
            'code' => 'HO',
            'is_legal_entity' => true,
        ]);

        $entity = Entity::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'organisation_unit_id' => $headOffice->id,
            'reference' => 'ENT-001',
            'legal_name' => $this->argument('name'),
            'entity_type' => $this->option('entity-type'),
            'jurisdiction' => strtoupper($this->option('residency')),
            'functional_currency' => strtoupper($this->option('currency')),
            'consolidation_method' => 'full',
            'ownership_percentage' => 100,
            'data_residency_country' => strtoupper($this->option('residency')),
            'status' => 'active',
        ]);

        $password = Str::password(16);

        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Administrator',
            'email' => $email,
            'password' => $password,
            'unit_id' => $headOffice->id,
            'is_active' => true,
        ]);
        $admin->assignRole('System Administrator');

        $entity->grantedUsers()->attach($admin->id, ['granted_by' => $admin->id, 'note' => 'Provisioning']);

        app()->forgetInstance('residency.reprovisioning');

        foreach ((array) $this->option('pack') as $code) {
            try {
                $installer->install($code);
                $this->info("Content pack installed: {$code}");
            } catch (\Throwable $e) {
                $this->warn("Content pack {$code} failed: {$e->getMessage()}");
            }
        }

        $tenant->auditAction('tenant-provisioned', null, [
            'residency' => $tenant->data_residency,
            'root_entity' => $entity->reference,
            'admin' => $email,
        ]);

        $this->info("Tenant #{$tenant->id} '{$tenant->name}' provisioned (residency {$tenant->data_residency}).");
        $this->info("Administrator: {$email}");
        $this->warn("One-time password (rotate on first login): {$password}");

        return self::SUCCESS;
    }
}
