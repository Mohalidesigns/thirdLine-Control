<?php

namespace Database\Seeders;

use App\Models\EscalationMatrix;
use App\Models\RiskAssessmentScale;
use App\Models\RiskCategory;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * The risk taxonomy and assessment scales (Phase 10). Categories ship
 * globally (tenant_id NULL); scales are seeded per tenant because a tenant
 * may run a 4×4 or 6×6 matrix, re-label its levels or re-price its money
 * bands without a code change (R1).
 *
 * Money bands are integer minor units — kobo — plus an ISO-4217 code (R7).
 * Band colours come from a validated status palette and are always shown
 * with their label in the UI, so nothing is encoded in colour alone.
 */
class RiskTaxonomySeeder extends Seeder
{
    /** Top-level taxonomy: code, name, description. */
    private const CATEGORIES = [
        ['CRD', 'Credit', 'Loss from a counterparty failing to meet its obligations'],
        ['MKT', 'Market', 'Loss from movements in rates, prices, spreads or FX'],
        ['LIQ', 'Liquidity', 'Inability to meet obligations as they fall due without unacceptable cost'],
        ['OPS', 'Operational', 'Loss from failed internal processes, people, systems or external events'],
        ['CMP', 'Compliance', 'Regulatory censure, fines or restrictions from non-compliance'],
        ['STR', 'Strategic', 'Loss of value from poor strategy, execution or market response'],
        ['REP', 'Reputational', 'Erosion of stakeholder trust and franchise value'],
        ['CYB', 'Cyber & Information Security', 'Compromise of confidentiality, integrity or availability of information'],
        ['CLM', 'Climate & Environmental', 'Physical and transition risk from climate change'],
        ['MDL', 'Model', 'Loss from model error, misuse or inappropriate application'],
        ['TPR', 'Third Party', 'Failure or misconduct of outsourced providers and vendors'],
        ['CDT', 'Conduct', 'Poor customer outcomes and market integrity failures'],
    ];

    /** Second-level branches: parent code, code, name. */
    private const SUBCATEGORIES = [
        ['OPS', 'OPS-FRD', 'Fraud'],
        ['OPS', 'OPS-PRC', 'Process Execution & Delivery'],
        ['OPS', 'OPS-TEC', 'Technology & Systems'],
        ['OPS', 'OPS-PPL', 'People & Employment Practices'],
        ['OPS', 'OPS-BCM', 'Business Continuity'],
        ['CMP', 'CMP-AML', 'AML / CFT'],
        ['CMP', 'CMP-DPR', 'Data Protection & Privacy'],
        ['CMP', 'CMP-PRU', 'Prudential Reporting'],
        ['CDT', 'CDT-CPR', 'Consumer Protection'],
        ['CYB', 'CYB-ACC', 'Access & Identity'],
    ];

    public function run(): void
    {
        $this->seedCategories();

        Tenant::all()->each(function (Tenant $tenant) {
            $this->seedScales($tenant);
            $this->seedEscalationRules($tenant);
        });
    }

