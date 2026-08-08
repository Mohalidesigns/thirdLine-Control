<?php

namespace Database\Seeders;

use App\Models\Control;
use App\Models\Metric;
use App\Models\OrganisationUnit;
use App\Models\Risk;
use App\Models\RiskAppetite;
use App\Models\RiskCategory;
use App\Models\RiskTreatment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AppetiteService;
use App\Services\LinkageService;
use App\Services\MetricService;
use App\Services\RiskAssessmentService;
use App\Services\TreatmentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * A demoable Nigerian enterprise risk position: a categorised register,
 * board-approved appetite statements, dated assessments (including one
 * quantified with Monte Carlo), treatment plans mid-flight, a live KRI
 * dashboard with a breach, and the linkage graph tying them together.
 */
class Phase10DemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::firstOrFail();
        $tid = $tenant->id;

        $cfh = User::withoutGlobalScopes()->where('email', 'cfh@secondline.test')->first();
        $exec = User::withoutGlobalScopes()->where('email', 'exec@secondline.test')->first();
        $officer1 = User::withoutGlobalScopes()->where('email', 'officer1@secondline.test')->first();
        $officer2 = User::withoutGlobalScopes()->where('email', 'officer2@secondline.test')->first();
        $owner1 = User::withoutGlobalScopes()->where('email', 'owner1@secondline.test')->first();
        $owner2 = User::withoutGlobalScopes()->where('email', 'owner2@secondline.test')->first();

        if (! $cfh || ! $officer1) {
            return;
        }

        $category = fn (string $code) => RiskCategory::withoutGlobalScopes()->where('code', $code)->first();
        $unit = fn (string $name) => OrganisationUnit::withoutGlobalScopes()->where('name', $name)->first();

        $appetiteService = app(AppetiteService::class);
        $assessmentService = app(RiskAssessmentService::class);
        $treatmentService = app(TreatmentService::class);
        $metricService = app(MetricService::class);
        $linkage = app(LinkageService::class);

        // ── 1. Categorise and enrich the existing register ───────────
        $enrichment = [
            'RSK-001' => ['OPS-FRD', 'Operations', 'Fast', 'Internal Fraud', 45_000_000_00, true, $officer1],
            'RSK-002' => ['OPS-FRD', 'Marina Branch', 'Medium', 'Internal Fraud', 12_000_000_00, false, $officer1],
            'RSK-003' => ['OPS-PRC', 'Operations', 'Slow', 'Execution Delivery & Process Management', 8_000_000_00, false, $officer2],
            'RSK-004' => ['CYB-ACC', 'Information Technology', 'Medium', 'Business Disruption & System Failures', 30_000_000_00, false, $officer2],
            'RSK-005' => ['OPS-TEC', 'Information Technology', 'Fast', 'Business Disruption & System Failures', 60_000_000_00, true, $officer1],
        ];

        foreach ($enrichment as $code => [$categoryCode, $unitName, $velocity, $baselType, $impactMinor, $isEmerging, $reviewer]) {
            $risk = Risk::withoutGlobalScopes()->where('tenant_id', $tid)->where('code', $code)->first();

            if (! $risk) {
                continue;
            }

            $risk->update([
                'risk_category_id' => $category($categoryCode)?->id,
                'entity_id' => $unit($unitName)?->id,
                'risk_type' => $code === 'RSK-001' ? 'both' : 'qualitative',
                'risk_source' => 'internal',
                'basel_event_type' => $baselType,
                'velocity' => $velocity,
                'is_emerging' => $isEmerging,
                'horizon' => $isEmerging ? '0–12 months' : '1–3 years',
                'second_line_reviewer_id' => $reviewer?->id,
                'financial_impact_minor' => $impactMinor,
                'loss_currency' => $tenant->currency ?? 'NGN',
            ]);
        }

        // ── 2. Board-approved appetite statements ────────────────────
        $appetites = [
            ['OPS', null, 'We accept a low level of operational risk in pursuit of service and growth, and no appetite for losses arising from control failures already identified.', 'Cautious', 4, 12, 18],
            ['CYB', null, 'We have no appetite for the compromise of customer data or core banking availability.', 'Averse', 2, 9, 15],
            ['CMP', null, 'We have no appetite for regulatory breach. Every applicable CBN, SEC, NDPC and FIRS obligation is met in full and on time.', 'Averse', 2, 6, 12],
            ['CDT', null, 'We accept minimal conduct risk. Customer complaints are resolved within the regulatory window, every time.', 'Minimal', 2, 8, 12],
        ];

        foreach ($appetites as [$categoryCode, $entityName, $statement, $level, $lower, $upper, $capacity]) {
            $existing = RiskAppetite::withoutGlobalScopes()
                ->where('tenant_id', $tid)
                ->where('risk_category_id', $category($categoryCode)?->id)
                ->first();

            if ($existing) {
                continue;
            }

            $appetite = $appetiteService->create([
                'tenant_id' => $tid,
                'risk_category_id' => $category($categoryCode)?->id,
                'entity_id' => $entityName ? $unit($entityName)?->id : null,
                'statement' => $statement,
                'appetite_level' => $level,
                'tolerance_lower' => $lower,
                'tolerance_upper' => $upper,
                'capacity' => $capacity,
                'metric_definition' => 'Highest residual risk score in the category, on the 5×5 scale.',
                'effective_from' => now()->startOfYear()->toDateString(),
                'review_due_at' => now()->addMonths(9)->toDateString(),
            ], $officer1);

            $appetiteService->submitForApproval($appetite);
            $appetiteService->approve($appetite, $exec ?? $cfh);
        }

        // ── 3. Dated assessments ─────────────────────────────────────
        foreach (Risk::withoutGlobalScopes()->where('tenant_id', $tid)->get() as $risk) {
            if ($risk->assessments()->withoutGlobalScopes()->exists()) {
                continue;
            }

            $inherent = $assessmentService->record($risk, [
                'assessment_type' => 'inherent',
                'likelihood_level' => $risk->inherent_likelihood,
                'impact_level' => $risk->inherent_impact,
                'likelihood_rationale' => 'Frequency observed across the last three years of loss data.',
                'impact_rationale' => 'Worst credible single-event exposure, gross of controls.',
                'impact_dimensions' => [
                    'financial' => $risk->inherent_impact,
                    'regulatory' => max(1, $risk->inherent_impact - 1),
                    'reputational' => max(1, $risk->inherent_impact - 1),
                    'operational' => max(1, $risk->inherent_impact - 2),
                    'customer' => max(1, $risk->inherent_impact - 1),
                    'people' => 1,
                ],
                'confidence' => 'Medium',
                'method' => 'workshop',
                'period_label' => now()->format('Y').'-Q'.now()->quarter,
                'notes' => 'Annual risk and control self-assessment workshop.',
                'financial_impact_minor' => $risk->financial_impact_minor,
                'currency' => $risk->loss_currency ?? 'NGN',
                // RSK-001 is quantified: best / most likely / worst case with
                // an annual occurrence probability, so the Monte Carlo engine
                // has something to work with.
                ...($risk->code === 'RSK-001' ? [
                    'loss_min_minor' => 5_000_000_00,
                    'loss_likely_minor' => 45_000_000_00,
                    'loss_max_minor' => 250_000_000_00,
                    'likelihood_percent' => 35,
                    'method' => 'monte_carlo',
                ] : []),
            ], $officer1);

            $assessmentService->publish($inherent->fresh(), $cfh);

            // A target position for the risks with a treatment plan.
            if (in_array($risk->code, ['RSK-001', 'RSK-004', 'RSK-005'], true)) {
                $target = $assessmentService->record($risk, [
                    'assessment_type' => 'target',
                    'likelihood_level' => max(1, $risk->inherent_likelihood - 2),
                    'impact_level' => max(1, $risk->inherent_impact - 1),
                    'likelihood_rationale' => 'Position the board expects once the treatment plan is verified.',
                    'confidence' => 'Medium',
                    'method' => 'workshop',
                ], $officer1);

                $assessmentService->publish($target->fresh(), $cfh);
            }
        }

        // ── 4. Treatment plans mid-flight ────────────────────────────
        $control = fn (string $ref) => Control::withoutGlobalScopes()->where('control_ref', $ref)->first();

        $plans = [
            ['RSK-005', 'Reduce', 'Implement enforced change advisory board approval', $owner1, $cfh,
                now()->subDays(20), now()->subDays(5), 'CTL-006', 45_000_000_00,
                ['Draft revised change policy', 'Configure ITSM approval gate', 'Train release engineers']],
            ['RSK-001', 'Reduce', 'Straight-through processing limits with dual release above ₦5m', $owner1, $cfh,
                now()->subDays(60), now()->addDays(30), 'CTL-001', 120_000_000_00,
                ['Agree limit matrix with Treasury', 'Configure core banking limits', 'Post-implementation review']],
            ['RSK-003', 'Accept', 'Accept residual suspense ageing risk pending core banking upgrade', $owner2, $cfh,
                now()->subDays(15), now()->addDays(90), null, 0,
                []],
        ];

        foreach ($plans as [$riskCode, $strategy, $title, $owner, $approver, $start, $due, $controlRef, $cost, $milestones]) {
            $risk = Risk::withoutGlobalScopes()->where('tenant_id', $tid)->where('code', $riskCode)->first();

            if (! $risk || RiskTreatment::withoutGlobalScopes()->where('risk_id', $risk->id)->exists()) {
                continue;
            }

            $treatment = $treatmentService->propose($risk, [
                'strategy' => $strategy,
                'title' => $title,
                'description' => "Treatment plan for {$risk->code}: {$title}.",
                'owner_id' => $owner?->id,
                'target_rating' => 'Moderate',
                'cost_minor' => $cost ?: null,
                'currency' => $cost ? ($tenant->currency ?? 'NGN') : null,
                'benefit_description' => 'Reduces the residual position into appetite and removes a repeat exception source.',
                'start_at' => $start->toDateString(),
                'due_at' => $due->toDateString(),
                'control_id' => $controlRef ? $control($controlRef)?->id : null,
                'milestones' => array_map(fn (string $milestone, int $index) => [
                    'title' => $milestone,
                    'due_at' => $start->copy()->addWeeks(($index + 1) * 2)->toDateString(),
                    'owner_id' => $owner?->id,
                ], $milestones, array_keys($milestones)),
            ], $officer1);

            $treatmentService->approve($treatment->fresh(), $approver, $strategy === 'Accept' ? [
                'acceptance_reason' => 'Core banking upgrade is scheduled; interim manual reconciliation is in place.',
                'acceptance_expiry' => now()->addMonths(6)->toDateString(),
            ] : []);

            if ($strategy !== 'Accept') {
                $treatmentService->start($treatment->fresh());
                $treatmentService->recordProgress($treatment->fresh(), $riskCode === 'RSK-005' ? 60 : 30);
            }

            $linkage->link('risk', $risk->id, 'treatment', $treatment->id, 'relates_to');

            if ($treatment->control_id) {
                $linkage->link('control', $treatment->control_id, 'risk', $risk->id, 'mitigates');
            }
        }

        // ── 5. KRI / KPI dashboard ───────────────────────────────────
        $this->seedMetrics($tenant, $metricService, $linkage, $category, $cfh, $owner1, $officer1);

        // ── 6. Linkage across modules ────────────────────────────────
        foreach (Risk::withoutGlobalScopes()->where('tenant_id', $tid)->with('controls')->get() as $risk) {
            foreach ($risk->controls as $mapped) {
                $linkage->link('control', $mapped->id, 'risk', $risk->id, 'mitigates', 4);
            }
        }

        // Final pass once every position is settled, so the demo opens on a
        // real appetite breach rather than a blank board view.
        $appetiteService->evaluateAll($tid);
    }

    private function seedMetrics(
        Tenant $tenant,
        MetricService $metrics,
        LinkageService $linkage,
        callable $category,
        ?User $cfh,
        ?User $owner1,
        ?User $officer1,
    ): void {
        $tid = $tenant->id;

        // code, type, name, unit, data type, direction, frequency, category, owner,
        // thresholds [level, operator, from, to], 12-month series
        $definitions = [
            [
                'KRI-FRAUD', 'KRI', 'Attempted internal fraud cases', 'cases', 'count', 'lower_is_better', 'OPS-FRD', $cfh,
                [['Critical', 'gt', 10, null, '#d03b3b'], ['Red', 'gt', 5, null, '#ec835a'], ['Amber', 'between', 3, 5, '#fab219'], ['Green', 'lte', 2, null, '#0ca30c']],
                [1, 2, 1, 3, 2, 4, 3, 2, 4, 5, 4, 7],
            ],
            [
                'KRI-RECON', 'KRI', 'Unreconciled suspense items over 30 days', 'items', 'count', 'lower_is_better', 'OPS-PRC', $owner1,
                [['Critical', 'gt', 200, null, '#d03b3b'], ['Red', 'gt', 120, null, '#ec835a'], ['Amber', 'between', 61, 120, '#fab219'], ['Green', 'lte', 60, null, '#0ca30c']],
                [88, 74, 65, 59, 62, 71, 84, 96, 108, 115, 119, 96],
            ],
            [
                'KRI-DOWNTIME', 'KRI', 'Core banking unplanned downtime', 'minutes', 'duration', 'lower_is_better', 'OPS-TEC', $owner1,
                [['Critical', 'gt', 240, null, '#d03b3b'], ['Red', 'gt', 120, null, '#ec835a'], ['Amber', 'between', 31, 120, '#fab219'], ['Green', 'lte', 30, null, '#0ca30c']],
                [12, 28, 45, 18, 62, 35, 22, 88, 41, 55, 30, 138],
            ],
            [
                'KRI-ACCESS', 'KRI', 'Privileged accounts past recertification', 'accounts', 'count', 'lower_is_better', 'CYB-ACC', $owner1,
                [['Critical', 'gt', 25, null, '#d03b3b'], ['Red', 'gt', 12, null, '#ec835a'], ['Amber', 'between', 5, 12, '#fab219'], ['Green', 'lt', 5, null, '#0ca30c']],
                [3, 4, 6, 9, 7, 5, 4, 8, 11, 9, 10, 8],
            ],
            [
                'KRI-COMPLAINT', 'KRI', 'Complaints resolved outside the regulatory window', '%', 'percentage', 'lower_is_better', 'CDT-CPR', $owner1,
                [['Critical', 'gt', 15, null, '#d03b3b'], ['Red', 'gt', 8, null, '#ec835a'], ['Amber', 'between', 4, 8, '#fab219'], ['Green', 'lt', 4, null, '#0ca30c']],
                [2.1, 3.4, 2.8, 4.2, 5.1, 3.9, 4.8, 6.2, 5.5, 4.1, 3.7, 5.9],
            ],
            [
                'KCI-TESTPASS', 'KCI', 'Control test pass rate', '%', 'percentage', 'higher_is_better', 'OPS', $officer1,
                [['Critical', 'lt', 70, null, '#d03b3b'], ['Red', 'lt', 85, null, '#ec835a'], ['Amber', 'between', 85, 92, '#fab219'], ['Green', 'gt', 92, null, '#0ca30c']],
                [94, 96, 93, 91, 95, 97, 94, 92, 90, 93, 95, 94],
            ],
            [
                'KRI-OPS-LOSS', 'KRI', 'Operational loss recognised', 'NGN', 'currency', 'lower_is_better', 'OPS', $cfh,
                [['Red', 'gt', 50000000, null, '#ec835a'], ['Amber', 'between', 10000001, 50000000, '#fab219'], ['Green', 'lte', 10000000, null, '#0ca30c']],
                [4200000, 8100000, 3400000, 12500000, 6800000, 9200000, 15400000, 7100000, 11800000, 5600000, 8900000, 13200000],
            ],
            [
                'KRI-TXN-VOL', 'KPI', 'Transaction value processed', 'NGN', 'currency', 'higher_is_better', 'OPS', $cfh,
                [['Green', 'gt', 0, null, '#0ca30c']],
                [412000000000, 438000000000, 455000000000, 471000000000, 462000000000, 489000000000,
                    503000000000, 518000000000, 495000000000, 527000000000, 541000000000, 566000000000],
            ],
        ];

        foreach ($definitions as [$code, $type, $name, $unit, $dataType, $direction, $categoryCode, $owner, $thresholds, $series]) {
            if (Metric::withoutGlobalScopes()->where('tenant_id', $tid)->where('code', $code)->exists()) {
                continue;
            }

            $metric = Metric::withoutGlobalScopes()->create([
                'tenant_id' => $tid,
                'metric_type' => $type,
                'code' => $code,
                'name' => $name,
                'description' => "{$name}, reported monthly to the Board Risk Committee.",
                'category_id' => $category($categoryCode)?->id,
                'unit' => $unit,
                'data_type' => $dataType,
                'currency' => $dataType === 'currency' ? ($tenant->currency ?? 'NGN') : null,
                'direction' => $direction,
                'frequency' => 'Monthly',
                'owner_id' => $owner?->id,
                'source' => 'manual',
                'aggregation' => $dataType === 'currency' ? 'sum' : 'last',
                'is_active' => true,
                'first_period' => CarbonImmutable::now()->subMonths(11)->format('Y-m'),
            ]);

            foreach ($thresholds as $index => [$level, $operator, $from, $to, $colour]) {
                $metric->thresholds()->create([
                    'tenant_id' => $tid,
                    'level' => $level,
                    'operator' => $operator,
                    'value_from' => $from,
                    'value_to' => $to,
                    'colour_hex' => $colour,
                    'action_required' => $level === 'Green'
                        ? 'No action — report to committee.'
                        : "Owner to explain the {$level} position and record remediation.",
                    'escalate_to_role' => in_array($level, ['Red', 'Critical'], true) ? 'Control Function Head' : null,
                    'sort_order' => $index,
                ]);
            }

            foreach ($series as $offset => $value) {
                $moment = CarbonImmutable::now()->subMonths(11 - $offset);

                $metrics->capture($metric, [
                    'value' => $value,
                    'period_label' => $moment->format('Y-m'),
                    'comment' => 'Monthly submission from the risk data mart.',
                ], $owner);
            }
        }

        // A calculated metric — loss rate in basis points of transaction
        // value, derived from two captured metrics through the safe
        // expression evaluator (never eval()).
        if (! Metric::withoutGlobalScopes()->where('tenant_id', $tid)->where('code', 'KRI-LOSS-BPS')->exists()) {
            $calculated = Metric::withoutGlobalScopes()->create([
                'tenant_id' => $tid,
                'metric_type' => 'KRI',
                'code' => 'KRI-LOSS-BPS',
                'name' => 'Operational loss rate',
                'description' => 'Operational loss as basis points of transaction value processed.',
                'category_id' => $category('OPS')?->id,
                'formula' => 'Operational loss ÷ transaction value × 10,000',
                'unit' => 'bps',
                'data_type' => 'ratio',
                'direction' => 'lower_is_better',
                'frequency' => 'Monthly',
                'owner_id' => $cfh?->id,
                'source' => 'calculated',
                'calculation_expression' => '[metric.KRI-OPS-LOSS] / [metric.KRI-TXN-VOL] * 10000',
                'aggregation' => 'average',
                'is_active' => true,
            ]);

            foreach ([['Critical', 'gt', 5, null, '#d03b3b'], ['Red', 'gt', 3, null, '#ec835a'],
                ['Amber', 'between', 1.5, 3, '#fab219'], ['Green', 'lte', 1.5, '#0ca30c']] as $index => $threshold) {
                $calculated->thresholds()->create([
                    'tenant_id' => $tid,
                    'level' => $threshold[0],
                    'operator' => $threshold[1],
                    'value_from' => $threshold[2],
                    'value_to' => $threshold[0] === 'Amber' ? $threshold[3] : null,
                    'colour_hex' => $threshold[0] === 'Green' ? '#0ca30c' : ($threshold[4] ?? '#0ca30c'),
                    'sort_order' => $index,
                ]);
            }

            for ($offset = 11; $offset >= 0; $offset--) {
                $metrics->computeCalculated($calculated, CarbonImmutable::now()->subMonths($offset));
            }
        }

        // Link the KRIs to the risks they indicate.
        $indicates = [
            'KRI-FRAUD' => 'RSK-001',
            'KRI-RECON' => 'RSK-003',
            'KRI-DOWNTIME' => 'RSK-005',
            'KRI-ACCESS' => 'RSK-004',
        ];

        foreach ($indicates as $metricCode => $riskCode) {
            $metric = Metric::withoutGlobalScopes()->where('tenant_id', $tid)->where('code', $metricCode)->first();
            $risk = Risk::withoutGlobalScopes()->where('tenant_id', $tid)->where('code', $riskCode)->first();

            if ($metric && $risk) {
                $linkage->link('metric', $metric->id, 'risk', $risk->id, 'indicates', 4);
            }
        }
    }
}
