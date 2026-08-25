<?php

namespace App\Policies;

use App\Models\Control;
use App\Models\User;

/**
 * CR-03 §E.3. Four permissions, four different hands:
 *  - reading the catalogue is broad, including first line and the board;
 *  - editing a function is second line;
 *  - IMPORTING the bank's workbook is the administrator's, because one
 *    commit rewrites 167 controls and 1,517 checklist lines;
 *  - performing and reviewing the tasks it manufactures are separate
 *    from each other, because the officer who ticked a checklist can
 *    never sign it off.
 *
 * Registered as NAMED GATES rather than as a model policy: the model is
 * Control, and Control already has ControlPolicy governing the ordinary
 * library. Two policies for one model would silently shadow each other,
 * so the control function surface gets its own gate namespace instead.
 */
class ControlFunctionPolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole('System Administrator') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('view control-functions');
    }

    public function view(User $user, Control $control): bool
    {
        return $user->can('view control-functions') && $control->tenant_id === $user->tenant_id;
    }

    public function manage(User $user, ?Control $control = null): bool
    {
        return $user->can('manage control-functions')
            && ($control === null || $control->tenant_id === $user->tenant_id);
    }

    public function import(User $user): bool
    {
        return $user->can('import control-functions');
    }

    /**
     * Raising an event-driven occurrence is an execution act, not an
     * administrative one: somebody decided the trigger happened.
     */
    public function trigger(User $user, Control $control): bool
    {
        return $user->can('execute control-tasks') && $control->tenant_id === $user->tenant_id;
    }
}
