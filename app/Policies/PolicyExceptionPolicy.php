<?php

namespace App\Policies;

use App\Models\PolicyException;
use App\Models\User;

class PolicyExceptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view policies');
    }

    public function view(User $user, PolicyException $exception): bool
    {
        return $user->can('view policies') || $exception->requested_by === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('request policy-exceptions');
    }

    /** The requester never approves their own waiver, whatever role (R2). */
    public function decide(User $user, PolicyException $exception): bool
    {
        return $user->can('approve policy-exceptions')
            && in_array($exception->status, ['Requested', 'Under Review'], true)
            && $exception->requested_by !== $user->id;
    }

    public function revoke(User $user, PolicyException $exception): bool
    {
        return $user->can('approve policy-exceptions') && $exception->status === 'Approved';
    }
}
