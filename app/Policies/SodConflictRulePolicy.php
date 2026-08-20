<?php

namespace App\Policies;

use App\Models\SodConflictRule;
use App\Models\User;

/**
 * The toxic-combination matrix and what it finds. Note the distinction
 * these permissions encode: reading the matrix is broad, editing it is
 * second-line, and *accepting* a live conflict is a risk decision that
 * needs its own permission and an expiry date.
 */
class SodConflictRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view sod');
    }

    public function view(User $user, SodConflictRule $rule): bool
    {
        return $user->can('view sod');
    }

    public function create(User $user): bool
    {
        return $user->can('manage sod-rules');
    }

    public function update(User $user, SodConflictRule $rule): bool
    {
        return $user->can('manage sod-rules');
    }

    public function delete(User $user, SodConflictRule $rule): bool
    {
        return $user->can('manage sod-rules');
    }
}
