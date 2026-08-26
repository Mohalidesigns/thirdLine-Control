<?php

namespace App\Policies;

use App\Models\ConsequenceAction;
use App\Models\Investigation;
use App\Models\User;

/**
 * Investigation authorization (CR-04 §D.3, §G.1).
 *
 * Every method starts from Investigation::grantsAccessTo(), which is the
 * in-memory twin of the model's visibility scope — the policy and the
 * query read one rule, so a list and a detail page can never disagree
 * about who may see what.
 *
 * A permission is necessary but rarely sufficient. Two overrides exist and
 * both are named permissions rather than role names, because a tenant that
 * separates these duties must be able to move them without a deploy (R1):
 *
 *   · 'view all investigations' — oversight sight of ordinary
 *     investigations. It does NOT open a confidential one.
 *   · 'view confidential-investigations' — the confidential override,
 *     seeded to the System Administrator and the Control Function Head.
 *
 * Sight is never reach. Acting on an investigation — editing, assigning,
 * completing, naming subjects — additionally requires being on its team.
 */
class InvestigationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view investigations');
    }

    public function view(User $user, Investigation $investigation): bool
    {
        return $user->can('view investigations') && $investigation->grantsAccessTo($user);
    }

    public function create(User $user): bool
    {
        return $user->can('create investigations');
    }

    /**
     * Editing is a member's act. Oversight sight confers none of it, and
     * a completed, closed or archived investigation is a record rather
     * than a workspace.
     */
    public function update(User $user, Investigation $investigation): bool
    {
        return $user->can('edit investigations')
            && $investigation->hasTeamMember($user)
            && $investigation->isEditable();
    }

    /** Deletion stays draft-only, as in the source module. */
    public function delete(User $user, Investigation $investigation): bool
    {
        return $user->can('delete investigations')
            && $investigation->status === 'draft'
            && ! $investigation->is_archived;
    }

    public function assign(User $user, Investigation $investigation): bool
    {
        return $user->can('assign investigations')
            && $investigation->hasTeamMember($user)
            && $investigation->isEditable();
    }

    public function complete(User $user, Investigation $investigation): bool
    {
        return $user->can('complete investigations')
            && $investigation->hasTeamMember($user)
            && in_array($investigation->status, ['under_investigation', 'pending_review'], true)
            && ! $investigation->is_archived;
    }

    public function archive(User $user, Investigation $investigation): bool
    {
        return $user->can('archive investigations')
            && in_array($investigation->status, ['completed', 'closed'], true);
    }

    public function unarchive(User $user, Investigation $investigation): bool
    {
        return $user->can('archive investigations') && $investigation->is_archived;
    }

    /**
     * Confidentiality is raiseable by a member, lowerable by nobody once
     * it was inherited from a Speak Up report (§D.3-1).
     */
    public function changeConfidentiality(User $user, Investigation $investigation): bool
    {
        return $user->can('edit investigations')
            && $investigation->hasTeamMember($user)
            && ! $investigation->confidentiality_locked;
    }

    public function recommendConsequence(User $user, Investigation $investigation): bool
    {
        return $user->can('manage investigation-consequences')
            && $investigation->hasTeamMember($user)
            && ! $investigation->is_archived;
    }

    /**
     * §D.4-2. The service refuses a self-approval whatever roles the actor
     * holds; the policy hides the button so nobody reaches for it.
     */
    public function approveConsequence(User $user, ConsequenceAction $action): bool
    {
        return $user->can('manage investigation-consequences')
            && $action->recommended_by !== $user->id
            && $action->investigation?->grantsAccessTo($user) === true;
    }

    public function generateReport(User $user, Investigation $investigation): bool
    {
        return $user->can('complete investigations')
            && $investigation->hasTeamMember($user)
            && in_array($investigation->status, ['completed', 'closed'], true);
    }

    public function viewDashboard(User $user): bool
    {
        return $user->can('view investigation-dashboard');
    }
}
