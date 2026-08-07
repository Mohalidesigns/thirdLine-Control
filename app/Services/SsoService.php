<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\SsoConfiguration;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\BreakGlassLoginNotification;
use App\Notifications\SsoConfigChangedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * SSO configuration lifecycle (maker-checker, Phase 7 rule: authentication
 * configuration only takes effect after a second System Administrator
 * approves) and JIT provisioning of IdP-asserted users.
 */
class SsoService
{
    public function __construct(private NotificationDispatcher $dispatcher) {}

    // ── Configuration lifecycle ──────────────────────────────────────

    public function create(User $maker, array $data): SsoConfiguration
    {
        $config = SsoConfiguration::create([
            ...collect($data)->only(SsoConfiguration::CONFIGURABLE_FIELDS)->all(),
            'tenant_id' => $maker->tenant_id,
            'created_by' => $maker->id,
        ]);

        $config->auditAction('sso_config_created', null, ['display_name' => $config->display_name]);
        $this->notifyAdmins($maker, "SSO configuration '{$config->display_name}' created — awaiting approval by a second System Administrator.");

        return $config;
    }

    /**
     * Edits to an existing configuration are staged, never applied
     * directly — the live config keeps serving logins until approval.
     */
    public function proposeUpdate(User $maker, SsoConfiguration $config, array $data): SsoConfiguration
    {
        $changes = collect($data)
            ->only(SsoConfiguration::CONFIGURABLE_FIELDS)
            ->filter(fn ($value, $key) => $config->{$key} !== $value)
            ->all();

        if ($changes === []) {
            throw ValidationException::withMessages(['display_name' => 'No changes to propose.']);
        }

        $config->forceFill([
            'pending_changes' => $changes,
            'pending_changes_by' => $maker->id,
            'pending_changes_at' => now(),
        ])->save();

        $config->auditAction('sso_config_change_proposed', null, ['fields' => array_keys($changes)]);
        $this->notifyAdmins($maker, "Changes to SSO configuration '{$config->display_name}' proposed — awaiting approval.");

        return $config;
    }

    /**
     * THE approval gate. The checker must be a System Administrator other
     * than the maker — with no bypass for any role (R2).
     */
    public function approve(User $checker, SsoConfiguration $config): SsoConfiguration
    {
        $makerId = $config->hasPendingChanges() ? $config->pending_changes_by : $config->created_by;

        if ($checker->id === $makerId) {
            throw ValidationException::withMessages([
                'approval' => 'Segregation of duties: the administrator who made an SSO change cannot approve it.',
            ]);
        }

        $before = $config->only(SsoConfiguration::CONFIGURABLE_FIELDS);

        if ($config->hasPendingChanges()) {
            $config->forceFill($config->pending_changes);
            $config->forceFill([
                'pending_changes' => null,
                'pending_changes_by' => null,
                'pending_changes_at' => null,
            ]);
        }

        $config->forceFill([
            'approved_by' => $checker->id,
            'approved_at' => now(),
        ])->save();

        $config->auditAction('sso_config_approved', $before, $config->only(SsoConfiguration::CONFIGURABLE_FIELDS));
        $this->notifyAdmins($checker, "SSO configuration '{$config->display_name}' approved and in effect.");

        return $config;
    }

    public function reject(User $checker, SsoConfiguration $config): void
    {
        if ($config->hasPendingChanges()) {
            $config->auditAction('sso_config_change_rejected', $config->pending_changes, null);
            $config->forceFill([
                'pending_changes' => null,
                'pending_changes_by' => null,
                'pending_changes_at' => null,
            ])->save();

            return;
        }

        $config->auditAction('sso_config_rejected');
        $config->delete();
    }

    // ── Login-side resolution ────────────────────────────────────────

    /** Active configurations offered on the login screen. */
    public function activeConfigurations(): Collection
    {
        return SsoConfiguration::withoutGlobalScope('tenant')
            ->active()
            ->get(['id', 'display_name', 'protocol']);
    }

    public function tenantEnforcesSso(?int $tenantId): bool
    {
        return SsoConfiguration::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->active()
            ->exists();
    }

    // ── Break-glass (Phase 7.1) ──────────────────────────────────────

