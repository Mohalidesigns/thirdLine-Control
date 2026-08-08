<?php

namespace App\Policies;

use App\Models\CsaResponse;
use App\Models\User;

class CsaResponsePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view campaigns') || $user->can('respond campaigns');
    }

    public function view(User $user, CsaResponse $response): bool
    {
        // Anonymous responses are aggregate-only: no role reads the row.
        if ($response->campaign->is_anonymous) {
            return false;
        }

        return $response->respondent_id === $user->id
            || $user->can('review campaigns')
            || $user->can('view campaigns');
    }

    /** Only the assigned respondent fills in their own response. */
    public function respond(User $user, CsaResponse $response): bool
    {
        return $response->respondent_id === $user->id
            && in_array($response->status, ['Not Started', 'In Progress', 'Returned'], true);
    }

    /**
     * SoD: a reviewer needs 'review campaigns' and can never review their
     * own response. No admin bypass (R2).
     */
    public function review(User $user, CsaResponse $response): bool
    {
        return $user->can('review campaigns')
            && $response->respondent_id !== $user->id
            && in_array($response->status, ['Submitted', 'Under Review'], true);
    }

    /**
     * Applying a CSA-proposed rating to a control is a Control Function
     * Head act — and never on a control they own (mirrors ExceptionPolicy).
     */
    public function approveRating(User $user, CsaResponse $response): bool
    {
        return $user->hasRole('Control Function Head')
            && $response->status === 'Accepted'
            && $response->proposed_design_rating !== null
            && $response->rating_approved_at === null
            && $response->respondent_id !== $user->id
            && $response->control?->owner_id !== $user->id;
    }
}
