<?php

namespace App\Policies;

use App\Models\CrossBorderTransfer;
use App\Models\User;

class CrossBorderTransferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view residency');
    }

    public function create(User $user): bool
    {
        return $user->can('record transfers');
    }

    /**
     * A second person's act: the permission plus not being the recorder.
     * The service enforces the same rule again — no admin bypass (R2).
     */
    public function authorise(User $user, CrossBorderTransfer $transfer): bool
    {
        return $user->can('authorise transfers') && $transfer->recorded_by !== $user->id;
    }

    public function complete(User $user, CrossBorderTransfer $transfer): bool
    {
        return $user->can('record transfers') && $transfer->status === 'authorised';
    }
}
