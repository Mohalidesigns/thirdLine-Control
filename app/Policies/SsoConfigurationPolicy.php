<?php

namespace App\Policies;

use App\Models\SsoConfiguration;
use App\Models\User;

/**
 * Maker-checker on authentication configuration (Phase 7 rule).
 * Deliberately NO before() hook: a System Administrator gets no bypass
 * of the maker≠checker rule (R2).
 */
class SsoConfigurationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage sso');
    }

    public function create(User $user): bool
    {
        return $user->can('manage sso');
    }

    public function update(User $user, SsoConfiguration $configuration): bool
    {
        return $user->can('manage sso');
    }

    /**
     * THE gate: the approver must hold the permission AND must not be the
     * person whose change is awaiting approval.
     */
    public function approve(User $user, SsoConfiguration $configuration): bool
    {
        if (! $user->can('manage sso')) {
            return false;
        }

        $makerId = $configuration->hasPendingChanges()
            ? $configuration->pending_changes_by
            : $configuration->created_by;

        return $user->id !== $makerId;
    }

    public function test(User $user, SsoConfiguration $configuration): bool
    {
        return $user->can('manage sso');
    }
}
