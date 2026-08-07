<?php

namespace App\Policies;

use App\Models\RegulatoryObligation;
use App\Models\User;

class RegulatoryObligationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view obligations');
    }

    public function view(User $user, RegulatoryObligation $obligation): bool
    {
        return $user->can('view obligations');
    }

    public function create(User $user): bool
    {
        return $user->can('manage obligations');
    }

    /** Shipped obligations are content-pack managed; only tenant rows are editable. */
    public function update(User $user, RegulatoryObligation $obligation): bool
    {
        return $user->can('manage obligations') && ! $obligation->isGlobal();
    }

    public function delete(User $user, RegulatoryObligation $obligation): bool
    {
        return $user->can('manage obligations') && ! $obligation->isGlobal();
    }

    public function assign(User $user, RegulatoryObligation $obligation): bool
    {
        return $user->can('assign obligations');
    }
}
