<?php

namespace App\Policies;

use App\Models\Initiative;
use App\Models\User;

class InitiativePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view objectives');
    }

    public function view(User $user, Initiative $initiative): bool
    {
        return $user->can('view objectives') || $initiative->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('manage initiatives');
    }

    public function update(User $user, Initiative $initiative): bool
    {
        return $user->can('manage initiatives') || $initiative->owner_id === $user->id;
    }

    /** Milestone completion is the owner's day-to-day act. */
    public function progress(User $user, Initiative $initiative): bool
    {
        return $user->can('manage initiatives') || $initiative->owner_id === $user->id;
    }
}
