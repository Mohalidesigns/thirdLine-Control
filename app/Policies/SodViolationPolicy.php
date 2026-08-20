<?php

namespace App\Policies;

use App\Models\SodViolation;
use App\Models\User;

/**
 * What may be done about a detected toxic combination.
 *
 * Mitigating one is a second-line finding of fact; *accepting* one is a
 * risk decision with its own permission and a mandatory expiry, because
 * an open-ended acceptance is how a teller ends up able to authorise.
 */
class SodViolationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view sod');
    }

    public function view(User $user, SodViolation $violation): bool
    {
        return $user->can('view sod');
    }

    public function mitigate(User $user, SodViolation $violation): bool
    {
        return $user->can('manage sod-rules') && $violation->status !== 'Remediated';
    }

    public function accept(User $user, SodViolation $violation): bool
    {
        return $user->can('accept sod-violations') && $violation->status !== 'Remediated';
    }

    public function remediate(User $user, SodViolation $violation): bool
    {
        return $user->can('manage sod-rules');
    }

    /** Raising the exception is the escalation path when nothing mitigates. */
    public function escalate(User $user, SodViolation $violation): bool
    {
        return $user->can('manage sod-rules') && $violation->status === 'Open';
    }
}
