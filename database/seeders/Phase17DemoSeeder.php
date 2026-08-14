<?php

namespace Database\Seeders;

use App\Models\AssuranceActivity;
use App\Models\AssuranceProvider;
use App\Models\Control;
use App\Models\Entity;
use App\Models\GhgDataPoint;
use App\Models\Initiative;
use App\Models\InitiativeMilestone;
use App\Models\MaterialityAssessment;
use App\Models\Metric;
use App\Models\Objective;
use App\Models\ObjectiveMetric;
use App\Models\Risk;
use App\Models\SustainabilityFiling;
use App\Models\SustainabilityTopic;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorAssessment;
use App\Models\VendorContract;
use App\Models\VendorFinding;
use App\Models\VendorScreening;
use App\Services\AssuranceService;
use App\Services\LinkageService;
use App\Services\ObjectiveService;
use App\Services\SustainabilityService;
use App\Services\VendorService;
use Illuminate\Database\Seeder;

/**
 * Phase 17 demo pack: a Nigerian bank's strategy layer, its third-party
 * estate, its first IFRS S1/S2 reporting cycle and its combined assurance
 * map — sitting on the group structure Phase 16 seeded.
 *
 * The dataset is built to demonstrate the four things the phase exists to
 * show: a strategic objective whose progress is derived rather than
 * asserted; a lapsed due-diligence review and a real concentration; an
 * unverified sustainability position with named exclusions; and an
 * assurance map that has both a genuine gap and a genuine duplication.
 */
