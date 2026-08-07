<?php

namespace App\Policies;

use App\Models\ObligationAssignment;
use App\Models\User;

class ObligationAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view obligations');
    }

    public function view(User $user, ObligationAssignment $assignment): bool
    {
        return $user->can('view obligations')
            || in_array($user->id, [$assignment->owner_id, $assignment->reviewer_id], true);
    }

    public function create(User $user): bool
    {
        return $user->can('assign obligations');
    }

    public function update(User $user, ObligationAssignment $assignment): bool
    {
        return $user->can('assign obligations');
    }

    /**
     * Declaring an obligation inapplicable is a compliance judgement the
     * owner may make; approving it is a second pair of eyes, never the
     * owner's own (R2), and there is no administrator bypass.
     */
    public function declareNonApplicable(User $user, ObligationAssignment $assignment): bool
    {
        return $user->can('assign obligations') || $assignment->owner_id === $user->id;
    }

    public function approveNonApplicability(User $user, ObligationAssignment $assignment): bool
    {
        return $user->can('approve obligation-submissions')
            && $assignment->owner_id !== $user->id
            && ! $assignment->is_applicable
            && $assignment->approved_at === null;
    }
}
