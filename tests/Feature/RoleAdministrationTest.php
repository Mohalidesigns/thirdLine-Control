<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The roles & permissions admin screen: System Administrator only, the
 * System Administrator role itself immutable, a tenant never left without
 * an active administrator, and every change audited.
 */
class RoleAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $cfh;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->admin = $this->makeUser('admin@test.local', 'System Administrator');
        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    // ── Reach ────────────────────────────────────────────────────────

    public function test_the_screen_is_reserved_for_the_system_administrator(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.roles'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Roles')
                ->has('roles')
                ->has('permissionGroups')
                ->has('users'));

        $this->actingAs($this->cfh)
            ->get(route('admin.roles'))
            ->assertForbidden();
    }

    public function test_the_user_list_is_tenant_scoped(): void
    {
        $other = Tenant::create(['name' => 'Other Bank', 'status' => 'active']);
        User::factory()->create(['email' => 'foreign@other.local', 'tenant_id' => $other->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.roles'))
            ->assertInertia(fn ($page) => $page->has('users', 2));
    }

    // ── Role permission edits ────────────────────────────────────────

    public function test_a_role_permission_set_can_be_edited_and_is_audited(): void
    {
        $role = Role::findByName('Line Manager');
        $before = $role->permissions->pluck('name')->values()->all();

        $this->actingAs($this->admin)
            ->put(route('admin.roles.update', $role), ['permissions' => ['view controls', 'view risks']])
            ->assertRedirect();

        $this->assertEqualsCanonicalizing(
            ['view controls', 'view risks'],
            $role->fresh()->permissions->pluck('name')->all(),
        );

        $row = AuditTrail::where('entity_id', $role->id)->where('action', 'permissions-updated')->first();
        $this->assertNotNull($row);
        $this->assertEqualsCanonicalizing($before, $row->before['permissions']);
        $this->assertEqualsCanonicalizing(['view controls', 'view risks'], $row->after['permissions']);
    }

    public function test_the_system_administrator_role_is_immutable(): void
    {
        $role = Role::findByName('System Administrator');

        $this->actingAs($this->admin)
            ->from(route('admin.roles'))
            ->put(route('admin.roles.update', $role), ['permissions' => []])
            ->assertRedirect(route('admin.roles'))
            ->assertSessionHasErrors('role');

        $this->assertTrue($this->admin->fresh()->can('manage users'));
    }

    public function test_an_unknown_permission_name_is_rejected(): void
    {
        $role = Role::findByName('Line Manager');

        $this->actingAs($this->admin)
            ->put(route('admin.roles.update', $role), ['permissions' => ['not a permission']])
            ->assertSessionHasErrors('permissions.0');
    }

    // ── Custom roles ─────────────────────────────────────────────────

    public function test_a_custom_role_can_be_created_and_deleted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.roles.store'), ['name' => 'Data Protection Officer'])
            ->assertRedirect();

        $role = Role::findByName('Data Protection Officer');
        $this->assertNotNull($role);

        $this->actingAs($this->admin)
            ->delete(route('admin.roles.destroy', $role))
            ->assertRedirect();

        $this->assertNull(Role::where('name', 'Data Protection Officer')->first());
    }

    public function test_a_seeded_role_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.roles'))
            ->delete(route('admin.roles.destroy', Role::findByName('Executive Viewer')))
            ->assertSessionHasErrors('role');

        $this->assertNotNull(Role::where('name', 'Executive Viewer')->first());
    }

    public function test_a_role_still_held_by_users_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin)->post(route('admin.roles.store'), ['name' => 'Auditor']);
        $this->cfh->assignRole('Auditor');

        $this->actingAs($this->admin)
            ->from(route('admin.roles'))
            ->delete(route('admin.roles.destroy', Role::findByName('Auditor')))
            ->assertSessionHasErrors('role');
    }

    // ── User role assignment ─────────────────────────────────────────

    public function test_a_users_roles_can_be_reassigned_and_the_change_is_audited(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.users.roles.update', $this->cfh), ['roles' => ['Control Officer']])
            ->assertRedirect();

        $this->assertEqualsCanonicalizing(['Control Officer'], $this->cfh->fresh()->roles->pluck('name')->all());

        $row = AuditTrail::where('entity_type', $this->cfh->getMorphClass())
            ->where('entity_id', $this->cfh->id)
            ->where('action', 'roles-updated')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(['Control Function Head'], $row->before['roles']);
        $this->assertSame(['Control Officer'], $row->after['roles']);
    }

    public function test_the_last_active_system_administrator_cannot_be_demoted(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.roles'))
            ->put(route('admin.users.roles.update', $this->admin), ['roles' => ['Executive Viewer']])
            ->assertSessionHasErrors('roles');

        $this->assertTrue($this->admin->fresh()->hasRole('System Administrator'));
    }

    public function test_an_administrator_can_be_demoted_once_another_remains(): void
    {
        $this->makeUser('admin2@test.local', 'System Administrator');

        $this->actingAs($this->admin)
            ->put(route('admin.users.roles.update', $this->admin), ['roles' => ['Executive Viewer']])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->admin->fresh()->hasRole('System Administrator'));
    }

    public function test_a_user_from_another_tenant_is_out_of_reach(): void
    {
        $other = Tenant::create(['name' => 'Other Bank', 'status' => 'active']);
        $foreign = User::factory()->create(['email' => 'foreign@other.local', 'tenant_id' => $other->id]);

        $this->actingAs($this->admin)
            ->put(route('admin.users.roles.update', $foreign), ['roles' => ['Line Manager']])
            ->assertNotFound();
    }
}
