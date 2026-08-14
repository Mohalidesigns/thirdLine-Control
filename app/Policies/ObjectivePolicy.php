<?php

namespace App\Policies;

use App\Models\Objective;
use App\Models\User;

class ObjectivePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view objectives');
    }

    public function view(User $user, Objective $objective): bool
    {
        return $user->can('view objectives') || $objective->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('manage objectives');
    }

    public function update(User $user, Objective $objective): bool
    {
        return $user->can('manage objectives');
    }

    /**
     * An owner reports progress on their own objective without holding the
     * strategy-authoring permission — reporting is not authoring.
     */
    public function reportProgress(User $user, Objective $objective): bool
    {
        return $user->can('manage objectives') || $objective->owner_id === $user->id;
    }

    /**
     * Approving an objective into the live scorecard is a second person's
     * act: the person who drafted it cannot be the one who activates it
     * (R2 — no admin bypass, the check is on identity, not on role).
     */
    public function approve(User $user, Objective $objective): bool
    {
        return $user->can('approve objectives')
            && $objective->created_by !== $user->id
            && $objective->status === 'Draft';
    }

    public function delete(User $user, Objective $objective): bool
    {
        return $user->can('manage objectives') && $objective->status === 'Draft';
    }
}
