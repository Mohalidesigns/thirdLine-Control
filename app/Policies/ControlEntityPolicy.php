<?php

namespace App\Policies;

use App\Models\ControlEntity;
use App\Models\User;

/**
 * CR2-A. 'manage control-structure' governs the shape of the universe;
 * 'attach control-entities' governs which controls sit under which
 * entity — separate authorities, deliberately (structure admin is not
 * control assignment).
 */
class ControlEntityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view control-structure');
    }

    public function view(User $user, ControlEntity $entity): bool
    {
        return $user->can('view control-structure') && $entity->tenant_id === $user->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->can('manage control-structure');
    }

    public function update(User $user, ControlEntity $entity): bool
    {
        return $user->can('manage control-structure') && $entity->tenant_id === $user->tenant_id;
    }

    public function attach(User $user, ControlEntity $entity): bool
    {
        return $user->can('attach control-entities') && $entity->tenant_id === $user->tenant_id;
    }
}
