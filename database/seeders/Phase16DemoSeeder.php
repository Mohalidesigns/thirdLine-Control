<?php

namespace Database\Seeders;

use App\Models\Control;
use App\Models\ControlRequirementMap;
use App\Models\CrossBorderTransfer;
use App\Models\Entity;
use App\Models\Framework;
use App\Models\FrameworkRequirement;
use App\Models\OrganisationUnit;
use App\Models\ResidencyAttestation;
use App\Models\Risk;
use App\Models\SoaEntry;
use App\Models\Tenant;
use App\Models\TransferLawfulBasis;
use App\Models\User;
use App\Services\ResidencyAttestationService;
use Illuminate\Database\Seeder;

/**
 * Phase 16 demo pack: a small banking group over the demo tenant —
 * holding, the bank itself, a Ghanaian subsidiary and a PFA associate —
 * with explicit per-entity access grants, a cross-border transfer
 * register with NDPA lawful bases, one signed residency attestation, and
 * the dogfooded ISO 27001:2022 Statement of Applicability (16.4).
 */
class Phase16DemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::first();

        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;

        $users = User::withoutGlobalScopes()->where('tenant_id', $tid)
            ->pluck('id', 'email');

        $admin = $users['admin@secondline.test'] ?? null;
        $cfh = $users['cfh@secondline.test'] ?? null;
        $officer = $users['officer1@secondline.test'] ?? null;
        $exec = $users['exec@secondline.test'] ?? null;
        $manager = $users['manager@secondline.test'] ?? null;

        if (! $admin || ! $cfh || ! $officer) {
            return;
        }

        $entities = $this->seedGroupStructure($tid);
        $this->seedGrants($entities, [$admin, $cfh, $exec, $manager], $officer);
        $this->seedSubsidiaryRecords($tid, $entities, $officer, $cfh);
        $this->seedTransferRegister($tid, $entities, $officer, $cfh);
        $this->seedAttestation($tenant, $cfh);
        $this->seedIsmsSoa($tid, $officer, $cfh);
    }

    /**
     * @return array<string, Entity>
     */
    private function seedGroupStructure(int $tid): array
    {
        $headOffice = OrganisationUnit::withoutGlobalScopes()
            ->where('tenant_id', $tid)->where('type', 'Head Office')->first();

        // Group office sits above the bank; the subsidiaries anchor their
        // own units so the bank's standalone numbers exclude them.
        $groupOffice = OrganisationUnit::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tid, 'name' => 'Group Office', 'type' => 'Head Office'],
            ['code' => 'GRP', 'is_legal_entity' => true, 'jurisdiction' => 'NG'],
        );

        $ghanaUnit = OrganisationUnit::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tid, 'name' => 'Demo Bank Ghana', 'type' => 'Subsidiary'],
            ['code' => 'GH', 'parent_id' => $headOffice?->id, 'is_legal_entity' => true, 'jurisdiction' => 'GH'],
        );

        $pfaUnit = OrganisationUnit::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tid, 'name' => 'Demo Pensions PFA', 'type' => 'Subsidiary'],
            ['code' => 'PFA', 'parent_id' => $headOffice?->id, 'is_legal_entity' => true, 'jurisdiction' => 'NG'],
        );

        $make = fn (string $ref, array $attributes) => Entity::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tid, 'reference' => $ref],
            $attributes,
        );

        $holding = $make('ENT-001', [
            'legal_name' => 'Demo Group Holdings Plc',
            'entity_type' => 'holding',
            'organisation_unit_id' => $groupOffice->id,
            'jurisdiction' => 'NG',
            'registration_number' => 'RC-100001',
            'regulators' => ['CBN', 'SEC', 'FRC'],
            'fiscal_year_end' => '12-31',
            'functional_currency' => 'NGN',
            'consolidation_method' => 'full',
            'ownership_percentage' => 100,
            'data_residency_country' => 'NG',
            'status' => 'active',
        ]);

        $bank = $make('ENT-002', [
            'parent_entity_id' => $holding->id,
            'legal_name' => 'Demo Bank Plc',
            'entity_type' => 'bank',
            'organisation_unit_id' => $headOffice?->id,
            'jurisdiction' => 'NG',
            'registration_number' => 'RC-100002',
            'licence_categories' => ['commercial_national'],
            'regulators' => ['CBN', 'NDIC'],
            'fiscal_year_end' => '12-31',
            'functional_currency' => 'NGN',
            'consolidation_method' => 'full',
            'ownership_percentage' => 100,
            'data_residency_country' => 'NG',
            'status' => 'active',
        ]);

        $ghana = $make('ENT-003', [
            'parent_entity_id' => $holding->id,
            'legal_name' => 'Demo Bank (Ghana) Ltd',
            'entity_type' => 'bank',
            'organisation_unit_id' => $ghanaUnit->id,
            'jurisdiction' => 'GH',
            'registration_number' => 'CS-2019-4411',
            'licence_categories' => ['universal_banking'],
            'regulators' => ['BoG'],
            'fiscal_year_end' => '12-31',
            'functional_currency' => 'GHS',
            'consolidation_method' => 'proportional',
            'ownership_percentage' => 60,
            'data_residency_country' => 'GH',
            'status' => 'active',
        ]);

        $pfa = $make('ENT-004', [
            'parent_entity_id' => $holding->id,
            'legal_name' => 'Demo Pensions PFA Ltd',
            'entity_type' => 'pfa',
            'organisation_unit_id' => $pfaUnit->id,
            'jurisdiction' => 'NG',
            'registration_number' => 'RC-100004',
            'regulators' => ['PenCom'],
            'fiscal_year_end' => '12-31',
            'functional_currency' => 'NGN',
            'consolidation_method' => 'equity',
            'ownership_percentage' => 40,
            'data_residency_country' => 'NG',
            'status' => 'active',
        ]);

        return ['holding' => $holding, 'bank' => $bank, 'ghana' => $ghana, 'pfa' => $pfa];
    }

    /**
     * Group officers see the whole group; officer1 is granted the bank
     * only — the negative case the permission tests lean on.
     *
     * @param  array<string, Entity>  $entities
     * @param  array<int, int|null>  $groupUsers
     */
    private function seedGrants(array $entities, array $groupUsers, int $bankOnlyUser): void
    {
        $grantor = $groupUsers[0];

        foreach ($entities as $entity) {
            foreach (array_filter($groupUsers) as $userId) {
                $entity->grantedUsers()->syncWithoutDetaching([
                    $userId => ['granted_by' => $grantor, 'note' => 'Group oversight (demo)'],
                ]);
            }
        }

        $entities['bank']->grantedUsers()->syncWithoutDetaching([
            $bankOnlyUser => ['granted_by' => $grantor, 'note' => 'Bank second line (demo)'],
        ]);
    }

    /**
     * A little life in the subsidiaries so the rollup has numbers.
     *
     * @param  array<string, Entity>  $entities
     */
    private function seedSubsidiaryRecords(int $tid, array $entities, int $officer, int $cfh): void
    {
        $rows = [
            ['ghana', 'GH-CTL-001', 'BoG prudential returns review', 'Demo Bank (Ghana) Ltd'],
            ['ghana', 'GH-CTL-002', 'Cedi liquidity monitoring', 'Demo Bank (Ghana) Ltd'],
            ['pfa', 'PFA-CTL-001', 'RSA contribution reconciliation', 'Demo Pensions PFA Ltd'],
        ];

        foreach ($rows as [$key, $ref, $title]) {
            Control::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tid, 'control_ref' => $ref],
                [
                    'title' => $title,
                    'description' => "Demo subsidiary control: {$title}.",
                    'type' => 'Detective',
                    'nature' => 'Manual',
                    'frequency' => 'Monthly',
                    'unit_id' => $entities[$key]->organisation_unit_id,
                    'is_key_control' => true,
                    'status' => 'Active',
                    'created_by' => $officer,
                    'approved_by' => $cfh,
                    'approved_at' => now()->subMonths(2),
                ],
            );
        }

        Risk::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tid, 'code' => 'GH-RSK-001'],
            [
                'title' => 'Cedi devaluation exposure on group reporting',
                'description' => 'Demo subsidiary risk.',
                'category' => 'Financial Reporting',
                'inherent_likelihood' => 3,
                'inherent_impact' => 4,
                'inherent_rating' => 12,
                'residual_rating' => 8,
                'entity_id' => $entities['ghana']->organisation_unit_id,
                'status' => 'active',
            ],
        );
    }

    /**
     * @param  array<string, Entity>  $entities
     */
    private function seedTransferRegister(int $tid, array $entities, int $officer, int $cfh): void
    {
        $bases = TransferLawfulBasis::withoutGlobalScopes()->whereNull('tenant_id')->pluck('id', 'code');

        if ($bases->isEmpty() || CrossBorderTransfer::withoutGlobalScopes()->where('tenant_id', $tid)->exists()) {
            return;
        }

        CrossBorderTransfer::withoutGlobalScopes()->create([
            'tenant_id' => $tid,
            'entity_id' => $entities['ghana']->id,
            'reference' => 'CBT-'.now()->year.'-001',
            'direction' => 'outbound',
            'data_categories' => ['group consolidation extract', 'control effectiveness summary'],
            'contains_personal_data' => false,
            'description' => 'Monthly group consolidation extract to the Ghana subsidiary finance team.',
            'destination_country' => 'GH',
            'recipient' => 'Demo Bank (Ghana) Ltd — Group Reporting',
            'lawful_basis_id' => $bases['NON-PERSONAL'],
            'lawful_basis_note' => 'Aggregated control and financial data only; no personal data leaves the NG plane.',
            'volume_records' => 1,
            'recorded_by' => $officer,
            'authorised_by' => $cfh,
            'authorised_at' => now()->subDays(20),
            'transferred_at' => now()->subDays(19),
            'status' => 'completed',
        ]);

        CrossBorderTransfer::withoutGlobalScopes()->create([
            'tenant_id' => $tid,
            'entity_id' => $entities['bank']->id,
            'reference' => 'CBT-'.now()->year.'-002',
            'direction' => 'outbound',
            'data_categories' => ['staff directory (name, role, work email)'],
            'contains_personal_data' => true,
            'description' => 'Group HR systems consolidation — staff directory for the holding company.',
            'destination_country' => 'GH',
            'recipient' => 'Group Shared Services (Accra)',
            'lawful_basis_id' => $bases['INSTRUMENT'],
            'lawful_basis_note' => 'Intra-group data transfer agreement (binding corporate rules pattern) — pending NDPC instrument verification.',
            'volume_records' => 412,
            'recorded_by' => $officer,
            'status' => 'pending_authorisation',
        ]);
    }

    private function seedAttestation(Tenant $tenant, int $cfh): void
    {
        if (ResidencyAttestation::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()) {
            return;
        }

        app(ResidencyAttestationService::class)->generate(
            $tenant,
            User::withoutGlobalScopes()->findOrFail($cfh),
            now()->subQuarter()->startOfQuarter()->toDateString(),
            now()->subQuarter()->endOfQuarter()->toDateString(),
        );
    }

    /**
     * Dogfooding (16.4): Atheris runs its own ISMS on the shipped ISO
     * 27001:2022 pack — platform controls mapped to Annex A, applicability
     * decided per control, exclusions justified.
     */
    private function seedIsmsSoa(int $tid, int $officer, int $cfh): void
    {
        $framework = Framework::withoutGlobalScopes()->whereNull('tenant_id')
            ->where('code', 'ISO-27001-2022')->orderByDesc('id')->first();

        if (! $framework) {
            return;
        }

        // Map the platform's own operating controls onto the Annex A
        // controls they implement.
        $mappings = [
            'CTL-004' => ['A.5.15', 'A.5.18', 'A.8.2'],   // access recertification
            'CTL-006' => ['A.8.32'],                       // change approval
            'CTL-001' => ['A.5.3'],                        // segregation via dual authorisation
        ];

        $controls = Control::withoutGlobalScopes()->where('tenant_id', $tid)
            ->whereIn('control_ref', array_keys($mappings))->pluck('id', 'control_ref');

        $requirements = FrameworkRequirement::where('framework_id', $framework->id)
            ->pluck('id', 'ref_code');

        foreach ($mappings as $controlRef => $refCodes) {
            foreach ($refCodes as $refCode) {
                if (! isset($controls[$controlRef], $requirements[$refCode])) {
                    continue;
                }

                ControlRequirementMap::withoutGlobalScopes()->firstOrCreate(
                    [
                        'tenant_id' => $tid,
                        'control_id' => $controls[$controlRef],
                        'requirement_id' => $requirements[$refCode],
                    ],
                    [
                        'coverage' => 'partial',
                        'mapped_by' => $officer,
                        'mapped_at' => now()->subMonth(),
                        'approved_by' => $cfh,
                        'approved_at' => now()->subMonth()->addDay(),
                    ],
                );
            }
        }

        // Applicability decisions. The exclusion carries its justification —
        // an unexplained exclusion is what a certification auditor rejects.
        $decisions = [
            ['A.5.15', true, 'Access to every module is permission-gated with four-layer enforcement.', 'implemented'],
            ['A.5.18', true, 'Quarterly recertification runs as control CTL-004.', 'implemented'],
            ['A.5.3', true, 'Maker-checker separation is enforced structurally across all approval gates.', 'implemented'],
            ['A.8.2', true, 'Privileged access is role-scoped; break-glass accounts are capped and audited.', 'partially_implemented'],
            ['A.8.32', true, 'Production changes require approval; CI enforces tests and dependency scans.', 'partially_implemented'],
            ['A.7.4', false, 'Physical security monitoring belongs to the hosting facility under its colocation agreement (docs/deployment); monitored via the facility\'s SOC 2 report.', 'not_implemented'],
        ];

        foreach ($decisions as [$refCode, $applicable, $justification, $status]) {
            if (! isset($requirements[$refCode])) {
                continue;
            }

            SoaEntry::withoutGlobalScopes()->updateOrCreate(
                [
                    'tenant_id' => $tid,
                    'framework_id' => $framework->id,
                    'requirement_id' => $requirements[$refCode],
                ],
                [
                    'is_applicable' => $applicable,
                    'justification' => $justification,
                    'implementation_status' => $status,
                    'decided_by' => $cfh,
                    'decided_at' => now()->subWeeks(2),
                ],
            );
        }
    }
}
