<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\AccessChangeAlertNotification;
use App\Notifications\AccountChangedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Segregation of duties on access itself (BRD §4). The System
 * Administrator holds the platform keys but is deliberately denied the
 * exception-handling rights (see RolePermissionSeeder); this keeps that
 * line from being crossed through the role screens:
 *
 *   - nobody changes their own roles — another administrator must;
 *   - no account combines System Administrator with a role carrying a
 *     right the System Administrator role is denied;
 *   - no role held by a System Administrator gains such a right.
 *
 * Every change to someone's access is told to them, and grants of
 * administrator rights or edits to a role's permissions are told to the
 * other administrators.
 */
class UserAccessService
{
    public const ADMIN_ROLE = 'System Administrator';

    public function __construct(private NotificationDispatcher $dispatcher) {}

    /**
     * @param  array<int, string>  $before
     * @param  array<int, string>  $after
     */
    public function assertCanAssign(User $actor, User $target, array $before, array $after): void
    {
        if (! $this->differs($before, $after)) {
            return;
        }

        if ($actor->id === $target->id) {
            throw ValidationException::withMessages([
                'roles' => 'You cannot change your own roles — ask another System Administrator.',
            ]);
        }

        $this->assertNoAdminConflict($after);
    }

    /** @param  array<int, string>  $roles */
    public function assertNoAdminConflict(array $roles): void
    {
        if (! in_array(self::ADMIN_ROLE, $roles, true)) {
            return;
        }

        $conflicting = Role::whereIn('name', array_diff($roles, [self::ADMIN_ROLE]))
            ->with('permissions:id,name')
            ->get()
            ->filter(fn (Role $role) => $role->permissions->pluck('name')->intersect($this->deniedToAdmin())->isNotEmpty())
            ->pluck('name');

        if ($conflicting->isNotEmpty()) {
            throw ValidationException::withMessages([
                'roles' => 'A System Administrator cannot also hold '.$conflicting->implode(', ')
                    .': it carries exception-handling rights the administrator role is denied.',
            ]);
        }
    }

    /**
     * A role held by any System Administrator may not gain a right the
     * administrator role is denied — that would hand it to them by the
     * back door.
     *
     * @param  array<int, string>  $permissions
     */
    public function assertRoleKeepsSeparation(?Role $role, array $permissions): void
    {
        $denied = collect($permissions)->intersect($this->deniedToAdmin())->values();

        if ($denied->isEmpty() || ! $role) {
            return;
        }

        $heldByAdmin = User::whereHas('roles', fn ($q) => $q->where('name', self::ADMIN_ROLE))
            ->whereHas('roles', fn ($q) => $q->whereKey($role->id))
            ->exists();

        if ($heldByAdmin) {
            throw ValidationException::withMessages([
                'permissions' => "'{$role->name}' is held by a System Administrator, so it cannot carry: "
                    .$denied->implode(', ').'.',
            ]);
        }
    }

    /**
     * @param  array<int, string>  $before
     * @param  array<int, string>  $after
     */
    public function rolesChanged(User $actor, User $target, array $before, array $after): void
    {
        if (! $this->differs($before, $after)) {
            return;
        }

        $this->send($this->targetUnlessActor($target, $actor), 'account.roles-changed',
            new AccountChangedNotification(AccountChangedNotification::ROLES_CHANGED, $actor->name, $after));

        if (in_array(self::ADMIN_ROLE, $after, true) && ! in_array(self::ADMIN_ROLE, $before, true)) {
            $this->alertAdmins($actor, new AccessChangeAlertNotification(
                AccessChangeAlertNotification::ADMIN_GRANTED, $actor->name, $target->name));
        }
    }

    /**
     * A new account arrives with its invitation, so only the
     * administrator alert applies.
     *
     * @param  array<int, string>  $roles
     */
    public function userCreated(User $actor, User $user, array $roles): void
    {
        if (in_array(self::ADMIN_ROLE, $roles, true)) {
            $this->alertAdmins($actor, new AccessChangeAlertNotification(
                AccessChangeAlertNotification::ADMIN_GRANTED, $actor->name, $user->name));
        }
    }

    /** @param  array<int, string>  $permissions */
    public function rolePermissionsChanged(User $actor, Role $role, array $permissions): void
    {
        $this->alertAdmins($actor, new AccessChangeAlertNotification(
            AccessChangeAlertNotification::ROLE_PERMISSIONS_CHANGED, $actor->name, $role->name, $permissions));
    }

    public function accountChanged(User $actor, User $target, string $kind, ?string $detail = null): void
    {
        $this->send($this->targetUnlessActor($target, $actor), 'account.'.str_replace('_', '-', $kind),
            new AccountChangedNotification($kind, $actor->name, [], $detail));
    }

    /**
     * The old address hears about an email change too — if the change was
     * not theirs, it is the only place they would find out.
     */
    public function emailChanged(User $actor, User $target, string $oldEmail): void
    {
        $notification = new AccountChangedNotification(
            AccountChangedNotification::EMAIL_CHANGED, $actor->name, [], $target->email);

        $this->send($this->targetUnlessActor($target, $actor), 'account.email-changed', $notification);

        try {
            $this->dispatcher->sendExternal($oldEmail, 'account.email-changed', $notification);
        } catch (\Throwable $e) {
            Log::warning('Account change notification failed', ['user_id' => $target->id, 'error' => $e->getMessage()]);
        }
    }

    /** @return Collection<int, string> */
    private function deniedToAdmin(): Collection
    {
        $admin = Role::where('name', self::ADMIN_ROLE)->with('permissions:id,name')->first();

        return $admin
            ? Permission::pluck('name')->diff($admin->permissions->pluck('name'))->values()
            : collect();
    }

    private function differs(array $before, array $after): bool
    {
        return collect($before)->sort()->values()->all() !== collect($after)->sort()->values()->all();
    }

    private function alertAdmins(User $actor, AccessChangeAlertNotification $notification): void
    {
        $admins = User::where('tenant_id', $actor->tenant_id)
            ->where('id', '!=', $actor->id)
            ->where('is_active', true)
            ->role(self::ADMIN_ROLE)
            ->get();

        $this->send($admins, 'security.access-changed', $notification);
    }

    /**
     * The account holder, unless they made the change themselves. A
     * suspended account still gets the email, so no is_active filter.
     */
    private function targetUnlessActor(User $target, User $actor): Collection
    {
        return collect($target->id === $actor->id ? [] : [$target]);
    }

    /** Fire-and-log — a notification hiccup never blocks an admin change. */
    private function send(iterable $recipients, string $eventKey, $notification): void
    {
        try {
            $this->dispatcher->send($recipients, $eventKey, $notification);
        } catch (\Throwable $e) {
            Log::warning('Account change notification failed', ['event' => $eventKey, 'error' => $e->getMessage()]);
        }
    }
}
