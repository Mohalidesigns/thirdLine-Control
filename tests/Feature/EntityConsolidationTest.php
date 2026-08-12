<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\Entity;
use App\Models\OrganisationUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ConsolidationService;
use App\Services\EntityService;
use App\Support\TenantCache;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class EntityConsolidationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    private OrganisationUnit $hq;

    private OrganisationUnit $ghUnit;

    private OrganisationUnit $pfaUnit;

    private Entity $bank;

    private Entity $ghana;

    private Entity $pfa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Group Test Bank', 'status' => 'active', 'data_residency' => 'NG']);

        $this->cfh = $this->makeUser('cfh@group.test', 'Control Function Head');
        $this->officer = $this->makeUser('officer@group.test', 'Control Officer');

        $this->hq = $this->unit('HQ', 'Head Office');
        $this->ghUnit = $this->unit('GH', 'Subsidiary', $this->hq->id);
        $this->pfaUnit = $this->unit('PFA', 'Subsidiary', $this->hq->id);

        $this->actingAs($this->cfh);

        $service = app(EntityService::class);

        $this->bank = $service->create([
            'legal_name' => 'Test Bank Plc', 'entity_type' => 'bank',
            'organisation_unit_id' => $this->hq->id, 'jurisdiction' => 'NG',
            'functional_currency' => 'NGN', 'consolidation_method' => 'full',
            'ownership_percentage' => 100, 'data_residency_country' => 'NG', 'status' => 'active',
        ], $this->cfh);

        $this->ghana = $service->create([
            'legal_name' => 'Test Bank Ghana', 'entity_type' => 'bank',
            'parent_entity_id' => $this->bank->id,
            'organisation_unit_id' => $this->ghUnit->id, 'jurisdiction' => 'GH',
            'functional_currency' => 'GHS', 'consolidation_method' => 'proportional',
            'ownership_percentage' => 60, 'data_residency_country' => 'GH', 'status' => 'active',
        ], $this->cfh);

        $this->pfa = $service->create([
            'legal_name' => 'Test PFA', 'entity_type' => 'pfa',
            'parent_entity_id' => $this->bank->id,
            'organisation_unit_id' => $this->pfaUnit->id, 'jurisdiction' => 'NG',
            'functional_currency' => 'NGN', 'consolidation_method' => 'none',
            'ownership_percentage' => 20, 'data_residency_country' => 'NG', 'status' => 'active',
        ], $this->cfh);

        // 4 active controls at the bank's own units, 2 in Ghana, 1 in the PFA.
        foreach ([[$this->hq, 4, 'B'], [$this->ghUnit, 2, 'G'], [$this->pfaUnit, 1, 'P']] as [$unit, $count, $prefix]) {
            for ($i = 1; $i <= $count; $i++) {
                Control::withoutGlobalScopes()->create([
                    'tenant_id' => $this->tenant->id,
                    'control_ref' => "{$prefix}-{$i}",
                    'title' => "Control {$prefix}-{$i}",
                    'type' => 'Preventive', 'nature' => 'Manual', 'frequency' => 'Monthly',
                    'unit_id' => $unit->id, 'status' => 'Active', 'is_key_control' => true,
                ]);
            }
        }
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function unit(string $code, string $type, ?int $parentId = null): OrganisationUnit
    {
        return OrganisationUnit::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'code' => $code, 'name' => $code, 'type' => $type,
            'parent_id' => $parentId,
        ]);
    }

    private function grantAll(User $user): void
    {
        foreach ([$this->bank, $this->ghana, $this->pfa] as $entity) {
            $entity->grantedUsers()->attach($user->id, ['granted_by' => $this->cfh->id]);
        }
    }

    public function test_consolidation_methods_produce_correct_aggregates(): void
    {
        $this->grantAll($this->cfh);

        $rollup = app(ConsolidationService::class)->rollup($this->cfh);

        $byName = collect($rollup['entities'])->keyBy('legal_name');

        // Standalone: the bank's subtree excludes the units claimed by the
        // two nested entities — no double counting.
        $this->assertSame(4, $byName['Test Bank Plc']['kpis']['active_controls']);
        $this->assertSame(2, $byName['Test Bank Ghana']['kpis']['active_controls']);
        $this->assertSame(1, $byName['Test PFA']['kpis']['active_controls']);

        // Group: full 4×1.0 + proportional 2×0.6 + none excluded = 5.2.
        $this->assertSame(5.2, $rollup['group']['kpis']['active_controls']);
        $this->assertSame(2, $rollup['group']['consolidated_count']);
        $this->assertSame(3, $rollup['group']['entity_count']);

        // Benchmark: average over consolidated entities = (4+2×0.6)/2 = 2.6.
        $this->assertSame(2.6, $rollup['group']['averages']['active_controls']);
        $this->assertSame(1.4, $byName['Test Bank Plc']['benchmark']['active_controls']);
    }

    public function test_rollup_respects_per_entity_grants(): void
    {
        // The officer is granted the bank only — Ghana's numbers must not
        // appear anywhere in their rollup, including the group totals.
        $this->bank->grantedUsers()->attach($this->officer->id, ['granted_by' => $this->cfh->id]);

        $rollup = app(ConsolidationService::class)->rollup($this->officer);

        $this->assertCount(1, $rollup['entities']);
        $this->assertSame('Test Bank Plc', $rollup['entities'][0]['legal_name']);
        $this->assertSame(4.0, $rollup['group']['kpis']['active_controls']);
    }

    public function test_entity_policy_denies_without_grant_even_for_admin(): void
    {
        $admin = $this->makeUser('admin@group.test', 'System Administrator');

        $this->assertFalse($admin->can('view', $this->bank));

        $this->bank->grantedUsers()->attach($admin->id, ['granted_by' => $this->cfh->id]);

        $this->assertTrue($admin->fresh()->can('view', $this->bank));
    }

    public function test_group_dashboard_requires_permission(): void
    {
        $this->grantAll($this->cfh);

        $this->actingAs($this->cfh)->get(route('group.dashboard'))->assertOk();

        $owner = $this->makeUser('owner@group.test', 'Control Owner');
        $this->actingAs($owner)->get(route('group.dashboard'))->assertForbidden();
    }

    public function test_rollup_cache_is_tenant_scoped_and_invalidated_on_entity_writes(): void
    {
        $this->grantAll($this->cfh);

        app(ConsolidationService::class)->rollup($this->cfh);

        $key = TenantCache::key($this->tenant->id, ConsolidationService::CACHE_SUFFIX);
        $this->assertTrue(Cache::has($key));

        // Another tenant's key is distinct — nothing to read across tenants.
        $other = Tenant::create(['name' => 'Other Bank', 'status' => 'active']);
        $this->assertNotSame($key, TenantCache::key($other->id, ConsolidationService::CACHE_SUFFIX));
        $this->assertFalse(Cache::has(TenantCache::key($other->id, ConsolidationService::CACHE_SUFFIX)));

        // An entity write invalidates the tenant's rollup explicitly.
        app(EntityService::class)->update($this->ghana, ['legal_name' => 'Test Bank Ghana Ltd']);
        $this->assertFalse(Cache::has($key));
    }
}
