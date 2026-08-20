<?php

namespace App\Policies;

use App\Models\ReportDefinition;
use App\Models\User;

/**
 * A report definition decides what a board or a regulator is shown, so it
 * carries its own maker-checker: the author never approves their own layout,
 * and a shipped definition is read-only.
 */
class ReportDefinitionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('export reports');
    }

    public function view(User $user, ReportDefinition $definition): bool
    {
        return $user->can('export reports');
    }

    public function create(User $user): bool
    {
        return $user->can('design reports');
    }

    public function update(User $user, ReportDefinition $definition): bool
    {
        return $user->can('design reports') && ! $definition->is_system;
    }

    public function delete(User $user, ReportDefinition $definition): bool
    {
        return $user->can('design reports') && ! $definition->is_system;
    }

    public function approve(User $user, ReportDefinition $definition): bool
    {
        return $user->can('approve report-definitions') && $definition->owner_id !== $user->id;
    }

    /** Running a report reads live data — export, not design, is the gate. */
    public function run(User $user, ReportDefinition $definition): bool
    {
        return $user->can('export reports') && $definition->is_active;
    }
}
