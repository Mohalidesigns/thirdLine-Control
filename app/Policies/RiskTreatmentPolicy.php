<?php

namespace App\Policies;

use App\Models\RiskTreatment;
use App\Models\User;

class RiskTreatmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view treatments');
    }

    public function view(User $user, RiskTreatment $treatment): bool
    {
        return $user->can('view treatments') || $treatment->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('create treatments');
    }

    public function update(User $user, RiskTreatment $treatment): bool
    {
        return ($treatment->owner_id === $user->id || $user->can('create treatments'))
            && ! in_array($treatment->status, ['Verified', 'Cancelled'], true);
    }

    /** Approval is never the plan owner's to give (R2). */
    public function approve(User $user, RiskTreatment $treatment): bool
    {
        if ($treatment->status !== 'Proposed' || $treatment->owner_id === $user->id) {
            return false;
        }

        // Accepting a risk is a Control Function Head decision only, with no
        // administrator bypass — it mirrors ExceptionPolicy::acceptRisk.
        return $treatment->strategy === 'Accept'
            ? $user->hasRole('Control Function Head')
            : $user->can('approve treatments');
    }

    public function progress(User $user, RiskTreatment $treatment): bool
    {
        return in_array($treatment->status, ['Approved', 'In Progress', 'Overdue'], true)
            && ($treatment->owner_id === $user->id || $user->can('approve treatments'));
    }

    /**
     * The failing path the phase brief names: whoever owns the plan can
     * never be the one who verifies it, whatever role they hold.
     */
    public function verify(User $user, RiskTreatment $treatment): bool
    {
        return $user->can('verify treatments')
            && $treatment->status === 'Implemented'
            && $treatment->owner_id !== $user->id;
    }
}