    /**
     * When SSO is active, password login is reserved for break-glass
     * accounts, capped per tenant per day, and always audited.
     */
    public function authoriseLocalLogin(User $user): void
    {
        if (! $this->tenantEnforcesSso($user->tenant_id)) {
            return;
        }

        if (! $user->is_break_glass) {
            throw ValidationException::withMessages([
                'email' => 'Password sign-in is disabled — use your organisation single sign-on. Contact an administrator if you need break-glass access.',
            ]);
        }

        $cap = $user->tenant?->break_glass_daily_cap ?? 3;
        $usedToday = AuditTrail::where('tenant_id', $user->tenant_id)
            ->where('action', 'break_glass_login')
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        if ($usedToday >= $cap) {
            throw ValidationException::withMessages([
                'email' => 'The daily break-glass login limit for your organisation has been reached.',
            ]);
        }

        $user->auditAction('break_glass_login', null, ['used_today' => $usedToday + 1, 'cap' => $cap]);

        $admins = User::withoutGlobalScope('tenant')
            ->where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->role('System Administrator')
            ->get();

        $this->dispatcher->send($admins, 'security.break-glass-login',
            new BreakGlassLoginNotification($user->name, $user->email));
    }

    // ── JIT provisioning ─────────────────────────────────────────────

    /**
     * Find or provision the asserted user. attribute_map maps claim names
     * and IdP group values to roles, e.g.:
     * { "email": "email", "name": "name", "groups": "groups",
     *   "role_map": { "GRC-Admins": "Control Function Head" } }
     */
    public function resolveUser(SsoConfiguration $config, array $claims): User
    {
        $map = $config->attribute_map ?? [];
        $email = strtolower(trim($claims[$map['email'] ?? 'email'] ?? ''));

        if ($email === '') {
            throw ValidationException::withMessages(['email' => 'The identity provider did not assert an email address.']);
        }

        $this->assertAllowedDomain($config, $email);

        $user = User::withoutGlobalScope('tenant')
            ->where('tenant_id', $config->tenant_id)
            ->where('email', $email)
            ->first();

        if ($user) {
            if (! $user->is_active) {
                throw ValidationException::withMessages(['email' => 'This account is deactivated.']);
            }

            return $user;
        }

        if (! $config->jit_provisioning) {
            throw ValidationException::withMessages(['email' => 'No account exists for this identity and just-in-time provisioning is disabled.']);
        }

        $user = User::create([
            'tenant_id' => $config->tenant_id,
            'name' => $claims[$map['name'] ?? 'name'] ?? Str::before($email, '@'),
            'email' => $email,
            'password' => Str::random(64), // never used — SSO account
            'is_active' => true,
        ]);

        $user->assignRole($this->roleForClaims($config, $claims));
        $user->auditAction('sso_jit_provisioned', null, ['config_id' => $config->id, 'email' => $email]);

        return $user;
    }

    public function roleForClaims(SsoConfiguration $config, array $claims): Role
    {
        $map = $config->attribute_map ?? [];
        $groupsClaim = $claims[$map['groups'] ?? 'groups'] ?? [];
        $groups = is_array($groupsClaim) ? $groupsClaim : [$groupsClaim];

        foreach (($map['role_map'] ?? []) as $idpGroup => $roleName) {
            if (in_array($idpGroup, $groups, true)) {
                $role = Role::where('name', $roleName)->first();
                if ($role) {
                    return $role;
                }
            }
        }

        $default = $config->default_role_id ? Role::find($config->default_role_id) : null;

        return $default ?? Role::where('name', 'Executive Viewer')->firstOrFail();
    }

    private function assertAllowedDomain(SsoConfiguration $config, string $email): void
    {
        $domains = $config->allowed_email_domains ?? [];

        if ($domains !== [] && ! in_array(Str::after($email, '@'), array_map('strtolower', $domains), true)) {
            throw ValidationException::withMessages(['email' => 'This email domain is not permitted for single sign-on.']);
        }
    }

    private function notifyAdmins(User $actor, string $message): void
    {
        $admins = User::withoutGlobalScope('tenant')
            ->where('tenant_id', $actor->tenant_id)
            ->where('is_active', true)
            ->role('System Administrator')
            ->where('id', '!=', $actor->id)
            ->get();

        $this->dispatcher->send($admins, 'security.sso-config-changed',
            new SsoConfigChangedNotification($message, $actor->name));
    }
}
