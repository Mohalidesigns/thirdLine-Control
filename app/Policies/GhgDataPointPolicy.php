<?php

namespace App\Policies;

use App\Models\GhgDataPoint;
use App\Models\User;

class GhgDataPointPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view sustainability');
    }

    public function view(User $user, GhgDataPoint $point): bool
    {
        return $user->can('view sustainability');
    }

    public function create(User $user): bool
    {
        return $user->can('prepare ghg-data');
    }

    public function update(User $user, GhgDataPoint $point): bool
    {
        return $user->can('prepare ghg-data')
            && in_array($point->status, ['Draft', 'Prepared', 'Rejected'], true);
    }

    /**
     * The preparer cannot verify their own figure. Holding every permission
     * in the product does not change that (R2, no admin bypass) — and the
     * service enforces the same rule for any non-request caller.
     */
    public function verify(User $user, GhgDataPoint $point): bool
    {
        return $user->can('verify ghg-data')
            && $point->status === 'Prepared'
            && $point->prepared_by !== $user->id;
    }

    public function defer(User $user, GhgDataPoint $point): bool
    {
        return $user->can('manage sustainability') && $point->scope === '3';
    }
}
