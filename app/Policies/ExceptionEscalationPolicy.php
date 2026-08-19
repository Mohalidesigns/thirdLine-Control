<?php

namespace App\Policies;

use App\Models\ExceptionEscalation;
use App\Models\User;

/**
 * CR-01. Deliberately NO System Administrator / admin bypass anywhere in
 * this policy: the segregation-of-duties rules R-A..R-D bind every role.
 */
class ExceptionEscalationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view exceptions');
    }

    public function view(User $user, ExceptionEscalation $escalation): bool
    {
        if ($user->hasAnyRole(['System Administrator', 'Control Function Head', 'Control Officer', 'Executive Viewer', 'Line Manager'])) {
            return true;
        }

        return $escalation->respondent_id === $user->id
            || in_array($user->id, $escalation->cc_user_ids ?? [], true)
            || $escalation->exception?->owner_id === $user->id;
    }

    /** CR1.1: issuing the exception to departments. */
    public function issue(User $user): bool
    {
        return $user->can('escalate exceptions');
    }

    /**
     * CR1.3 + R-A: only the ADDRESSED respondent answers, and never the user
     * who raised the exception or issued the escalation — the control
     * function cannot answer its own notice.
     */
    public function respond(User $user, ExceptionEscalation $escalation): bool
    {
        if (! $user->can('respond exceptions')) {
            return false;
        }

        if (! $escalation->isAwaitingResponse()) {
            return false;
        }

        if ($escalation->respondent_id !== $user->id) {
            return false;
        }

        return $escalation->exception?->raised_by !== $user->id
            && $escalation->issued_by !== $user->id;
    }

    /**
     * CR1.4: review (reject) needs 'review exception-responses'. R-B — the
     * submitter of the round under review is checked per response in the
     * service and again in the controller.
     */
    public function review(User $user, ExceptionEscalation $escalation): bool
    {
        return $user->can('review exception-responses')
            && $escalation->status === 'Responded';
    }

    /**
     * R-C: accepting a response is a closure act — it needs BOTH the review
     * permission and 'close exceptions'. A System Administrator holds
     * neither review nor escalate and is refused here, by design.
     */
    public function accept(User $user, ExceptionEscalation $escalation): bool
    {
        return $this->review($user, $escalation)
            && $user->can('close exceptions');
    }

    /**
     * CR1.5: validating the actions and closing the escalation is the
     * control function's act, mirroring FR-5.4 exactly.
     */
    public function close(User $user, ExceptionEscalation $escalation): bool
    {
        return $user->hasRole('Control Function Head')
            && $user->can('close exceptions')
            && $escalation->status === 'Accepted';
    }

    public function withdraw(User $user, ExceptionEscalation $escalation): bool
    {
        return $user->can('withdraw exceptions')
            && $escalation->isOpen();
    }

    /** Secure links are generated and revoked by whoever may issue. */
    public function manageLinks(User $user, ExceptionEscalation $escalation): bool
    {
        return $user->can('escalate exceptions');
    }
}
