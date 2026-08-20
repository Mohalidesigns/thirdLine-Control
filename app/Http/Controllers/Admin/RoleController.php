<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles & permissions administration (BRD §4). Gated on 'manage users',
 * which only the System Administrator holds.
 *
 * Two invariants this screen refuses to break:
 *
 *   1. The System Administrator role is immutable — it always holds every
 *      permission, so an admin cannot edit themselves out of the ability
 *      to undo a mistake made here.
 *   2. A tenant always keeps at least one active System Administrator —
 *      the last one cannot be demoted, least of all by themselves.
 *
 * Every change lands in the audit trail with before and after.
 */
class RoleController extends Controller
{
    /** BRD §4 roles ship with the product and cannot be renamed or deleted. */
    private const SEEDED_ROLES = [
        'System Administrator', 'Control Function Head', 'Control Officer',
        'Control Owner', 'Line Manager', 'Executive Viewer',
    ];

    public function __construct(private AuditTrailService $audit) {}

    public function index(Request $request): Response
    {
        $roles = Role::with('permissions:id,name')->withCount('users')
            ->orderBy('id')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'users_count' => $role->users_count,
                'is_seeded' => in_array($role->name, self::SEEDED_ROLES, true),
                'is_locked' => $role->name === 'System Administrator',
                'permissions' => $role->permissions->pluck('name')->values(),
            ]);

        // Grouped by the object being acted on ('view controls' → controls),
        // which is how the seeder lays them out and how the matrix reads.
        $permissions = Permission::orderBy('id')->pluck('name')
            ->groupBy(fn (string $name) => Str::after($name, ' '))
            ->map(fn ($names, $group) => [
                'group' => $group,
                'permissions' => $names->values(),
            ])
            ->values();

        $users = User::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with('roles:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'position', 'is_active'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'position' => $user->position,
                'is_active' => $user->is_active,
                'roles' => $user->roles->pluck('name')->values(),
            ]);

        return Inertia::render('Admin/Roles', [
            'roles' => $roles,
            'permissionGroups' => $permissions,
            'users' => $users,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('roles', 'name')],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        $role = Role::create(['name' => $validated['name'], 'guard_name' => 'web']);
        $role->syncPermissions($validated['permissions'] ?? []);

        $this->audit->record('role-created', $role, null, [
            'name' => $role->name,
            'permissions' => $validated['permissions'] ?? [],
        ]);

        return back()->with('success', "Role '{$role->name}' created.");
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        if ($role->name === 'System Administrator') {
            throw ValidationException::withMessages([
                'role' => 'The System Administrator role is immutable — it always holds every permission.',
            ]);
        }

        $validated = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        $before = $role->permissions->pluck('name')->values()->all();
        $role->syncPermissions($validated['permissions']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->audit->record('permissions-updated', $role,
            ['permissions' => $before],
            ['permissions' => $validated['permissions']],
        );

        return back()->with('success', "Permissions for '{$role->name}' updated.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        if (in_array($role->name, self::SEEDED_ROLES, true)) {
            throw ValidationException::withMessages([
                'role' => 'The BRD §4 roles ship with the product and cannot be deleted.',
            ]);
        }

        if ($role->users()->exists()) {
            throw ValidationException::withMessages([
                'role' => 'Reassign the users holding this role before deleting it.',
            ]);
        }

        $this->audit->record('role-deleted', $role, [
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->values()->all(),
        ], null);

        $role->delete();

        return back()->with('success', "Role '{$role->name}' deleted.");
    }

    public function updateUserRoles(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->tenant_id === $request->user()->tenant_id, 404);

        $validated = $request->validate([
            'roles' => ['present', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ]);

        $before = $user->roles->pluck('name')->values()->all();

        $losesAdmin = in_array('System Administrator', $before, true)
            && ! in_array('System Administrator', $validated['roles'], true);

        if ($losesAdmin && ! $this->anotherActiveAdminExists($user)) {
            throw ValidationException::withMessages([
                'roles' => 'A tenant must keep at least one active System Administrator.',
            ]);
        }

        $user->syncRoles($validated['roles']);

        $this->audit->record('roles-updated', $user,
            ['roles' => $before],
            ['roles' => $validated['roles']],
        );

        return back()->with('success', "Roles for {$user->name} updated.");
    }

    private function anotherActiveAdminExists(User $except): bool
    {
        return User::where('tenant_id', $except->tenant_id)
            ->where('id', '!=', $except->id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'System Administrator'))
            ->exists();
    }
}
