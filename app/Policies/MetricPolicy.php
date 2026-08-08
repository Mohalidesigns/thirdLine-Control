<?php

namespace App\Policies;

use App\Models\Metric;
use App\Models\User;

class MetricPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view metrics');
    }

    public function view(User $user, Metric $metric): bool
    {
        return $user->can('view metrics') || $metric->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('manage metrics');
    }

    public function update(User $user, Metric $metric): bool
    {
        return $user->can('manage metrics');
    }

    /** Owners capture their own readings; the control function captures any. */
    public function capture(User $user, Metric $metric): bool
    {
        return $metric->is_active
            && ($metric->owner_id === $user->id || $user->can('capture metrics'));
    }

    public function acknowledgeBreach(User $user, Metric $metric): bool
    {
        return $user->can('acknowledge breaches') || $metric->owner_id === $user->id;
    }
}
