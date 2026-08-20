<?php

namespace App\Policies;

use App\Models\Entity;
use App\Models\User;

class EntityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view entities');
    }

    /**
     * Seeing an entity's data requires an explicit grant on top of the
     * permission — group visibility is never implicit, for any role (R2).
     */
    public function view(User $user, Entity $entity): bool
    {
        return $user->can('view entities')
            && $entity->grantedUsers()->where('users.id', $user->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('manage entities');
    }

    public function update(User $user, Entity $entity): bool
    {
        return $user->can('manage entities');
    }

    public function grantAccess(User $user, Entity $entity): bool
    {
        return $user->can('grant entity-access');
    }
}
