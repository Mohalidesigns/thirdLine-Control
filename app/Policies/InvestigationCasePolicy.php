<?php

namespace App\Policies;

use App\Models\InvestigationCase;
use App\Models\User;

/**
 * Case authorization (11.4).
 *
 * Every method starts from `grantsAccessTo()`: the same allowlist the
 * model's global scope applies, so the policy and the query can never drift
 * apart. A permission is necessary but never sufficient; membership is
 * always required — with one named exception. 'view all cases' (System
 * Administrator only) is the read-only oversight override: it opens `view`
 * so no report can be invisible to the platform owner, but every acting
 * method — update, investigate, conclude, close, notes, access management,
 * privileged notes — still requires membership. CaseConfidentialityTest
 * asserts both halves: the oversight sight and its read-only ceiling.
 */
class InvestigationCasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view cases');
    }

    public function view(User $user, InvestigationCase $case): bool
    {
        return $case->grantsAccessTo($user) || $user->can('view all cases');
    }

    public function create(User $user): bool
    {
        return $user->can('create cases');
    }

    public function update(User $user, InvestigationCase $case): bool
    {
        return $case->grantsAccessTo($user)
            && $user->can('investigate cases')
            && $case->status !== 'Closed';
    }

    public function assess(User $user, InvestigationCase $case): bool
    {
        return $this->update($user, $case) && in_array($case->status, ['Received', 'Assessed'], true);
    }

    public function investigate(User $user, InvestigationCase $case): bool
    {
        return $case->grantsAccessTo($user)
            && ($case->lead_investigator_id === $user->id || $user->can('investigate cases'))
            && $case->status !== 'Closed';
    }

    /** The reporter of a case never decides its outcome (R2). */
    public function conclude(User $user, InvestigationCase $case): bool
    {
        return $case->grantsAccessTo($user)
            && $user->can('investigate cases')
            && $case->status === 'Under Investigation'
            && $case->reporter_id !== $user->id;
    }

    public function close(User $user, InvestigationCase $case): bool
    {
        return $case->grantsAccessTo($user)
            && $user->can('close cases')
            && in_array($case->status, ['Substantiated', 'Unsubstantiated', 'Referred', 'Assessed', 'Received'], true);
    }

    public function manageAccess(User $user, InvestigationCase $case): bool
    {
        return $case->grantsAccessTo($user);
    }

    /** Privileged notes stay with the lead investigator and legal counsel. */
    public function viewPrivileged(User $user, InvestigationCase $case): bool
    {
        return $case->grantsAccessTo($user)
            && ($case->lead_investigator_id === $user->id || $user->can('view privileged-notes'));
    }
}
