<?php

namespace App\Policies;

use App\Models\Framework;
use App\Models\User;

class FrameworkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view frameworks');
    }

    public function view(User $user, Framework $framework): bool
    {
        return $user->can('view frameworks');
    }

    public function create(User $user): bool
    {
        return $user->can('manage frameworks');
    }

    /**
     * Shipped content is maintained by content packs, not by hand: a tenant
     * customises by taking its own copy, which keeps pack updates safe.
     */
    public function update(User $user, Framework $framework): bool
    {
        return $user->can('manage frameworks') && ! $framework->isGlobal();
    }

    public function delete(User $user, Framework $framework): bool
    {
        return $user->can('manage frameworks') && ! $framework->isGlobal();
    }

    public function export(User $user, Framework $framework): bool
    {
        return $user->can('view frameworks');
    }

    public function import(User $user): bool
    {
        return $user->can('manage frameworks');
    }
}
