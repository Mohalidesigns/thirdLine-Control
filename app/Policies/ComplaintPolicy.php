<?php

namespace App\Policies;

use App\Models\Complaint;
use App\Models\User;

class ComplaintPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view complaints');
    }

    public function view(User $user, Complaint $complaint): bool
    {
        return $user->can('view complaints') || $complaint->assigned_to === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('create complaints');
    }

    public function update(User $user, Complaint $complaint): bool
    {
        return $complaint->status !== 'Closed'
            && ($user->can('handle complaints') || $complaint->assigned_to === $user->id);
    }

    /**
     * Acknowledgement stops a regulatory clock, so it is a handling right,
     * not a viewing one — and the time it records is always the server's.
     */
    public function acknowledge(User $user, Complaint $complaint): bool
    {
        return $user->can('handle complaints') && $complaint->acknowledged_at === null;
    }

    public function resolve(User $user, Complaint $complaint): bool
    {
        return $this->update($user, $complaint)
            && in_array($complaint->status, ['Acknowledged', 'Under Investigation', 'Awaiting Customer', 'Reopened'], true);
    }

    /** Closing off a resolved complaint is a supervisory act, not the handler's. */
    public function close(User $user, Complaint $complaint): bool
    {
        return $user->can('close complaints') && $complaint->status === 'Resolved';
    }

    public function escalate(User $user, Complaint $complaint): bool
    {
        return $user->can('close complaints');
    }

    public function export(User $user): bool
    {
        return $user->can('export complaint-returns');
    }
}
