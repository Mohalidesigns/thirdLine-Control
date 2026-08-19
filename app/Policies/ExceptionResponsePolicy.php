<?php

namespace App\Policies;

use App\Models\ExceptionResponse;
use App\Models\User;

/** CR-01. No admin bypass — R-B binds every role. */
class ExceptionResponsePolicy
{
    public function view(User $user, ExceptionResponse $response): bool
    {
        if ($user->hasAnyRole(['System Administrator', 'Control Function Head', 'Control Officer', 'Executive Viewer', 'Line Manager'])) {
            return true;
        }

        return $response->responder_id === $user->id
            || $response->escalation?->respondent_id === $user->id;
    }

    /**
     * R-B: the user who submitted a response cannot review it. No
     * exceptions, no admin bypass.
     */
    public function review(User $user, ExceptionResponse $response): bool
    {
        return $user->can('review exception-responses')
            && $response->review_status === 'Pending Review'
            && $response->submitted_at !== null
            && $response->responder_id !== $user->id;
    }

    /** R-C: acceptance additionally requires closure authority. */
    public function accept(User $user, ExceptionResponse $response): bool
    {
        return $this->review($user, $response)
            && $user->can('close exceptions');
    }
}
