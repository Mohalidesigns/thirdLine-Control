<?php

namespace Tests\Feature;

use App\Dashboards\WidgetRegistry;
use App\Models\Control;
use App\Models\ControlEntity;
use App\Models\ControlException;
use App\Models\ControlStakeholder;
use App\Models\ControlUnit;
use App\Models\OrganisationUnit;
use App\Models\Tenant;
use App\Models\TestInstance;
use App\Models\User;
use App\Notifications\SharedControlExceptionRaisedNotification;
use App\Services\ControlStructureService;
use Database\Seeders\ControlStructureSeeder;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\NotificationEventSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ControlStructureTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    private User $owner;

    private User $admin;

    private OrganisationUnit $headOffice;

    private OrganisationUnit $operations;

    private OrganisationUnit $marina;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(NotificationEventSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Structure Test Bank', 'status' => 'active', 'data_residency' => 'NG']);

        $this->cfh = $this->makeUser('cfh@structure.test', 'Control Function Head');
        $this->officer = $this->makeUser('officer@structure.test', 'Control Officer');
        $this->owner = $this->makeUser('owner@structure.test', 'Control Owner');
        $this->admin = $this->makeUser('admin@structure.test', 'System Administrator');

        $this->headOffice = $this->unit('HO', 'Head Office', 'Head Office');
        $this->operations = $this->unit('OPS', 'Operations', 'Department', $this->headOffice->id);
        // Created BEFORE the structure seeder runs, so provisioning comes
        // from the seeder's sync rather than the observer.
        $this->marina = $this->unit('BR-001', 'Marina Branch', 'Branch', $this->headOffice->id);

        $this->seed(ControlStructureSeeder::class);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function unit(string $code, string $name, string $type, ?int $parentId = null): OrganisationUnit
    {
        return OrganisationUnit::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'code' => $code, 'name' => $name, 'type' => $type,
            'parent_id' => $parentId,
        ]);
    }

    private function makeControl(string $ref, ?OrganisationUnit $unit = null, ?User $owner = null): Control
    {
        return Control::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => $ref,
            'title' => "Control {$ref}",
            'type' => 'Preventive', 'nature' => 'Manual', 'frequency' => 'Monthly',
            'unit_id' => ($unit ?? $this->operations)->id,
            'owner_id' => $owner?->id,
            'status' => 'Active',
        ]);
    }

    private function branchEntity(string $name = 'Marina Branch'): ControlEntity
    {
        return ControlEntity::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('entity_kind', 'branch')
            ->where('name', $name)
            ->firstOrFail();
    }

    private function activityUnder(ControlEntity $branch, string $name): ControlEntity
    {
        return ControlEntity::withoutGlobalScopes()
            ->where('parent_id', $branch->id)
            ->where('name', $name)
            ->firstOrFail();
    }

    // ── Seeder ───────────────────────────────────────────────────────

    public function test_seeder_builds_the_three_units_and_the_taxonomy(): void
    {
        $units = ControlUnit::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->pluck('domain', 'code');

        $this->assertSame('head_office', $units['HOC']);
        $this->assertSame('information_systems', $units['ISC']);
        $this->assertSame('branch', $units['BRC']);

        $hoc = ControlUnit::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('code', 'HOC')->first();
        $isc = ControlUnit::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('code', 'ISC')->first();
        $brc = ControlUnit::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('code', 'BRC')->first();

        $names = fn ($unit, $template = false) => ControlEntity::withoutGlobalScopes()
            ->where('control_unit_id', $unit->id)->where('is_template', $template)
            ->whereNull('parent_id')->pluck('name')->all();

        $this->assertContains('Treasury', $names($hoc));
        $this->assertContains('Finance & Accounts', $names($hoc));
        $this->assertContains('Network Security', $names($isc));
        $this->assertContains('End of Day Transactions Cutoff', $names($isc));
        $this->assertContains('Cash Management', $names($brc, template: true));
        $this->assertContains('E-Business Channels', $names($brc, template: true));

        // The seeder links the bridge where a name matches an org unit.
        $opsEntity = ControlEntity::withoutGlobalScopes()
            ->where('control_unit_id', $hoc->id)->where('name', 'Operations')->first();
        $this->assertSame($this->operations->id, $opsEntity->organisation_unit_id);

        // Treasury has no matching org unit here — the bridge stays NULL.
        $treasury = ControlEntity::withoutGlobalScopes()
            ->where('control_unit_id', $hoc->id)->where('name', 'Treasury')->first();
        $this->assertNull($treasury->organisation_unit_id);
    }

    public function test_seeder_rerun_duplicates_nothing(): void
    {
        $before = [
            ControlUnit::withoutGlobalScopes()->count(),
            ControlEntity::withoutGlobalScopes()->count(),
        ];

        $this->seed(ControlStructureSeeder::class);

        $this->assertSame($before, [
            ControlUnit::withoutGlobalScopes()->count(),
            ControlEntity::withoutGlobalScopes()->count(),
        ]);
    }

    // ── Branch sync (CR2A.2) ─────────────────────────────────────────

    public function test_sync_provisions_a_branch_with_its_activities_and_is_idempotent(): void
    {
        $branch = $this->branchEntity();

        $this->assertSame('branch', $branch->entity_kind);
        $this->assertSame($this->marina->id, $branch->organisation_unit_id);

        $activityCount = ControlEntity::withoutGlobalScopes()->where('parent_id', $branch->id)->count();
        $this->assertSame(10, $activityCount); // the full activity template

        $before = ControlEntity::withoutGlobalScopes()->count();
        $this->artisan('control-structure:sync-branches')->assertSuccessful();
        $this->assertSame($before, ControlEntity::withoutGlobalScopes()->count());
    }

    public function test_a_new_template_activity_reaches_existing_branches_on_the_next_sync(): void
    {
        $brc = ControlUnit::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->where('code', 'BRC')->first();

        ControlEntity::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_unit_id' => $brc->id,
            'reference' => app(ControlStructureService::class)->nextEntityReference($this->tenant->id),
            'name' => 'Agency Banking',
            'entity_kind' => 'activity',
            'is_template' => true,
            'is_active' => true,
        ]);

        $this->artisan('control-structure:sync-branches')->assertSuccessful();

        $this->assertTrue(
            ControlEntity::withoutGlobalScopes()
                ->where('parent_id', $this->branchEntity()->id)
                ->where('name', 'Agency Banking')
                ->where('is_template', false)
                ->exists(),
        );
    }

    public function test_editing_a_template_never_touches_instantiated_activities(): void
    {
        $template = ControlEntity::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('is_template', true)
            ->where('name', 'Vault')
            ->firstOrFail();

        $instantiated = $this->activityUnder($this->branchEntity(), 'Vault');

        $template->update(['description' => 'Rewritten guidance', 'risk_rating' => 'Critical']);

        $this->artisan('control-structure:sync-branches')->assertSuccessful();

        $this->assertNull($instantiated->fresh()->description);
        $this->assertNull($instantiated->fresh()->risk_rating);
    }

    public function test_creating_a_branch_org_unit_auto_provisions_via_the_observer(): void
    {
        $kano = $this->unit('BR-002', 'Kano Branch', 'Branch', $this->headOffice->id);

        $entity = ControlEntity::withoutGlobalScopes()
            ->where('organisation_unit_id', $kano->id)
            ->where('entity_kind', 'branch')
            ->first();

        $this->assertNotNull($entity, 'The observer must provision a new branch immediately.');
        $this->assertSame(10, ControlEntity::withoutGlobalScopes()->where('parent_id', $entity->id)->count());
    }

    public function test_branch_entity_without_bridge_is_a_validation_error(): void
    {
        $this->expectException(ValidationException::class);

        $this->actingAs($this->officer);

        app(ControlStructureService::class)->createEntity([
            'control_unit_id' => ControlUnit::withoutGlobalScopes()
                ->where('tenant_id', $this->tenant->id)->where('code', 'BRC')->value('id'),
            'name' => 'Phantom Branch',
            'entity_kind' => 'branch',
        ], $this->officer);
    }

    // ── Attaching controls (CR2A.3) ──────────────────────────────────

    public function test_attach_is_unique_and_detach_is_blocked_by_an_open_exception(): void
    {
        $this->actingAs($this->officer);

        $control = $this->makeControl('CTL-T1');
        $cash = $this->activityUnder($this->branchEntity(), 'Cash Management');

        $service = app(ControlStructureService::class);

        $service->attachControls($cash, [['control_id' => $control->id, 'is_key' => true]], $this->officer);
        $service->attachControls($cash, [['control_id' => $control->id, 'is_key' => true]], $this->officer);

        $this->assertSame(1, $cash->controls()->count());

        $exception = ControlException::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'reference' => 'EXC-T-001',
            'source_type' => 'Manual',
            'control_id' => $control->id,
            'title' => 'Cash shortage',
            'severity' => 'High',
            'unit_id' => $this->marina->id,
            'date_raised' => now()->toDateString(),
            'status' => 'Open',
        ]);

        try {
            $service->detachControl($cash, $control, $this->officer);
            $this->fail('Detach must be blocked while an open exception exists.');
        } catch (ValidationException) {
            $this->assertSame(1, $cash->controls()->count());
        }

        $exception->update(['status' => 'Verified-Closed']);

        $service->detachControl($cash, $control, $this->officer);
        $this->assertSame(0, $cash->controls()->count());
    }

    public function test_detach_is_blocked_by_an_in_flight_test(): void
    {
        $this->actingAs($this->officer);

        $control = $this->makeControl('CTL-T2');
        $vault = $this->activityUnder($this->branchEntity(), 'Vault');

        $service = app(ControlStructureService::class);
        $service->attachControls($vault, [['control_id' => $control->id]], $this->officer);

        TestInstance::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_id' => $control->id,
            'reference' => 'TST-T-001',
            'period_label' => now()->format('Y-m'),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => 'In Progress',
        ]);

        $this->expectException(ValidationException::class);
        $service->detachControl($vault, $control, $this->officer);
    }

    // ── Stakeholders (CR2A.4) ────────────────────────────────────────

    public function test_owner_stakeholder_must_be_singular_and_match_the_controls_unit(): void
    {
        $this->actingAs($this->officer);

        $control = $this->makeControl('CTL-T3', $this->operations);
        $service = app(ControlStructureService::class);

        // Owner row naming a DIFFERENT unit is rejected.
        try {
            $service->addStakeholder($control, [
                'organisation_unit_id' => $this->marina->id,
                'role' => 'owner',
            ], $this->officer);
            $this->fail('An owner stakeholder must match controls.unit_id.');
        } catch (ValidationException) {
        }

        $service->addStakeholder($control, [
            'organisation_unit_id' => $this->operations->id,
            'role' => 'owner',
        ], $this->officer);

        // A second owner row — even on the right unit via another row —
        // is rejected outright.
        $this->expectException(ValidationException::class);
        $service->addStakeholder($control, [
            'organisation_unit_id' => $this->headOffice->id,
            'role' => 'owner',
        ], $this->officer);
    }

    public function test_shared_controls_appear_in_the_co_owner_units_register_only(): void
    {
        $this->actingAs($this->officer);

        $control = $this->makeControl('CTL-T4', $this->operations);
        $service = app(ControlStructureService::class);

        $coOwnerUnit = $this->unit('IT', 'Information Technology', 'Department', $this->headOffice->id);
        $consultedUnit = $this->unit('LGL', 'Legal Dept', 'Department', $this->headOffice->id);

        $service->addStakeholder($control, ['organisation_unit_id' => $coOwnerUnit->id, 'role' => 'co_owner'], $this->officer);
        $service->addStakeholder($control, ['organisation_unit_id' => $consultedUnit->id, 'role' => 'consulted'], $this->officer);

        $inRegister = fn (OrganisationUnit $unit) => str_contains(
            $this->get(route('controls.index', ['unit_id' => $unit->id]))->getContent(),
            'CTL-T4',
        );

        $this->assertTrue($inRegister($coOwnerUnit), 'A co-owner unit sees the shared control.');
        $this->assertFalse($inRegister($consultedUnit), 'A consulted unit does not.');
    }

    public function test_exception_on_a_shared_control_notifies_the_co_owner_head(): void
    {
        Notification::fake();

        $itHead = $this->makeUser('ithead@structure.test', 'Line Manager');
        $itUnit = $this->unit('IT', 'Information Technology', 'Department', $this->headOffice->id);
        $itUnit->update(['head_user_id' => $itHead->id]);

        $this->actingAs($this->officer);

        $control = $this->makeControl('CTL-T5', $this->operations, $this->owner);
        app(ControlStructureService::class)->addStakeholder($control, [
            'organisation_unit_id' => $itUnit->id,
            'role' => 'co_owner',
        ], $this->officer);

        $this->post(route('exceptions.store'), [
            'title' => 'Shared control failed',
            'severity' => 'High',
            'control_id' => $control->id,
            'unit_id' => $this->operations->id,
            'target_closure_date' => now()->addDays(14)->toDateString(),
        ])->assertSessionHasNoErrors();

        Notification::assertSentTo($itHead, SharedControlExceptionRaisedNotification::class);
    }

    // ── Permissions & tenant isolation ───────────────────────────────

    public function test_the_four_new_permissions_gate_their_routes(): void
    {
        $brc = ControlUnit::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->where('code', 'BRC')->first();
        $entity = $this->branchEntity();
        $control = $this->makeControl('CTL-T6');

        // No role at all → no view.
        $stranger = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($stranger)->get(route('control-structure.index'))->assertForbidden();

        // Control Owner: view yes, manage no.
        $this->actingAs($this->owner)->get(route('control-structure.index'))->assertOk();
        $this->actingAs($this->owner)->post(route('control-structure.units.store'), [
            'code' => 'SUB', 'name' => 'Subsidiary Control', 'domain' => 'other',
        ])->assertForbidden();

        // System Administrator: manage structure yes; attach and
        // stakeholders no — structure admin is not control assignment.
        $this->actingAs($this->admin)->put(route('control-structure.units.update', $brc->id), [
            'code' => 'BRC', 'name' => 'Branch Control', 'domain' => 'branch',
        ])->assertRedirect();
        $this->actingAs($this->admin)->post(route('control-structure.entities.attach', $entity->id), [
            'attachments' => [['control_id' => $control->id]],
        ])->assertForbidden();
        $this->actingAs($this->admin)->post(route('controls.stakeholders.store', $control->id), [
            'organisation_unit_id' => $this->operations->id, 'role' => 'co_owner',
        ])->assertForbidden();

        // The Control Officer holds all four.
        $this->actingAs($this->officer)->post(route('control-structure.entities.attach', $entity->id), [
            'attachments' => [['control_id' => $control->id]],
        ])->assertRedirect();
    }

    public function test_tenant_isolation_on_the_structure_tables(): void
    {
        $otherTenant = Tenant::create(['name' => 'Other Bank', 'status' => 'active', 'data_residency' => 'NG']);

        $otherUnit = ControlUnit::withoutGlobalScopes()->create([
            'tenant_id' => $otherTenant->id, 'code' => 'HOC2', 'name' => 'Head Office Control', 'domain' => 'head_office',
        ]);

        $otherEntity = ControlEntity::withoutGlobalScopes()->create([
            'tenant_id' => $otherTenant->id, 'control_unit_id' => $otherUnit->id,
            'reference' => 'CE-900', 'name' => 'Foreign Treasury', 'entity_kind' => 'department',
        ]);

        $this->actingAs($this->cfh);

        // Route-model binding under the tenant scope: not found, never leaked.
        $this->get(route('control-structure.unit', $otherUnit->id))->assertNotFound();
        $this->get(route('control-structure.entity', $otherEntity->id))->assertNotFound();

        // Queries under the scope never see the other tenant's rows.
        $this->assertSame(0, ControlUnit::query()->where('code', 'HOC2')->count());
        $this->assertSame(0, ControlEntity::query()->where('name', 'Foreign Treasury')->count());
        $this->assertSame(0, ControlStakeholder::query()->where('tenant_id', $otherTenant->id)->count());

        // The index only shows this tenant's units.
        $this->get(route('control-structure.index'))->assertOk()->assertDontSee('Foreign Treasury');
    }

    public function test_widget_sources_resolve_for_the_control_function(): void
    {
        $this->actingAs($this->cfh);

        $registry = app(WidgetRegistry::class);

        foreach (['structure_entities_by_rating', 'structure_coverage', 'structure_branch_heat', 'structure_reviews_overdue'] as $key) {
            $source = $registry->find($key);
            $this->assertNotNull($source, "Widget source {$key} must be registered.");

            $payload = $registry->resolve($this->cfh, $source);
            $this->assertArrayNotHasKey('message', $payload, "Widget source {$key} must resolve without error.");
        }
    }
}
