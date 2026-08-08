<?php

namespace App\Policies;

use App\Models\AiConfiguration;
use App\Models\User;

/**
 * The AI governance surface (§C.8).
 *
 * Enabling a capability, changing its model, publishing a prompt version
 * and raising a budget are all 'manage ai'. That permission is separate from
 * 'manage settings' on purpose: an organisation demonstrating AI governance
 * to a regulator — draft King V's technology chapter, Ghana's 2026 BoG
 * AI/ML directive — needs to show that turning the models on is its own
 * named authority held by named people, not a line item inside general
 * administration.
 */
class AiConfigurationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage ai') || $user->can('view ai-log');
    }

    public function view(User $user, AiConfiguration $configuration): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, AiConfiguration $configuration): bool
    {
        return $user->can('manage ai');
    }

    public function create(User $user): bool
    {
        return $user->can('manage ai');
    }

    /**
     * Publishing a prompt version changes what every future call to that
     * capability says and does. It is the closest thing this module has to
     * deploying code, and it is gated accordingly.
     */
    public function managePrompts(User $user): bool
    {
        return $user->can('manage ai');
    }

    public function manageBudget(User $user): bool
    {
        return $user->can('manage ai');
    }
}
