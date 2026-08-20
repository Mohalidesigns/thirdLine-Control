<?php

namespace App\Policies;

use App\Models\MonitoringRule;
use App\Models\User;

/**
 * A monitoring rule decides whether a control is failing, so activating
 * one is an approval gate in its own right: the author never approves
 * their own rule and neither does its owner, with no administrator
 * bypass anywhere (R2).
 */
class MonitoringRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view monitoring');
    }

    public function view(User $user, MonitoringRule $rule): bool
    {
        return $user->can('view monitoring')
            || $rule->owner_id === $user->id
            || $rule->control?->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('manage monitoring-rules');
    }

    /** A retired rule is history and stays that way. */
    public function update(User $user, MonitoringRule $rule): bool
    {
        return $user->can('manage monitoring-rules') && $rule->status !== 'Retired';
    }

    public function submit(User $user, MonitoringRule $rule): bool
    {
        return $user->can('manage monitoring-rules')
            && in_array($rule->status, ['Draft', 'Paused'], true);
    }

    /**
     * THE gate. Approving a rule the user wrote, or owns, is refused here
     * and again in MonitoringService — the policy is the UI's answer and
     * the service is the one that cannot be routed around.
     */
    public function approve(User $user, MonitoringRule $rule): bool
    {
        return $user->can('approve monitoring-rules')
            && $rule->status === 'Pending Approval'
            && $rule->created_by !== $user->id
            && $rule->owner_id !== $user->id;
    }

    public function pause(User $user, MonitoringRule $rule): bool
    {
        return $user->can('manage monitoring-rules');
    }

    /** Running a rule by hand pulls from a live system; it is not a view action. */
    public function run(User $user, MonitoringRule $rule): bool
    {
        return $user->can('manage monitoring-rules') && $rule->status === 'Active';
    }

    public function retire(User $user, MonitoringRule $rule): bool
    {
        return $user->can('approve monitoring-rules');
    }
}
