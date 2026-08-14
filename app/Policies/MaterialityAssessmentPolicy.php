<?php

namespace App\Policies;

use App\Models\MaterialityAssessment;
use App\Models\User;

class MaterialityAssessmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view sustainability');
    }

    public function view(User $user, MaterialityAssessment $assessment): bool
    {
        return $user->can('view sustainability');
    }

    public function create(User $user): bool
    {
        return $user->can('manage sustainability');
    }

    public function update(User $user, MaterialityAssessment $assessment): bool
    {
        return $user->can('manage sustainability')
            && in_array($assessment->status, ['Draft', 'Submitted', 'Rejected'], true);
    }

    /**
     * Determining a topic immaterial is deciding not to disclose it, so the
     * assessor may not also approve it (R2).
     */
    public function approve(User $user, MaterialityAssessment $assessment): bool
    {
        return $user->can('approve materiality')
            && $assessment->status === 'Submitted'
            && $assessment->assessed_by !== $user->id;
    }
}