class Phase17DemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::first();

        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;

        $users = User::withoutGlobalScopes()->where('tenant_id', $tid)->pluck('id', 'email');

        $cfh = $users['cfh@secondline.test'] ?? null;
        $officer = $users['officer1@secondline.test'] ?? null;
        $exec = $users['exec@secondline.test'] ?? null;
        $owner = $users['owner1@secondline.test'] ?? $officer;

        if (! $cfh || ! $officer) {
            return;
        }

        $entities = Entity::withoutGlobalScopes()->where('tenant_id', $tid)->get()->keyBy('reference');
        $bank = $entities->get('ENT-002') ?? $entities->first();

        $this->seedStrategy($tid, $cfh, $officer, $exec, $bank);
        $this->seedThirdParties($tid, $cfh, $officer, $owner, $bank);
        $this->seedSustainability($tid, $cfh, $officer, $bank);
        $this->seedAssurance($tid, $cfh);
    }

    // ── 17.1 Strategy ────────────────────────────────────────────────

    private function seedStrategy(int $tid, int $cfh, int $officer, ?int $exec, ?Entity $bank): void
    {
        if (Objective::withoutGlobalScopes()->where('tenant_id', $tid)->exists()) {
            return;
        }

        $objectives = [
            ['OBJ-FIN-1', 'financial', 'Grow retail deposit base by 25%', $cfh, 3],
            ['OBJ-CX-1', 'customer', 'Halve median complaint resolution time', $officer, 2],
            ['OBJ-OPS-1', 'internal_process', 'Automate 60% of manual control testing', $officer, 2],
            ['OBJ-LG-1', 'learning_growth', 'Certify every control officer to CRMA standard', $cfh, 1],
            ['OBJ-SUS-1', 'sustainability', 'Be IFRS S1/S2 reporting-ready ahead of first mandatory period', $cfh, 3],
        ];

        $created = [];

        foreach ($objectives as [$code, $perspective, $title, $ownerId, $weight]) {
            $created[$code] = Objective::withoutGlobalScopes()->create([
                'tenant_id' => $tid,
                'entity_id' => $bank?->id,
                'perspective' => $perspective,
                'code' => $code,
                'title' => $title,
                'owner_id' => $ownerId,
                'period' => 'FY'.now()->year,
                'start_date' => now()->startOfYear()->toDateString(),
                'target_date' => now()->endOfYear()->toDateString(),
                'status' => 'Active',
                'progress_mode' => 'auto',
                'weight' => $weight,
                'created_by' => $officer,
            ]);
        }

        // Measures: a real KRI/KPI drives each objective where one exists.
        $metrics = Metric::withoutGlobalScopes()->where('tenant_id', $tid)->get()->keyBy('code');
        $objectiveService = app(ObjectiveService::class);

        // Each objective is measured by the indicator that actually speaks
        // to it, with a baseline and target that bracket the live reading —
        // so the demo scorecard shows real mid-flight positions rather than
        // everything pinned at 0% or 100%.
        //
        // 'OBJ-LG-1' is deliberately left with no measure and no initiative:
        // the scorecard should show at least one objective reading "not
        // measured", because that is the honest state of a freshly written
        // objective and it must not read as 0%.
        $measureMap = [
            // Complaints resolved outside the window: 8.4% at baseline,
            // 5.9% now, 4% target — a bit over half way.
            'OBJ-CX-1' => ['metric' => 'KRI-COMPLAINT', 'baseline' => 8.4, 'target' => 4],
            // Control test pass rate: 88% at baseline, 94% now, 98% target.
            'OBJ-OPS-1' => ['metric' => 'KCI-TESTPASS', 'baseline' => 88, 'target' => 98],
            // Operational loss rate in bps: 0.31 at baseline, 0.233 now,
            // 0.15 target.
            'OBJ-FIN-1' => ['metric' => 'KRI-LOSS-BPS', 'baseline' => 0.31, 'target' => 0.15],
        ];

        foreach ($measureMap as $code => $bounds) {
            $metric = $metrics->get($bounds['metric']);

            if (! $metric || ! isset($created[$code])) {
                continue;
            }

            ObjectiveMetric::withoutGlobalScopes()->create([
                'tenant_id' => $tid,
                'objective_id' => $created[$code]->id,
                'metric_id' => $metric->id,
                'baseline_value' => $bounds['baseline'],
                'target_value' => $bounds['target'],
                'weight' => 2,
                'note' => 'Scorecard measure.',
            ]);
        }

        // Initiatives with milestones, so the roll-up has something to
        // derive from beyond the indicators.
        $initiatives = [
            ['OBJ-FIN-1', 'Agent banking rollout — 2,000 new agents', 45_000_000_00, [
                ['Vendor selection complete', true],
                ['Pilot in 3 states', true],
                ['National rollout', false],
                ['Post-implementation review', false],
            ]],
            ['OBJ-OPS-1', 'Continuous controls monitoring for the payments estate', 18_000_000_00, [
                ['Core banking connector live', true],
                ['First 20 rules approved', true],
                ['Switch connector live', true],
                ['Retire 40 manual test scripts', false],
            ]],
            ['OBJ-SUS-1', 'IFRS S1/S2 readiness programme', 25_000_000_00, [
                ['Materiality assessment complete', true],
                ['Scope 1 & 2 inventory built', true],
                ['Controls over sustainability reporting mapped to COSO ICSR', false],
                ['Assurance readiness review', false],
                ['Stage 1 pre-reporting filing', false],
            ]],
        ];

        foreach ($initiatives as $index => [$objectiveCode, $title, $budget, $milestones]) {
            if (! isset($created[$objectiveCode])) {
                continue;
            }

            $initiative = Initiative::withoutGlobalScopes()->create([
                'tenant_id' => $tid,
                'objective_id' => $created[$objectiveCode]->id,
                'entity_id' => $bank?->id,
                'reference' => sprintf('INI-%d-%03d', now()->year, $index + 1),
                'title' => $title,
                'owner_id' => $officer,
                'status' => 'In Progress',
                'start_date' => now()->startOfYear()->toDateString(),
                'end_date' => now()->endOfYear()->toDateString(),
                'budget_minor' => $budget,
                'spend_minor' => (int) round($budget * 0.4),
                'currency' => 'NGN',
                'progress_mode' => 'auto',
                'weight' => 1,
                'created_by' => $officer,
            ]);

            foreach ($milestones as $order => [$milestoneTitle, $complete]) {
                InitiativeMilestone::withoutGlobalScopes()->create([
                    'tenant_id' => $tid,
                    'initiative_id' => $initiative->id,
                    'title' => $milestoneTitle,
                    'due_date' => now()->startOfYear()->addMonths(($order + 1) * 2)->toDateString(),
                    'status' => $complete ? 'Complete' : 'Pending',
                    'completed_at' => $complete ? now()->subMonths(2) : null,
                    'weight' => 1,
                    'sort_order' => $order,
                ]);
            }
        }

        // Link the top risks to the objectives they threaten, which is what
        // makes the strategy map answer "is the cover holding".
        $linkage = app(LinkageService::class);
        $risks = Risk::withoutGlobalScopes()->where('tenant_id', $tid)->limit(4)->get();

        foreach ($risks as $index => $risk) {
            $objective = array_values($created)[$index % count($created)];

            $linkage->link(
                'risk', $risk->id, 'objective', $objective->id,
                'relates_to', 4, 'Threatens delivery of this objective.'
            );
        }

        $objectiveService->rollUpAll($tid);

        // The executive tier is the second pair of eyes on the scorecard.
        if ($exec) {
            foreach ($created as $objective) {
                $objective->auditAction('approved', ['status' => 'Draft'], [
                    'status' => 'Active', 'approved_by' => $exec,
                ]);
            }
        }
    }

    // ── 17.2 Third parties ───────────────────────────────────────────

    private function seedThirdParties(int $tid, int $cfh, int $officer, ?int $owner, ?Entity $bank): void
    {
        if (Vendor::withoutGlobalScopes()->where('tenant_id', $tid)->exists()) {
            return;
        }

        $vendors = [
            ['Interswitch Limited', 'Payment processing', 'Critical', 'NG', 'NG', 60_000_000_000, 'personal', true],
            ['NIBSS PLC', 'Payment infrastructure', 'Critical', 'NG', 'NG', 32_000_000_000, 'personal', true],
            ['Rack Centre Limited', 'Data centre & colocation', 'Critical', 'NG', 'NG', 18_000_000_000, 'confidential', true],
            ['MainOne (Equinix LG1)', 'Connectivity', 'High', 'NG', 'NG', 9_000_000_000, 'internal', false],
            ['Oracle Nigeria', 'Core banking licence & support', 'High', 'NG', 'NG', 14_000_000_000, 'confidential', true],
            ['Deloitte Nigeria', 'External audit', 'High', 'NG', 'NG', 6_500_000_000, 'confidential', false],
            ['Smile Communications', 'Branch connectivity', 'Medium', 'NG', 'NG', 2_100_000_000, 'none', false],
            ['CRC Credit Bureau', 'Credit reference', 'Medium', 'NG', 'NG', 1_400_000_000, 'personal', false],
        ];

        $service = app(VendorService::class);
        $created = [];

        foreach ($vendors as $index => [$name, $category, $criticality, $jurisdiction, $processing, $spend, $classification, $notify]) {
            $created[$name] = Vendor::withoutGlobalScopes()->create([
                'tenant_id' => $tid,
                'entity_id' => $bank?->id,
                'reference' => sprintf('TP-%03d', $index + 1),
                'legal_name' => $name,
                'category' => $category,
                'criticality' => $criticality,
                'services_provided' => $category.' services to the bank.',
                'jurisdiction' => $jurisdiction,
                'processing_country' => $processing,
                'relationship_start' => now()->subYears(3)->toDateString(),
                'annual_spend_minor' => $spend,
                'spend_currency' => 'NGN',
                'owner_id' => $index % 2 === 0 ? $officer : ($owner ?? $officer),
                'regulator_notification_required' => $notify,
                // Interswitch is notified; NIBSS deliberately is not, so the
                // register shows one outstanding notification to work.
                'regulator_notified_at' => $notify && $index !== 1 ? now()->subMonths(8)->toDateString() : null,
                'regulator_notification_ref' => $notify && $index !== 1 ? 'CBN/BSD/OUT/2025/'.(100 + $index) : null,
                'data_access_classification' => $classification,
                'sub_processors' => $index === 0 ? [
                    ['name' => 'Amazon Web Services', 'service' => 'Tokenisation vault', 'country' => 'ZA'],
                ] : null,
                'review_frequency_months' => $criticality === 'Critical' ? 12 : 24,
                'status' => 'Active',
                'created_by' => $officer,
            ]);
        }

        // Approved due diligence on most; one deliberately lapsed so the
        // expiry sweep has something real to find.
        foreach ($created as $name => $vendor) {
            $assessment = VendorAssessment::withoutGlobalScopes()->create([
                'tenant_id' => $tid,
                'vendor_id' => $vendor->id,
                'reference' => 'TPA-'.now()->year.'-'.str_pad((string) $vendor->id, 3, '0', STR_PAD_LEFT),
                'assessment_type' => 'periodic',
                'period_label' => 'FY'.now()->subYear()->year,
                'risk_score' => match ($vendor->criticality) {
                    'Critical' => 72, 'High' => 55, default => 34,
                },
                'risk_band' => $vendor->criticality,
                'conclusion' => 'Due diligence completed against the outsourcing questionnaire. '
                    .($vendor->touchesPersonalData() ? 'NDPA processor obligations confirmed in contract.' : 'No personal data processed.'),
                'assessed_by' => $officer,
                'assessed_at' => now()->subMonths(11),
                'approved_by' => $cfh,
                'approved_at' => now()->subMonths(11),
                'review_frequency_months' => $vendor->review_frequency_months,
                'expires_at' => $name === 'Oracle Nigeria'
                    ? now()->subDays(21)->toDateString()
                    : now()->addMonths(1)->toDateString(),
                'status' => 'Approved',
            ]);

            if ($name === 'NIBSS PLC') {
                VendorFinding::withoutGlobalScopes()->create([
                    'tenant_id' => $tid,
                    'vendor_id' => $vendor->id,
                    'assessment_id' => $assessment->id,
                    'reference' => 'TPF-'.now()->year.'-001',
                    'title' => 'No documented exit plan for the instant payments rail',
                    'description' => 'The contract carries no substitutability analysis and no tested exit plan, '
                        .'which is the concentration exposure the board asked about.',
                    'severity' => 'High',
                    'status' => 'In Remediation',
                    'owner_id' => $officer,
                    'due_date' => now()->addMonths(2)->toDateString(),
                    'raised_by' => $officer,
                ]);
            }
        }

        // Contracts: one inside its renewal notice window.
        foreach (['Interswitch Limited' => 90, 'Rack Centre Limited' => 180] as $name => $notice) {
            if (! isset($created[$name])) {
                continue;
            }

            VendorContract::withoutGlobalScopes()->create([
                'tenant_id' => $tid,
                'vendor_id' => $created[$name]->id,
                'entity_id' => $bank?->id,
                'reference' => 'TPC-'.now()->year.'-'.str_pad((string) $created[$name]->id, 3, '0', STR_PAD_LEFT),
                'title' => $name.' master services agreement',
                'contract_type' => 'Master services agreement',
                'start_date' => now()->subYears(3)->toDateString(),
                'end_date' => now()->addDays($notice === 90 ? 60 : 300)->toDateString(),
                'auto_renew' => true,
                'renewal_notice_days' => $notice,
                'value_minor' => $created[$name]->annual_spend_minor * 3,
                'currency' => 'NGN',
                'sla_commitments' => [
                    ['label' => 'Platform availability', 'target' => '99.9% monthly', 'measure' => 'Monthly uptime report'],
                    ['label' => 'Incident notification', 'target' => 'Within 1 hour of detection', 'measure' => 'Incident log'],
                ],
                'owner_id' => $officer,
                'status' => 'Active',
            ]);
        }

        // A screening hit nobody has dispositioned yet.
        if (isset($created['Smile Communications'])) {
            VendorScreening::withoutGlobalScopes()->create([
                'tenant_id' => $tid,
                'vendor_id' => $created['Smile Communications']->id,
                'screening_type' => 'adverse_media',
                'screened_at' => now()->subDays(4),
                'result' => 'potential_match',
                'hit_count' => 1,
                'hits' => [[
                    'name' => 'Smile Communications (unrelated entity)',
                    'source' => 'Regional business press',
                    'detail' => 'Report of a regulatory dispute involving a similarly named company in another market.',
                ]],
                'summary' => 'Single adverse-media hit on a similarly named entity in another jurisdiction. '
                    .'Awaiting a named person\'s decision.',
            ]);
        }

        $service->refreshContracts($tid);
    }

    // ── 17.3 Sustainability ──────────────────────────────────────────

    private function seedSustainability(int $tid, int $cfh, int $officer, ?Entity $bank): void
    {
        if (SustainabilityFiling::withoutGlobalScopes()->where('tenant_id', $tid)->exists()) {
            return;
        }

        $service = app(SustainabilityService::class);
        $period = (string) now()->year;

        // Materiality across the topics a Nigerian bank would most likely
        // determine material first.
        $material = ['S2-GHG-1', 'S2-GHG-2', 'S2-FINANCED', 'S1-GOV', 'SOC-INCLUSION', 'SOC-DATA', 'GOV-ETHICS'];

        foreach (SustainabilityTopic::withoutGlobalScopes()->whereNull('tenant_id')->get() as $topic) {
            $isMaterial = in_array($topic->code, $material, true);

            MaterialityAssessment::withoutGlobalScopes()->create([
                'tenant_id' => $tid,
                'entity_id' => $bank?->id,
                'topic_id' => $topic->id,
                'period_label' => $period,
                'basis' => 'double',
                'financial_materiality_score' => $isMaterial ? 78 : 22,
                'impact_materiality_score' => $isMaterial ? 71 : 18,
                'is_material' => $isMaterial,
                'rationale' => $isMaterial
                    ? 'Assessed material on both the financial and impact tests for the reporting period.'
                    : 'Below the threshold on both tests for this period; re-tested each cycle.',
                'stakeholders_consulted' => ['Board Risk Committee', 'Investor relations', 'Corporate banking'],
                'assessed_by' => $officer,
                'assessed_at' => now()->subMonths(2),
                // Deliberately left Submitted, not Approved: the readiness
                // panel should show a real reason a pack cannot be generated.
                'status' => $isMaterial ? 'Approved' : 'Submitted',
                'approved_by' => $isMaterial ? $cfh : null,
                'approved_at' => $isMaterial ? now()->subMonths(2) : null,
            ]);
        }

        // Scope 1 and 2 with full lineage; Scope 3 financed emissions
        // deferred under the transition reliefs.
        $control = Control::withoutGlobalScopes()->where('tenant_id', $tid)->first();

        $figures = [
            ['1', 'Stationary combustion — branch and data-centre generators', 2_400_000, 'litres diesel', 0.002687, 'Verified'],
            ['1', 'Mobile combustion — fleet', 310_000, 'litres petrol', 0.002315, 'Verified'],
            ['2', 'Purchased electricity — grid supply (location-based)', 18_400_000, 'kWh', 0.000439, 'Verified'],
            ['2', 'Purchased electricity — branch network (location-based)', 6_100_000, 'kWh', 0.000439, 'Prepared'],
            ['3', 'Category 15: Investments (financed emissions)', null, null, null, 'Deferred'],
            ['3', 'Category 6: Business travel', 4_200_000, 'passenger-km', 0.000158, 'Prepared'],
        ];

        foreach ($figures as [$scope, $category, $activity, $unit, $factor, $status]) {
            $point = GhgDataPoint::withoutGlobalScopes()->create([
                'tenant_id' => $tid,
                'entity_id' => $bank?->id,
                'scope' => $scope,
                'category' => $category,
                'period_label' => $period,
                'period_start' => now()->startOfYear()->toDateString(),
                'period_end' => now()->endOfYear()->toDateString(),
                'activity_data' => $activity,
                'activity_unit' => $unit,
                'emission_factor' => $factor,
                'emission_factor_source' => $factor ? 'DEFRA 2025 conversion factors' : null,
                'tco2e' => $activity && $factor ? round($activity * $factor, 6) : null,
                'data_quality' => $scope === '3' ? 'estimated' : 'calculated',
                'methodology' => $scope === '2' ? 'location_based' : 'not_applicable',
                'control_id' => $control?->id,
                'prepared_by' => $officer,
                'prepared_at' => now()->subMonth(),
                'is_deferred' => $status === 'Deferred',
                'deferral_reason' => $status === 'Deferred'
                    ? 'Financed emissions deferred in the first reporting period under the IFRS S2 transition reliefs. '
                        .'PCAF-aligned methodology in build.'
                    : null,
                'status' => $status,
                'verified_by' => $status === 'Verified' ? $cfh : null,
                'verified_at' => $status === 'Verified' ? now()->subWeeks(3) : null,
                'verification_note' => $status === 'Verified'
                    ? 'Traced to the fuel purchase ledger and the DisCo billing statements.'
                    : null,
            ]);

            unset($point);
        }

        // The filing itself, with its three computed stage deadlines. It
        // ships unverified — the FRC offsets have not been confirmed line by
        // line against the regulator's own instrument (R10).
        $service->createFiling([
            'tenant_id' => $tid,
            'entity_id' => $bank?->id,
            'reporting_class' => 'pie',
            'adoption_year' => 2028,
            'fiscal_year_start' => '2028-01-01',
            'period_label' => 'FY2028',
            'owner_id' => $cfh,
        ]);
    }

    // ── 17.4 Combined assurance ──────────────────────────────────────

    /**
     * The risk Phase 17 itself introduces: dependence on a single payments
     * processor, which the concentration view has just quantified at 60% of
     * third-party spend. Rated High on its own merits, linked to the vendor
     * and to the objective it threatens — and left with management-only
     * assurance, which is exactly the picture a combined assurance map
     * exists to put in front of a board.
     */
    private function seedConcentrationRisk(int $tid, int $cfh): ?Risk
    {
        $vendor = Vendor::withoutGlobalScopes()
            ->where('tenant_id', $tid)
            ->orderByDesc('annual_spend_minor')
            ->first();

        if (! $vendor) {
            return null;
        }

        $risk = Risk::withoutGlobalScopes()->create([
            'tenant_id' => $tid,
            'code' => 'RSK-TP-001',
            'title' => 'Concentration on a single payments processor',
            'description' => "{$vendor->legal_name} carries the largest share of third-party spend and "
                .'sits on the critical path for card and transfer settlement. There is no tested exit plan '
                .'and no substitutability analysis, so an outage or an exit becomes a service continuity '
                .'event rather than a supplier change.',
            'category' => 'Operational',
            'inherent_likelihood' => 3,
            'inherent_impact' => 5,
            'inherent_rating' => 15,
            'current_rating_band' => 'High',
            'residual_rating' => 12,
            'risk_owner_id' => $cfh,
            'entity_id' => $vendor->entity_id,
            'status' => 'active',
        ]);

        $linkage = app(LinkageService::class);
        $linkage->link('vendor', $vendor->id, 'risk', $risk->id, 'causes', 5, 'Single point of failure in settlement.');

        $objective = Objective::withoutGlobalScopes()->where('tenant_id', $tid)->where('code', 'OBJ-FIN-1')->first();

        if ($objective) {
            $linkage->link('risk', $risk->id, 'objective', $objective->id, 'relates_to', 5,
                'A settlement outage stops deposit growth in its tracks.');
        }

        return $risk;
    }

    private function seedAssurance(int $tid, int $cfh): void
    {
        if (AssuranceProvider::withoutGlobalScopes()->where('tenant_id', $tid)->exists()) {
            return;
        }

        $providers = [
            ['MGT', 'Business unit management', 'management', false],
            ['CTRL', 'Internal Control (second line)', 'second_line', false],
            ['COMP', 'Compliance (second line)', 'second_line', false],
            ['IA', 'Internal Audit', 'internal_audit', true],
            ['EA', 'Deloitte Nigeria (external audit)', 'external_audit', true],
            ['CBN', 'Central Bank of Nigeria (examination)', 'regulator', true],
        ];

        $created = [];

        foreach ($providers as [$code, $name, $line, $independent]) {
            $created[$code] = AssuranceProvider::withoutGlobalScopes()->create([
                'tenant_id' => $tid,
                'code' => $code,
                'name' => $name,
                'line' => $line,
                'is_independent' => $independent,
                'is_active' => true,
            ]);
        }

        $concentrationRisk = $this->seedConcentrationRisk($tid, $cfh);

        $risks = Risk::withoutGlobalScopes()->where('tenant_id', $tid)->orderBy('id')->get();
        $controls = Control::withoutGlobalScopes()->where('tenant_id', $tid)->orderBy('id')->limit(6)->get();
        $period = 'FY'.now()->year;

        // Everything gets management plus second line plus internal audit —
        // except the third-party concentration risk, which gets management
        // only. That is a genuine gap on a genuinely High risk, not a
        // contrived one: the demo tenant's other risks are all rated
        // Moderate, so leaving one of those uncovered would show nothing.
        foreach ($risks as $risk) {
            $coverage = $concentrationRisk && $risk->id === $concentrationRisk->id
                ? ['MGT']
                : ['MGT', 'CTRL', 'IA'];

            foreach ($coverage as $code) {
                AssuranceActivity::withoutGlobalScopes()->create([
                    'tenant_id' => $tid,
                    'provider_id' => $created[$code]->id,
                    'subject_type' => 'risk',
                    'subject_id' => $risk->id,
                    'activity_name' => $code === 'IA'
                        ? "{$period} internal audit — {$risk->title}"
                        : ($code === 'MGT' ? 'Management self-assessment' : 'Second-line control testing'),
                    'coverage_level' => $code === 'MGT' ? 'partial' : 'full',
                    'assurance_type' => $code === 'MGT' ? 'self_assessment' : ($code === 'IA' ? 'reasonable' : 'limited'),
                    'last_assurance_date' => now()->subMonths(3)->toDateString(),
                    'next_assurance_date' => now()->addMonths(9)->toDateString(),
                    'period_label' => $period,
                    'conclusion' => $risk->id % 3 === 0 ? 'needs_improvement' : 'satisfactory',
                    'reliance_level' => $code === 'IA' ? 'moderate' : 'none',
                    'reliance_rationale' => $code === 'IA'
                        ? 'Scope and methodology reviewed; second-line testing reperformed on a sample basis.'
                        : null,
                    'reliance_recorded_by' => $code === 'IA' ? $cfh : null,
                    'reliance_recorded_at' => $code === 'IA' ? now()->subMonths(3) : null,
                    'source' => 'manual',
                ]);
            }
        }

        // One control tested by four providers — the duplication the map
        // exists to surface.
        if ($controls->isNotEmpty()) {
            $duplicated = $controls->first();

            foreach (['CTRL', 'COMP', 'IA', 'EA'] as $code) {
                AssuranceActivity::withoutGlobalScopes()->create([
                    'tenant_id' => $tid,
                    'provider_id' => $created[$code]->id,
                    'subject_type' => 'control',
                    'subject_id' => $duplicated->id,
                    'activity_name' => "{$period} testing of {$duplicated->control_ref}",
                    'coverage_level' => 'full',
                    'assurance_type' => $code === 'EA' ? 'reasonable' : 'limited',
                    'last_assurance_date' => now()->subMonths(2)->toDateString(),
                    'next_assurance_date' => now()->addMonths(10)->toDateString(),
                    'period_label' => $period,
                    'conclusion' => 'satisfactory',
                    'reliance_level' => 'none',
                    'source' => 'manual',
                ]);
            }
        }

        app(AssuranceService::class)->escalateGaps($tid, $period);
    }
}
