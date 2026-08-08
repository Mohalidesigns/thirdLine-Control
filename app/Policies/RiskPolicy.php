<?php

namespace App\Policies;

use App\Models\Risk;
use App\Models\User;

class RiskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view risks');
    }

    public function view(User $user, Risk $risk): bool
    {
        return $user->can('view risks')
            || $risk->risk_owner_id === $user->id
            || $risk->second_line_reviewer_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('create risks');
    }

    public function update(User $user, Risk $risk): bool
    {
        return $user->can('edit risks') || $risk->risk_owner_id === $user->id;
    }

    public function map(User $user, Risk $risk): bool
    {
        return $user->can('map risks');
    }

    /** Recording an assessment — the maker side. */
    public function assess(User $user, Risk $risk): bool
    {
        return $user->can('assess risks') || $risk->risk_owner_id === $user->id;
    }

    /**
     * Publishing a high-scoring assessment — the checker side. The
     * assessment-level SoD check (assessor ≠ approver) lives in
     * RiskAssessmentService and applies with no admin bypass (R2).
     */
    public function review(User $user, Risk $risk): bool
    {
        return $user->can('review risk-assessments');
    }
}
