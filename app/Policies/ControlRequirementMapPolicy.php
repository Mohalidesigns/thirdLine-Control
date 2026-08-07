<?php

namespace App\Policies;

use App\Models\ControlRequirementMap;
use App\Models\User;

/**
 * Maker-checker on the control-to-requirement claim. There is deliberately
 * no before() bypass: a System Administrator who created a mapping cannot
 * approve it either (R2).
 */
class ControlRequirementMapPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view frameworks');
    }

    public function create(User $user): bool
    {
        return $user->can('map controls');
    }

    public function approve(User $user, ControlRequirementMap $map): bool
    {
        return $user->can('approve control-mappings')
            && $map->mapped_by !== $user->id
            && ! $map->isApproved();
    }

    public function reject(User $user, ControlRequirementMap $map): bool
    {
        return $user->can('approve control-mappings')
            && $map->mapped_by !== $user->id
            && ! $map->isApproved();
    }

    /** An approved mapping is unpicked by a checker, not by its maker. */
    public function delete(User $user, ControlRequirementMap $map): bool
    {
        return $map->isApproved()
            ? $user->can('approve control-mappings')
            : $user->can('map controls');
    }
}
