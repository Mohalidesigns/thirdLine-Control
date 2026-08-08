<?php

namespace App\Policies;

use App\Models\Policy;
use App\Models\User;

/**
 * Authorization for governing policies (11.1). The gate the phase brief
 * names — publication requires an approver different from the owner — is
 * enforced here and re-checked in PolicyService, with no administrator
 * bypass on either path (R2).
 */
class PolicyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view policies');
    }

    public function view(User $user, Policy $policy): bool
    {
        return $user->can('view policies') || $policy->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('create policies');
    }

    public function update(User $user, Policy $policy): bool
    {
        return ($policy->owner_id === $user->id || $user->can('create policies'))
            && ! in_array($policy->status, ['Superseded', 'Withdrawn'], true);
    }

    public function submit(User $user, Policy $policy): bool
    {
        return $this->update($user, $policy)
            && in_array($policy->status, ['Draft', 'Under Review', 'Under Revision'], true);
    }

    public function approve(User $user, Policy $policy): bool
    {
        return $user->can('approve policies')
            && $policy->status === 'Pending Approval'
            && $policy->owner_id !== $user->id;
    }

    /** The stated rule: a policy's owner never publishes it. */
    public function publish(User $user, Policy $policy): bool
    {
        return $user->can('publish policies')
            && $policy->status === 'Approved'
            && $policy->owner_id !== $user->id;
    }

    public function revise(User $user, Policy $policy): bool
    {
        return $user->can('create policies') && $policy->status === 'Published';
    }

    public function withdraw(User $user, Policy $policy): bool
    {
        return $user->can('approve policies')
            && ! in_array($policy->status, ['Superseded', 'Withdrawn'], true);
    }
}
