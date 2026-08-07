<?php

namespace App\Policies;

use App\Models\ObligationInstance;
use App\Models\User;

/**
 * No before() bypass by design: recording a filing and accepting it are two
 * different people's jobs, administrator or not (R2).
 */
class ObligationInstancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view obligations');
    }

    public function view(User $user, ObligationInstance $instance): bool
    {
        if ($user->can('view obligations')) {
            return true;
        }

        return in_array($user->id, [
            $instance->assignment?->owner_id,
            $instance->assignment?->reviewer_id,
        ], true);
    }

    public function update(User $user, ObligationInstance $instance): bool
    {
        return ! $instance->isClosed()
            && ($user->can('submit obligations') || $instance->assignment?->owner_id === $user->id);
    }

    public function submit(User $user, ObligationInstance $instance): bool
    {
        return in_array($instance->status, ['Not Started', 'In Progress', 'Overdue', 'Rejected'], true)
            && ($user->can('submit obligations') || $instance->assignment?->owner_id === $user->id);
    }

    public function review(User $user, ObligationInstance $instance): bool
    {
        return $user->can('approve obligation-submissions')
            && $instance->status === 'Submitted'
            && $instance->submitted_by !== $user->id;
    }

    public function waive(User $user, ObligationInstance $instance): bool
    {
        return $user->can('waive obligations') && ! $instance->isClosed();
    }

    public function attachEvidence(User $user, ObligationInstance $instance): bool
    {
        return $this->update($user, $instance);
    }
}
