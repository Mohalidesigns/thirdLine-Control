<?php

namespace Database\Seeders;

use App\Models\Control;
use App\Models\ControlRequirementMap;
use App\Models\Framework;
use App\Models\FrameworkRequirement;
use App\Models\ObligationAssignment;
use App\Models\OrganisationUnit;
use App\Models\Regulator;
use App\Models\RegulatoryChange;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ObligationService;
use Illuminate\Database\Seeder;

/**
 * Makes the Phase 8 modules demoable on a fresh database: a Nigerian
 * commercial bank with a regulatory profile, the obligations that actually
 * bind it, a populated compliance calendar including a couple of overdue
 * filings with live penalty exposure, approved and pending control-to-COSO
 * mappings, and a regulatory change part-way through impact assessment.
 */
class ObligationDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::first();

        if (! $tenant) {
            return;
        }

        $obligations = app(ObligationService::class);

        $cfh = User::where('email', 'cfh@secondline.test')->first();
        $officer = User::where('email', 'officer1@secondline.test')->first();
        $owner = User::where('email', 'owner1@secondline.test')->first();

        $entity = $this->profileHeadOffice($tenant->id);

        if (! $entity || ! $owner || ! $cfh) {
            return;
        }

        $this->assignObligations($obligations, $entity, $owner, $cfh);
        $this->mapControlsToCoso($tenant->id, $officer, $cfh);
        $this->seedRegulatoryChange($tenant->id, $officer);
    }

    /** Give the head office a regulatory profile so applicability resolves. */
    private function profileHeadOffice(int $tenantId): ?OrganisationUnit
    {
        $entity = OrganisationUnit::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('type', 'Head Office')
            ->first();

        $entity?->forceFill([
            'is_legal_entity' => true,
            'entity_type' => 'commercial_bank',
            'licence_categories' => ['national', 'quoted', 'large_taxpayer'],
            'jurisdiction' => 'NG',
            'fiscal_year_end_month' => 12,
            'fiscal_year_end_day' => 31,
            'incorporated_on' => '1998-04-15',
        ])->save();

        return $entity;
    }

    private function assignObligations(ObligationService $service, OrganisationUnit $entity, User $owner, User $reviewer): void
    {
        // Daily operational obligations are excluded from the demo: they are
        // real, but a year of daily rows drowns the calendar in noise. Weekly
        // ones are kept — the CBN consumer-protection per-week penalty is the
        // clearest illustration of live exposure — with a shorter lookback.
        $applicable = $service->applicableObligations($entity)
            ->where('trigger_type', 'calendar')
            ->where('frequency', '!=', 'daily')
            ->take(18);

        foreach ($applicable as $obligation) {
            $assignment = ObligationAssignment::withoutGlobalScopes()->updateOrCreate(
                [
                    'tenant_id' => $entity->tenant_id,
                    'obligation_id' => $obligation->id,
                    'entity_id' => $entity->id,
                ],
                [
                    'owner_id' => $owner->id,
                    'reviewer_id' => $reviewer->id,
                    'is_applicable' => true,
                ],
            );

            // A lookback so the demo shows overdue filings and the live
            // penalty exposure they carry, not just an empty future calendar.
            $service->generateInstances(
                $assignment,
                horizonDays: $obligation->frequency === 'weekly' ? 90 : ObligationService::DEFAULT_HORIZON_DAYS,
                lookbackDays: $obligation->frequency === 'weekly' ? 60 : ObligationService::DEFAULT_LOOKBACK_DAYS,
            );

            foreach ($assignment->instances()->withoutGlobalScopes()->get() as $instance) {
                $service->refreshInstance($instance);
            }
        }
    }

    /**
     * A handful of control-to-COSO mappings, deliberately mixed: some
     * approved and counting toward coverage, one still awaiting a checker.
     */
    private function mapControlsToCoso(int $tenantId, ?User $officer, ?User $approver): void
    {
        $coso = Framework::withoutGlobalScopes()->whereNull('tenant_id')->where('code', 'COSO-IC-2013')->first();

        if (! $coso || ! $officer || ! $approver) {
            return;
        }

        $pairs = [
            ['CTL-002', 'P11'],   // access recertification → technology general controls
            ['CTL-003', 'P11'],
            ['CTL-001', 'P10'],   // maker-checker on payments → control activities
            ['CTL-005', 'P16'],   // reconciliation → ongoing evaluations
            ['CTL-004', 'P12'],   // approval limits → policies and procedures
        ];

        foreach ($pairs as $index => [$controlRef, $refCode]) {
            $control = Control::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->where('control_ref', $controlRef)->first();

            $requirement = FrameworkRequirement::where('framework_id', $coso->id)
                ->where('ref_code', $refCode)->first();

            if (! $control || ! $requirement) {
                continue;
            }

            ControlRequirementMap::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenantId, 'control_id' => $control->id, 'requirement_id' => $requirement->id],
                [
                    'coverage' => 'full',
                    'mapped_by' => $officer->id,
                    'mapped_at' => now()->subDays(20 - $index),
                    // The last pair stays unapproved so the maker-checker
                    // queue has something in it on a fresh install.
                    'approved_by' => $index === array_key_last($pairs) ? null : $approver->id,
                    'approved_at' => $index === array_key_last($pairs) ? null : now()->subDays(18 - $index),
                ],
            );
        }
    }

    private function seedRegulatoryChange(int $tenantId, ?User $officer): void
    {
        $cbn = Regulator::where('code', 'CBN')->first();

        if (! $cbn) {
            return;
        }

        RegulatoryChange::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenantId, 'regulator_id' => $cbn->id, 'source_guid' => 'demo-dataloc-circular'],
            [
                'title' => 'Circular on the storage and processing of Nigerian payment transaction data',
                'reference' => 'PSS/DIR/PUB/CIR/001/004',
                'published_at' => now()->subDays(21),
                'effective_at' => now()->addMonths(6)->toDateString(),
                'summary' => 'Requires in-country storage and processing of Nigerian payment transaction data, sets market-structure limits, and introduces monthly market-share returns.',
                'status' => 'Under Review',
                'source' => 'manual',
                'assessed_by' => $officer?->id,
            ],
        );

        RegulatoryChange::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenantId, 'regulator_id' => $cbn->id, 'source_guid' => 'demo-cyber-circular'],
            [
                'title' => 'Reminder on cyber incident reporting timelines',
                'reference' => 'BSD/DIR/GEN/LAB/17/002',
                'published_at' => now()->subDays(4),
                'summary' => 'Restates the incident notification expectations under the Risk-Based Cybersecurity Framework.',
                'status' => 'New',
                'source' => 'manual',
            ],
        );
    }
}
