<?php

namespace App\Policies;

use App\Models\ControlException;
use App\Models\User;
use App\Services\ExceptionService;

class ExceptionPolicy
{
    public function __construct(private ExceptionService $exceptionService) {}

    /**
     * Deliberately no System Administrator bypass on close/remediate: the
     * segregation-of-duties rules in FR-12.3 bind every role.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ControlException $exception): bool
    {
        if ($user->hasAnyRole(['System Administrator', 'Control Function Head', 'Control Officer', 'Executive Viewer', 'Line Manager'])) {
            return true;
        }

        return $exception->owner_id === $user->id
            || $exception->responsible_party_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['Control Officer', 'Control Function Head']);
    }

    public function update(User $user, ControlException $exception): bool
    {
        return $user->hasAnyRole(['Control Officer', 'Control Function Head'])
            && $exception->isOpen();
    }

    /**
     * Owners and responsible parties progress remediation — up to
     * Remediated, never further (FR-5.4).
     */
    public function remediate(User $user, ControlException $exception): bool
    {
        if (! $exception->isOpen() || $exception->status === 'Remediated') {
            return false;
        }

        return $exception->owner_id === $user->id
            || $exception->responsible_party_id === $user->id
            || $user->hasAnyRole(['Control Officer', 'Control Function Head']);
    }

    /**
     * THE closure rule (FR-5.4): control function only; never the tester
     * whose test raised it; never the owner of the failed control.
     */
    public function close(User $user, ControlException $exception): bool
    {
        if ($exception->status !== 'Remediated') {
            return false;
        }

        if (! $user->hasRole('Control Function Head')) {
            return false;
        }

        if ($this->exceptionService->userPerformedSourceTest($exception, $user)) {
            return false;
        }

        if ($exception->control && $exception->control->owner_id === $user->id) {
            return false;
        }

        return $exception->owner_id !== $user->id;
    }

    public function requestExtension(User $user, ControlException $exception): bool
    {
        return $exception->isOpen()
            && ($exception->owner_id === $user->id || $exception->responsible_party_id === $user->id);
    }

    public function decideExtension(User $user, ControlException $exception): bool
    {
        return $user->hasRole('Control Function Head');
    }

    public function acceptRisk(User $user, ControlException $exception): bool
    {
        return $user->hasAnyRole(['Control Function Head', 'System Administrator'])
            && $exception->isOpen()
            && $exception->control?->owner_id !== $user->id;
    }

    public function comment(User $user, ControlException $exception): bool
    {
        return $this->view($user, $exception);
    }
}
