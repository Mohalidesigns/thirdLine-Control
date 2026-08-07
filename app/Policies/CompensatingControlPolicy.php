<?php

namespace App\Policies;

use App\Models\CompensatingControl;
use App\Models\User;

class CompensatingControlPolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole('System Administrator') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['Control Officer', 'Control Function Head', 'Control Owner']);
    }

    /**
     * Control function approval before a compensating control counts in
     * residual risk (FR-6.3); proposer cannot self-approve.
     */
    public function approve(User $user, CompensatingControl $compensating): bool
    {
        return $user->hasRole('Control Function Head')
            && $compensating->status === 'Proposed'
            && $compensating->owner_id !== $user->id;
    }

    public function update(User $user, CompensatingControl $compensating): bool
    {
        return $user->hasAnyRole(['Control Officer', 'Control Function Head'])
            && in_array($compensating->status, ['Proposed', 'Approved', 'Active'], true);
    }
}
