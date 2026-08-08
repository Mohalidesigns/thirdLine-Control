<?php

namespace App\Policies;

use App\Models\RiskAppetite;
use App\Models\User;

class RiskAppetitePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view appetite');
    }

    public function view(User $user, RiskAppetite $appetite): bool
    {
        return $user->can('view appetite');
    }

    public function create(User $user): bool
    {
        return $user->can('manage appetite');
    }

    public function update(User $user, RiskAppetite $appetite): bool
    {
        return $user->can('manage appetite')
            && in_array($appetite->status, ['Draft', 'Pending Approval'], true);
    }

    /**
     * Board-level approval, and never by the author (R2) — the same rule is
     * re-checked in AppetiteService, so a direct service call cannot slip
     * past it. There is no administrator bypass.
     */
    public function approve(User $user, RiskAppetite $appetite): bool
    {
        return $user->can('approve appetite')
            && $appetite->status === 'Pending Approval'
            && $appetite->created_by !== $user->id;
    }

    public function supersede(User $user, RiskAppetite $appetite): bool
    {
        return $user->can('manage appetite') && $appetite->status === 'Active';
    }
}
