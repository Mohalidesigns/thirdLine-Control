<?php

namespace App\Policies;

use App\Models\RegulatoryChange;
use App\Models\User;

class RegulatoryChangePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view regulatory-changes');
    }

    public function view(User $user, RegulatoryChange $change): bool
    {
        return $user->can('view regulatory-changes');
    }

    public function create(User $user): bool
    {
        return $user->can('assess regulatory-changes');
    }

    public function assess(User $user, RegulatoryChange $change): bool
    {
        return $user->can('assess regulatory-changes')
            && in_array($change->status, ['New', 'Under Review', 'Not Applicable'], true);
    }

    /** Maker-checker: the assessor cannot sign off their own assessment (R2). */
    public function action(User $user, RegulatoryChange $change): bool
    {
        return $user->can('action regulatory-changes')
            && $change->status === 'Impact Assessed'
            && $change->assessed_by !== $user->id;
    }
}
