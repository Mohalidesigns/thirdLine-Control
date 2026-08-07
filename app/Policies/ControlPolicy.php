<?php

namespace App\Policies;

use App\Models\Control;
use App\Models\User;

class ControlPolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole('System Administrator') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Control $control): bool
    {
        if ($user->hasAnyRole(['Control Function Head', 'Control Officer', 'Executive Viewer', 'Line Manager'])) {
            return true;
        }

        // A control owner sees the controls they own (§4).
        return $control->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['Control Officer', 'Control Function Head']);
    }

    public function update(User $user, Control $control): bool
    {
        return $user->hasAnyRole(['Control Officer', 'Control Function Head'])
            && $control->status !== 'Retired';
    }

    public function delete(User $user, Control $control): bool
    {
        return $user->hasRole('Control Function Head') && $control->status === 'Draft';
    }

    /**
     * Maker-checker (FR-1.8): only a Control Function Head approves, and
     * never a control they created themselves.
     */
    public function approve(User $user, Control $control): bool
    {
        return $user->hasRole('Control Function Head')
            && $control->created_by !== $user->id
            && $control->status === 'Pending Approval';
    }

    public function retire(User $user, Control $control): bool
    {
        return $user->hasRole('Control Function Head');
    }

    /**
     * Effectiveness self-assessment: officers and function heads, plus the
     * control's own owner, may assess an active control.
     */
    public function assess(User $user, Control $control): bool
    {
        if ($control->status === 'Retired') {
            return false;
        }

        return $user->hasAnyRole(['Control Officer', 'Control Function Head'])
            || $control->owner_id === $user->id;
    }
}
