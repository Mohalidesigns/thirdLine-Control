<?php

namespace App\Policies;

use App\Models\MonitoringFinding;
use App\Models\User;

class MonitoringFindingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view monitoring');
    }

    public function view(User $user, MonitoringFinding $finding): bool
    {
        return $user->can('view monitoring')
            || $finding->run?->rule?->control?->owner_id === $user->id;
    }

    /**
     * Reviewing a finding decides whether a control failed, so it sits
     * with the second line. The control owner may see their findings but
     * does not get to dismiss one as a false positive.
     */
    public function review(User $user, MonitoringFinding $finding): bool
    {
        return $user->can('review monitoring-findings')
            && ! in_array($finding->status, ['Confirmed', 'Remediated'], true);
    }
}
