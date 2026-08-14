<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VendorAssessment;

class VendorAssessmentPolicy
{
    public function view(User $user, VendorAssessment $assessment): bool
    {
        return $user->can('view vendors');
    }

    public function create(User $user): bool
    {
        return $user->can('assess vendors');
    }

    public function update(User $user, VendorAssessment $assessment): bool
    {
        return $user->can('assess vendors')
            && in_array($assessment->status, ['Draft', 'In Progress', 'Rejected'], true);
    }

    public function submit(User $user, VendorAssessment $assessment): bool
    {
        return $user->can('assess vendors')
            && in_array($assessment->status, ['Draft', 'In Progress', 'Rejected'], true);
    }

    /**
     * The assessor cannot approve their own conclusion, and holding every
     * permission in the system does not change that (R2, no admin bypass).
     * The service enforces the same rule for non-request callers.
     */
    public function approve(User $user, VendorAssessment $assessment): bool
    {
        return $user->can('approve vendor-assessments')
            && $assessment->status === 'Submitted'
            && $assessment->assessed_by !== $user->id;
    }
}
