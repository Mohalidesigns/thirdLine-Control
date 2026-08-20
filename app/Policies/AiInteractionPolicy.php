<?php

namespace App\Policies;

use App\Models\AiInteraction;
use App\Models\User;
use App\Services\Ai\CapabilityRegistry;

/**
 * Who may read and decide on an AI draft.
 *
 * Note the absence of a before() bypass. Every other policy in this
 * codebase omits one too (R2) — but it matters particularly here, because
 * "the administrator approved it" is exactly the audit finding an AI
 * governance review is written to catch.
 */
class AiInteractionPolicy
{
    /** The log explorer, not an individual draft. */
    public function viewAny(User $user): bool
    {
        return $user->can('view ai-log');
    }

    /**
     * The person who asked can always see what came back. Anyone else needs
     * the log permission: an interaction carries the redacted question and
     * the retrieved record ids, which together say a good deal about what
     * someone was investigating.
     */
    public function view(User $user, AiInteraction $interaction): bool
    {
        return $interaction->user_id === $user->id || $user->can('view ai-log');
    }

    /**
     * Accepting is the act R4 exists to require, so it is gated on the same
     * domain permission the manual equivalent needs — accepting a drafted
     * control needs 'create controls', because that is what accepting it
     * does.
     *
     * The requester may be the approver. Part C allows that deliberately:
     * the point is that a person made an explicit, recorded decision, not
     * that a second person did. Where a tenant wants two people, it sets
     * requires_approval_role_id and AiService enforces it on top of this.
     */
    public function decide(User $user, AiInteraction $interaction): bool
    {
        if (! $interaction->isAwaitingDecision()) {
            return false;
        }

        $permission = CapabilityRegistry::permission($interaction->capability_key);

        // A capability with no resolvable permission is an unknown key.
        // Refuse — never read a missing permission as "none required".
        if (! $permission || ! $user->can($permission)) {
            return false;
        }

        // Someone else's draft is not yours to accept. They may still be
        // editing it, and a race between two reviewers on one suggestion is
        // a governance mess with no upside.
        return $interaction->user_id === $user->id;
    }

    /** Feedback is open to whoever can see the interaction. */
    public function feedback(User $user, AiInteraction $interaction): bool
    {
        return $this->view($user, $interaction);
    }

    public function export(User $user): bool
    {
        return $user->can('export ai-log');
    }
}
