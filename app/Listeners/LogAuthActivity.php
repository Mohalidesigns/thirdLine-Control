<?php

namespace App\Listeners;

use App\Services\AuditTrailService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;

/**
 * Activity Log (CR3) auth capture. Picked up by Laravel's event
 * auto-discovery from the typed handle*() signatures — do NOT also
 * register it manually, or every auth event is logged twice.
 *
 * A failed login records the attempted identifier and the request IP.
 * The submitted password is never touched, in any field.
 */
class LogAuthActivity
{
    public function __construct(private AuditTrailService $audit) {}

    public function handleLogin(Login $event): void
    {
        $this->audit->recordEvent('login', 'auth', 'User signed in', null, [
            'tenant_id' => $event->user->tenant_id ?? null,
            'user_id' => $event->user->getAuthIdentifier(),
            'actor_name' => $event->user->name ?? null,
            'actor_email' => $event->user->email ?? null,
        ]);
    }

    public function handleLogout(Logout $event): void
    {
        if (! $event->user) {
            return;
        }

        $this->audit->recordEvent('logout', 'auth', 'User signed out', null, [
            'tenant_id' => $event->user->tenant_id ?? null,
            'user_id' => $event->user->getAuthIdentifier(),
            'actor_name' => $event->user->name ?? null,
            'actor_email' => $event->user->email ?? null,
        ]);
    }

    public function handleFailed(Failed $event): void
    {
        $email = is_array($event->credentials) ? ($event->credentials['email'] ?? null) : null;

        $this->audit->recordEvent(
            'login_failed',
            'auth',
            'Login attempt failed'.($email ? " for {$email}" : ''),
            ['identifier' => $email],
            [
                'tenant_id' => $event->user->tenant_id ?? null,
                'user_id' => $event->user?->getAuthIdentifier(),
                'actor_email' => $email,
            ],
        );
    }

    public function handleLockout(Lockout $event): void
    {
        $email = $event->request->input('email');

        $this->audit->recordEvent(
            'login_locked_out',
            'auth',
            'Login throttled after repeated failures'.($email ? " for {$email}" : ''),
            ['identifier' => $email],
            ['actor_email' => $email],
        );
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $this->audit->recordEvent('password_reset', 'auth', 'Password reset completed', null, [
            'tenant_id' => $event->user->tenant_id ?? null,
            'user_id' => $event->user->getAuthIdentifier(),
            'actor_name' => $event->user->name ?? null,
            'actor_email' => $event->user->email ?? null,
        ]);
    }
}
