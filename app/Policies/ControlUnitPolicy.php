<?php

namespace App\Policies;

use App\Models\ControlUnit;
use App\Models\User;

/**
 * CR2-A. Deliberately no Super Admin before() bypass: structure admin is
 * 'manage control-structure'; attaching controls and naming stakeholders
 * are control-assignment acts the System Administrator does not hold.
 */
class ControlUnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view control-structure');
    }

    public function view(User $user, ControlUnit $unit): bool
    {
        return $user->can('view control-structure') && $unit->tenant_id === $user->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->can('manage control-structure');
    }

    public function update(User $user, ControlUnit $unit): bool
    {
        return $user->can('manage control-structure') && $unit->tenant_id === $user->tenant_id;
    }
}
