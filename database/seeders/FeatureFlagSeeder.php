<?php

namespace Database\Seeders;

use App\Models\FeatureFlag;
use Illuminate\Database\Seeder;

class FeatureFlagSeeder extends Seeder
{
    /**
     * Every v2 module registers behind a flag so partial deployments are
     * safe (Phase 7.9). Global defaults; tenants may override per key.
     */
    private const FLAGS = [
        'sso' => 'Single sign-on (SAML 2.0 / OIDC) login and admin configuration',
        'mfa' => 'Multi-factor authentication enrolment and role enforcement',
        'branding' => 'Tenant white-label branding of shell, login, reports and email',
        'notification-preferences' => 'Per-user notification preferences and multi-channel dispatch',
        'audit-log-ui' => 'Read-only audit log explorer and record activity tabs',
        'global-search' => 'Cmd/Ctrl-K global search across all modules',
        'saved-views' => 'Saved filter views on list pages',
        'pwa' => 'Installable PWA shell with offline fallback',
        'low-bandwidth-mode' => 'Reduced-data mode for slow connections',
    ];

    public function run(): void
    {
        foreach (self::FLAGS as $key => $description) {
            FeatureFlag::updateOrCreate(
                ['tenant_id' => null, 'key' => $key],
                ['is_enabled' => true, 'rollout_percentage' => 100, 'description' => $description],
            );
        }
    }
}
