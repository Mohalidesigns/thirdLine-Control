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
        // Phase 8 — framework & regulatory obligation engine
        'frameworks' => 'Framework explorer, requirement trees, control mapping and coverage heatmaps',
        'obligations' => 'Regulatory obligation register and compliance calendar',
        'regulatory-changes' => 'Regulator publication feed and impact-assessment workflow',
        'content-packs' => 'Versioned regulatory content pack installation',
        // Phase 9 — control library v2, CSA & surveys
        'control-distribution' => 'Group→entity control distribution with implementation tracking',
        'csa' => 'Control self-assessment campaigns with questionnaires and reviewer workflow',
        'surveys' => 'General survey engine with anonymous response support',
        'attestations' => 'Policy and code-of-conduct attestation campaigns',
        'documents' => 'Document management with folders, versioning and maker-checker approval',
        'improvements' => 'Improvement action database across all sources',
        'bulk-import' => 'Bulk Excel import with dry-run validation',
        // Phase 10 — risk management v2
        'risk-assessments' => 'Inherent/residual/target risk assessment with multi-dimensional impact and Monte Carlo',
        'risk-heatmap' => 'Configurable N×N risk heatmaps with movement view and cell drill-through',
        'risk-appetite' => 'Board-approved appetite statements, tolerance bands and breach escalation',
        'risk-treatments' => 'Treatment plans with milestones, cost/benefit and independent verification',
        'metrics' => 'KRI/KPI/KCI engine with thresholds, breach detection and trend charts',
        'linkage' => 'Universal linkage graph across risks, controls, metrics and obligations',
        // Phase 11 — policy, incident, complaints & case management
        'policies' => 'Policy lifecycle with section structure, attestation campaigns, waivers and gap analysis',
        'incidents' => 'Incident management with Basel loss capture, control-failure linkage and regulatory notification',
        'complaints' => 'CBN consumer-protection complaint handling with SLA clocks, penalty exposure and CPD returns',
        'cases' => 'Investigation and case management with allowlist confidentiality and privileged notes',
        'whistleblowing' => 'Public whistleblowing intake with genuine anonymity and a one-way reporter token',
        // Phase 12 — continuous controls monitoring & connectors
        'data-sources' => 'Connector framework and data source registration with encrypted credentials and a circuit breaker',
        'continuous-monitoring' => 'Rule engine, scheduled runs, findings review and the CCM dashboard',
        'sod-analysis' => 'Segregation-of-duties conflict matrix and violation management over entitlement extracts',
        // Phase 13 — dashboards, analytics & reporting v2
        'dashboard-builder' => 'Configurable dashboards with a widget palette, drag-and-drop layout and org-tree rollup',
        'report-designer' => 'Report definitions, section designer, PDF/Word/Excel/PowerPoint output and scheduled distribution',
        'submission-packs' => 'Regulator-shaped submission pack generation with completeness scoring and maker-checker filing',
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