    private function seedCategories(): void
    {
        $byCode = [];

        foreach (self::CATEGORIES as $index => [$code, $name, $description]) {
            $byCode[$code] = RiskCategory::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => null, 'code' => $code],
                ['name' => $name, 'description' => $description, 'sort_order' => ($index + 1) * 10],
            );
        }

        foreach (self::SUBCATEGORIES as $index => [$parentCode, $code, $name]) {
            RiskCategory::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => null, 'code' => $code],
                [
                    'parent_id' => $byCode[$parentCode]->id,
                    'name' => $name,
                    'sort_order' => $byCode[$parentCode]->sort_order + $index + 1,
                ],
            );
        }
    }

    private function seedScales(Tenant $tenant): void
    {
        $currency = $tenant->currency ?? 'NGN';

        // ── Likelihood: 5 levels, annual probability of occurrence ────
        $likelihood = [
            [1, 'Rare', 'Less than 10% chance in the next 12 months'],
            [2, 'Unlikely', '10–30% chance in the next 12 months'],
            [3, 'Possible', '30–60% chance in the next 12 months'],
            [4, 'Likely', '60–85% chance in the next 12 months'],
            [5, 'Almost Certain', 'Over 85% chance in the next 12 months'],
        ];

        foreach ($likelihood as [$level, $label, $description]) {
            $this->scale($tenant, 'likelihood', null, $level, $label, $description);
        }

        // ── Impact: 5 levels, with the money band that defines each ───
        // Bands in kobo: ₦5m, ₦25m, ₦100m, ₦500m.
        $impact = [
            [1, 'Insignificant', 'Absorbed in normal operations; no customer or regulatory consequence', null, 500_000_000],
            [2, 'Minor', 'Contained within the business unit; no external reporting', 500_000_000, 2_500_000_000],
            [3, 'Moderate', 'Management attention required; possible regulatory query', 2_500_000_000, 10_000_000_000],
            [4, 'Major', 'Executive attention; likely regulatory sanction and press interest', 10_000_000_000, 50_000_000_000],
            [5, 'Severe', 'Threatens the franchise, licence or capital position', 50_000_000_000, null],
        ];

        foreach ($impact as [$level, $label, $description, $lower, $upper]) {
            $this->scale($tenant, 'impact', null, $level, $label, $description, $lower, $upper, $currency);
        }

        // ── Per-dimension impact scales ───────────────────────────────
        $dimensions = [
            'financial' => ['Negligible loss', 'Minor loss', 'Material loss', 'Major loss', 'Capital-threatening loss'],
            'regulatory' => ['No breach', 'Technical breach, self-reported', 'Formal query or warning', 'Fine or directive', 'Licence condition or suspension'],
            'reputational' => ['No coverage', 'Internal awareness only', 'Local press or social media', 'National press', 'Sustained national and international coverage'],
            'operational' => ['No disruption', 'Under 4 hours disruption', 'Up to 1 day disruption', 'Up to 1 week disruption', 'Over 1 week disruption'],
            'customer' => ['No customer affected', 'Isolated customers, no detriment', 'Group of customers, minor detriment', 'Widespread detriment, redress required', 'Systemic detriment across the customer base'],
            'people' => ['No impact', 'Isolated dissatisfaction', 'Team attrition risk', 'Loss of key personnel', 'Widespread attrition or safety incident'],
        ];

        foreach ($dimensions as $dimension => $labels) {
            foreach ($labels as $index => $label) {
                $this->scale($tenant, 'impact', $dimension, $index + 1, $label);
            }
        }

        // ── Velocity: how fast a risk crystallises ────────────────────
        foreach ([
            [1, 'Slow', 'Materialises over more than 12 months — time to respond'],
            [2, 'Medium', 'Materialises over 3–12 months'],
            [3, 'Fast', 'Materialises in under 3 months — little warning'],
        ] as [$level, $label, $description]) {
            $this->scale($tenant, 'velocity', null, $level, $label, $description);
        }

        // ── Rating bands: the cut-offs AND the heatmap colours ────────
        // 5×5 grid → maximum score 25. Change these rows to re-band the
        // register; no code change is needed anywhere.
        foreach ([
            [1, 'Low', '#0ca30c', 0, 6, 'Manage by routine procedures'],
            [2, 'Moderate', '#fab219', 6.01, 12, 'Management responsibility must be specified'],
            [3, 'High', '#ec835a', 12.01, 18, 'Senior management attention needed; treatment plan required'],
            [4, 'Critical', '#d03b3b', 18.01, null, 'Immediate action; board-level attention'],
        ] as [$level, $label, $colour, $from, $to, $description]) {
            RiskAssessmentScale::withoutGlobalScopes()->firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'scale_type' => 'rating_band',
                    'dimension' => null,
                    'level' => $level,
                ],
                [
                    'label' => $label,
                    'description' => $description,
                    'score_from' => $from,
                    'score_to' => $to,
                    'colour_hex' => $colour,
                    'sort_order' => $level,
                ],
            );
        }
    }

    private function scale(
        Tenant $tenant,
        string $type,
        ?string $dimension,
        int $level,
        string $label,
        ?string $description = null,
        ?int $lower = null,
        ?int $upper = null,
        ?string $currency = null,
    ): void {
        RiskAssessmentScale::withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'scale_type' => $type,
                'dimension' => $dimension,
                'level' => $level,
            ],
            [
                'label' => $label,
                'description' => $description,
                'lower_bound_minor' => $lower,
                'upper_bound_minor' => $upper,
                'currency' => $lower !== null || $upper !== null ? $currency : null,
                // Axis levels are neutral in the UI — the band colour is what
                // fills a heatmap cell, so these stay unstyled.
                'colour_hex' => '#e1e0d9',
                'sort_order' => $level,
            ],
        );
    }

    /**
     * Escalation rules for the Phase 10 triggers. Like every other rule in
     * the matrix these are data: who hears about an appetite breach, and
     * how quickly, is a tenant decision.
     */
    private function seedEscalationRules(Tenant $tenant): void
    {
        $rules = [
            ['Critical', 1, 'appetite_breach', 0, 'Control Function Head'],
            ['Critical', 2, 'appetite_breach', 3, 'Executive Viewer'],
            ['High', 1, 'appetite_breach', 0, 'Control Function Head'],
            ['Medium', 1, 'appetite_breach', 7, 'Control Function Head'],

            ['Critical', 1, 'kri_breach', 0, 'Control Function Head'],
            ['Critical', 2, 'kri_breach', 2, 'Executive Viewer'],
            ['High', 1, 'kri_breach', 0, 'Control Function Head'],
            ['Medium', 1, 'kri_breach', 5, 'Control Owner'],

            ['Critical', 1, 'treatment_overdue', 0, 'Control Owner'],
            ['Critical', 2, 'treatment_overdue', 5, 'Control Function Head'],
            ['High', 1, 'treatment_overdue', 3, 'Control Owner'],
            ['High', 2, 'treatment_overdue', 14, 'Control Function Head'],
            ['Medium', 1, 'treatment_overdue', 7, 'Control Owner'],
            ['Low', 1, 'treatment_overdue', 14, 'Control Owner'],
        ];

        foreach ($rules as [$severity, $tier, $trigger, $days, $role]) {
            EscalationMatrix::withoutGlobalScopes()->firstOrCreate([
                'tenant_id' => $tenant->id,
                'severity' => $severity,
                'tier_no' => $tier,
                'trigger_condition' => $trigger,
                'recipient_role' => $role,
            ], [
                'days_threshold' => $days,
                'channel' => 'both',
                'is_active' => true,
            ]);
        }
    }
}
