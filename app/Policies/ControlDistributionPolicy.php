<?php

namespace App\Policies;

use App\Models\ControlDistribution;
use App\Models\User;

class ControlDistributionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view distributions');
    }

    public function view(User $user, ControlDistribution $distribution): bool
    {
        if ($user->can('manage distributions') || $user->can('distribute controls')) {
            return true;
        }

        return $user->can('view distributions')
            && ($distribution->assigned_owner_id === $user->id
                || $distribution->entity?->head_user_id === $user->id
                || $distribution->tasks->contains('owner_id', $user->id));
    }

    public function distribute(User $user): bool
    {
        return $user->can('distribute controls');
    }

    /** Only the assigned entity owner acknowledges receipt. */
    public function acknowledge(User $user, ControlDistribution $distribution): bool
    {
        return $distribution->status === 'Pending'
            && $distribution->assigned_owner_id === $user->id;
    }

    public function progressTask(User $user, ControlDistribution $distribution): bool
    {
        if (in_array($distribution->status, ['Declined', 'Completed'], true)) {
            return false;
        }

        return $distribution->assigned_owner_id === $user->id
            || $user->can('manage distributions');
    }

    public function requestDecline(User $user, ControlDistribution $distribution): bool
    {
        return in_array($distribution->status, ['Pending', 'Acknowledged', 'In Progress'], true)
            && $distribution->assigned_owner_id === $user->id;
    }

    /**
     * Decline takes effect only on Control Function Head approval — and
     * never by the owner who requested it (no admin bypass, R2).
     */
    public function decideDecline(User $user, ControlDistribution $distribution): bool
    {
        return $distribution->status === 'Decline Requested'
            && $user->hasRole('Control Function Head')
            && $distribution->assigned_owner_id !== $user->id;
    }
}
