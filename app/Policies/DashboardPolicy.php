<?php

namespace App\Policies;

use App\Models\Dashboard;
use App\Models\User;

/**
 * Who may open and compose a dashboard.
 *
 * Being able to open a board is never the same as being able to read
 * everything on it: this policy gates the container, and WidgetRegistry
 * gates each tile's data separately. A viewer with access to a board they
 * are only partly entitled to sees the tiles they may see and a refusal on
 * the rest — never another person's numbers.
 */
class DashboardPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view dashboards');
    }

    public function view(User $user, Dashboard $dashboard): bool
    {
        if (! $user->can('view dashboards')) {
            return false;
        }

        return match ($dashboard->visibility) {
            'tenant', 'public_link' => true,
            'private' => $dashboard->owner_id === $user->id,
            'role' => array_intersect(
                $dashboard->role_ids ?? [],
                $user->roles->pluck('id')->all(),
            ) !== [],
            default => false,
        };
    }

    public function create(User $user): bool
    {
        return $user->can('manage dashboards');
    }

    /** A shipped dashboard is read-only for everyone. Duplicate and edit. */
    public function update(User $user, Dashboard $dashboard): bool
    {
        if ($dashboard->is_system) {
            return false;
        }

        return $dashboard->owner_id === $user->id
            || ($user->can('manage dashboards') && $dashboard->visibility !== 'private');
    }

    public function delete(User $user, Dashboard $dashboard): bool
    {
        return ! $dashboard->is_system && $this->update($user, $dashboard);
    }

    /** Duplicating only needs the right to compose and to see the original. */
    public function duplicate(User $user, Dashboard $dashboard): bool
    {
        return $user->can('manage dashboards') && $this->view($user, $dashboard);
    }

    public function publish(User $user, Dashboard $dashboard): bool
    {
        return $user->can('publish dashboards') && ! $dashboard->is_system;
    }
}
