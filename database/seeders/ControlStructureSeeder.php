<?php

namespace Database\Seeders;

use App\Models\Control;
use App\Models\ControlEntity;
use App\Models\ControlStakeholder;
use App\Models\ControlUnit;
use App\Models\OrganisationUnit;
use App\Models\Tenant;
use App\Services\ControlStructureService;
use Illuminate\Database\Seeder;

/**
 * CR2-A: the Nigerian-bank control universe. The taxonomy lives HERE as
 * data — never as PHP constants scattered through services (R1). A
 * tenant extends or renames freely; behaviour keys on domain, not name.
 *
 * Idempotent: every write is firstOrCreate keyed on the table's natural
 * unique constraint, so a re-run duplicates nothing.
 */
class ControlStructureSeeder extends Seeder
{
    /** The three seeded sub-units of the Internal Control function. */
    private const UNITS = [
        ['code' => 'HOC', 'name' => 'Head Office Control', 'domain' => 'head_office', 'sequence' => 1,
            'description' => 'Second-line oversight of head office departments.'],
        ['code' => 'ISC', 'name' => 'Information Systems Control', 'domain' => 'information_systems', 'sequence' => 2,
            'description' => 'Second-line oversight of information systems control domains.'],
        ['code' => 'BRC', 'name' => 'Branch Control', 'domain' => 'branch', 'sequence' => 3,
            'description' => 'Second-line oversight of the branch network, derived from the organisation tree.'],
    ];

    /** Head Office Control register: the head office departments. */
    private const HEAD_OFFICE_DEPARTMENTS = [
        'Treasury', 'Human Resources', 'Corporate Services', 'Finance & Accounts',
        'Operations', 'Legal', 'Procurement',
    ];

    /** Information Systems Control register: the IS control domains. */
    private const IS_DOMAINS = [
        'Database Management', 'Network Security', 'Backup & Recovery',
        'Disaster Recovery', 'Vulnerability Management', 'Cloud Platform',
        'Operating Server Infrastructure', 'End User Computing',
        'Application Control', 'ATM', 'Change Management',
        'End of Day Transactions Cutoff',
    ];

    /**
     * The branch ACTIVITY TEMPLATE: instantiated copy-on-write under
     * every branch by control-structure:sync-branches.
     */
    private const BRANCH_ACTIVITIES = [
        'Cash Management', 'Teller Operations', 'Vault', 'ATM', 'POS',
        'Customer Account Opening', 'KYC', 'Funds Transfer',
        'Clearing & Settlements', 'E-Business Channels',
    ];

    public function run(): void
    {
        $service = app(ControlStructureService::class);

        foreach (Tenant::query()->pluck('id') as $tenantId) {
            $this->seedTenant($tenantId, $service);
        }

        $this->seedDemo($service);
    }

    private function seedTenant(int $tenantId, ControlStructureService $service): void
    {
        $units = [];

        foreach (self::UNITS as $definition) {
            $units[$definition['domain']] = ControlUnit::withoutGlobalScope('tenant')->firstOrCreate(
                ['tenant_id' => $tenantId, 'code' => $definition['code']],
                $definition,
            );
        }

        foreach (self::HEAD_OFFICE_DEPARTMENTS as $index => $name) {
            $this->entity($service, $tenantId, $units['head_office'], $name, 'department', $index + 1);
        }

        foreach (self::IS_DOMAINS as $index => $name) {
            $this->entity($service, $tenantId, $units['information_systems'], $name, 'domain', $index + 1);
        }

        foreach (self::BRANCH_ACTIVITIES as $index => $name) {
            $this->entity($service, $tenantId, $units['branch'], $name, 'activity', $index + 1, isTemplate: true);
        }

        // Provision every existing Branch organisation unit and its
        // activities — the same idempotent path the nightly sync runs.
        $service->syncBranches($tenantId);
    }

    private function entity(
        ControlStructureService $service,
        int $tenantId,
        ControlUnit $unit,
        string $name,
        string $kind,
        int $sequence,
        bool $isTemplate = false,
    ): ControlEntity {
        $existing = ControlEntity::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('control_unit_id', $unit->id)
            ->whereNull('parent_id')
            ->where('name', $name)
            ->where('is_template', $isTemplate)
            ->first();

        if ($existing) {
            return $existing;
        }

        // Where a seeded entity matches an existing organisation unit by
        // name (e.g. Treasury), link the bridge automatically; otherwise
        // leave it NULL for the administrator to set.
        $orgUnitId = $isTemplate ? null : OrganisationUnit::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('name', $name)
            ->value('id');

        return ControlEntity::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenantId,
            'control_unit_id' => $unit->id,
            'reference' => $service->nextEntityReference($tenantId),
            'name' => $name,
            'entity_kind' => $kind,
            'organisation_unit_id' => $orgUnitId,
            'review_frequency' => $isTemplate ? 'quarterly' : null,
            'is_template' => $isTemplate,
            'sequence' => $sequence,
            'is_active' => true,
        ]);
    }

    /**
     * Demo data on the demo tenant: a third branch so the branch drill
     * has depth, controls attached to branch activities, and a shared
     * control with a co-owner unit — the CR2-A demo path end to end.
     */
    private function seedDemo(ControlStructureService $service): void
    {
        $tenant = Tenant::query()->where('name', 'Demo Bank Plc')->first();

        if (! $tenant) {
            return;
        }

        $headOffice = OrganisationUnit::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('type', 'Head Office')
            ->first();

        // A third demo branch beside Marina and Ikeja. The observer
        // provisions it under Branch Control the moment it is created.
        OrganisationUnit::withoutGlobalScope('tenant')->firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Kano Branch', 'type' => 'Branch'],
            ['code' => 'BR-003', 'parent_id' => $headOffice?->id],
        );

        // The sync backstop — also covers branches created before CR-02.
        $service->syncBranches($tenant->id);

        // Attach the demo controls where they belong in the structure.
        $attachments = [
            ['Marina Branch', 'Cash Management', 'CTL-002', true],
            ['Marina Branch', 'Vault', 'CTL-005', true],
            ['Marina Branch', 'ATM', 'CTL-001', false],
        ];

        foreach ($attachments as [$branchName, $activityName, $controlRef, $isKey]) {
            $branch = ControlEntity::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where('entity_kind', 'branch')
                ->where('name', $branchName)
                ->first();

            $activity = $branch?->children()->where('name', $activityName)->first();

            $control = Control::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where('control_ref', $controlRef)
                ->first();

            if ($activity && $control) {
                $activity->controls()->syncWithoutDetaching([
                    $control->id => ['tenant_id' => $tenant->id, 'is_key' => $isKey],
                ]);
            }
        }

        // A cross-functional control: the teller till proof is co-owned
        // by Operations (cash processing depends on it), so an exception
        // on it notifies the Operations head as well as its owner.
        $tillProof = Control::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('control_ref', 'CTL-002')
            ->first();

        $operations = OrganisationUnit::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('name', 'Operations')
            ->first();

        if ($tillProof && $operations) {
            ControlStakeholder::withoutGlobalScope('tenant')->firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'control_id' => $tillProof->id,
                    'organisation_unit_id' => $operations->id,
                ],
                [
                    'role' => 'co_owner',
                    'user_id' => $operations->head_user_id,
                    'notes' => 'Cash processing depends on the daily till proof — Operations shares the risk.',
                ],
            );
        }
    }
}
