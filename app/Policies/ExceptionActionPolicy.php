<?php

namespace App\Policies;

use App\Models\ExceptionAction;
use App\Models\User;

/** CR-01: the department progresses its own commitments; the control function validates. */
class ExceptionActionPolicy
{
    public function view(User $user, ExceptionAction $action): bool
    {
        if ($user->hasAnyRole(['System Administrator', 'Control Function Head', 'Control Officer', 'Executive Viewer', 'Line Manager'])) {
            return true;
        }

        return $action->owner_id === $user->id
            || $action->escalation?->respondent_id === $user->id;
    }

    /** The owning department moves its action along — never past Completed. */
    public function progress(User $user, ExceptionAction $action): bool
    {
        if (in_array($action->status, ['Completed', 'Cancelled'], true)) {
            return false;
        }

        return $action->owner_id === $user->id
            || $action->escalation?->respondent_id === $user->id
            || $user->hasAnyRole(['Control Officer', 'Control Function Head']);
    }

    public function complete(User $user, ExceptionAction $action): bool
    {
        return $this->progress($user, $action);
    }

    /**
     * Validation of a completed action is the control function's act
     * (CR1.5), mirroring FR-5.4 — the department never validates itself.
     */
    public function validateAction(User $user, ExceptionAction $action): bool
    {
        return $user->hasRole('Control Function Head')
            && $user->can('close exceptions')
            && $action->status === 'Completed'
            && $action->owner_id !== $user->id;
    }
}
