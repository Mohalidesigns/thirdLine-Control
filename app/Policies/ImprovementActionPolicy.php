<?php

namespace App\Policies;

use App\Models\ImprovementAction;
use App\Models\User;

class ImprovementActionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view improvements');
    }

    public function view(User $user, ImprovementAction $action): bool
    {
        return $user->can('view improvements') || $action->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('create improvements');
    }

    public function approve(User $user, ImprovementAction $action): bool
    {
        return $user->can('approve improvements')
            && $action->status === 'Proposed';
    }

    public function progress(User $user, ImprovementAction $action): bool
    {
        return in_array($action->status, ['Approved', 'In Progress'], true)
            && ($action->owner_id === $user->id || $user->can('approve improvements'));
    }

    /** Independent verification: verifier ≠ owner, no bypass (R2). */
    public function verify(User $user, ImprovementAction $action): bool
    {
        return $user->can('verify improvements')
            && $action->status === 'Implemented'
            && $action->owner_id !== $user->id;
    }
}
