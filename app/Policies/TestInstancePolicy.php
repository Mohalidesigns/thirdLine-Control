<?php

namespace App\Policies;

use App\Models\TestInstance;
use App\Models\User;

class TestInstancePolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole('System Administrator') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TestInstance $instance): bool
    {
        if ($user->hasAnyRole(['Control Function Head', 'Control Officer', 'Executive Viewer', 'Line Manager'])) {
            return true;
        }

        return $instance->assigned_tester_id === $user->id
            || $instance->control?->owner_id === $user->id;
    }

    public function execute(User $user, TestInstance $instance): bool
    {
        if ($instance->isLocked()) {
            return false;
        }

        return $user->hasAnyRole(['Control Officer', 'Control Function Head'])
            && ($instance->assigned_tester_id === null || $instance->assigned_tester_id === $user->id);
    }

    /**
     * Segregation of duties (§4): the tester who executed a test can never
     * review it. Review is a control function sign-off.
     */
    public function review(User $user, TestInstance $instance): bool
    {
        return $user->hasRole('Control Function Head')
            && $instance->assigned_tester_id !== $user->id
            && $instance->status === 'Submitted';
    }

    public function reopen(User $user, TestInstance $instance): bool
    {
        return $user->hasRole('Control Function Head') && $instance->isLocked();
    }

    public function rate(User $user, TestInstance $instance): bool
    {
        return $user->hasAnyRole(['Control Officer', 'Control Function Head']);
    }

    /**
     * Rating approval mirrors maker-checker: the proposer cannot publish
     * their own rating (FR-7.6).
     */
    public function approveRating(User $user, TestInstance $instance): bool
    {
        return $user->hasRole('Control Function Head')
            && $instance->effectivenessRating?->rated_by !== $user->id;
    }
}
