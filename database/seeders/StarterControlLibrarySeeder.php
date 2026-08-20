<?php

namespace Database\Seeders;

use App\Models\Control;
use App\Models\ControlCategory;
use Illuminate\Database\Seeder;

class StarterControlLibrarySeeder extends Seeder
{
    use Concerns\ResolvesDemoTenant;

    /**
     * Pre-seeded starter library of standard banking controls (FR-1.3),
     * marked as adoptable templates a tenant copies into their own library.
     */
    public function run(): void
    {
        $tenant = $this->demoTenantOrFail();

        $categories = [];
        foreach ([
            'Segregation of Duties', 'Access Management', 'Authorisation',
            'Reconciliation', 'Dual Control', 'Cut-off', 'Physical Security',
            'Change Management', 'Cash Handling',
        ] as $name) {
            $categories[$name] = ControlCategory::firstOrCreate(
                ['name' => $name, 'tenant_id' => null],
                ['is_system' => true],
            );
        }

        $templates = [
            ['Maker-checker on payment initiation', 'Segregation of Duties', 'Preventive', 'IT-Dependent Manual', 'Daily',
                'No single officer can both initiate and approve a payment instruction.',
                'Ensure all outward payments carry independent authorisation before release.'],
            ['Quarterly user access recertification', 'Access Management', 'Detective', 'Manual', 'Quarterly',
                'Line managers recertify all application and network access rights each quarter.',
                'Ensure access rights remain commensurate with current job responsibilities.'],
            ['Privileged access restriction and logging', 'Access Management', 'Preventive', 'Automated', 'Monthly',
                'Administrative access is restricted to named accounts, with all sessions logged and reviewed.',
                'Prevent and detect unauthorised privileged activity on core systems.'],
            ['Approval limits enforcement in core banking', 'Authorisation', 'Preventive', 'Automated', 'Quarterly',
                'The core banking system enforces tiered transaction approval limits per officer grade.',
                'Ensure transactions above threshold receive appropriately senior approval.'],
            ['Daily GL-to-subledger reconciliation', 'Reconciliation', 'Detective', 'Manual', 'Daily',
                'Suspense and settlement accounts are reconciled to the general ledger daily, with ageing of open items.',
                'Detect posting errors and unauthorised entries promptly.'],
            ['Nostro account reconciliation', 'Reconciliation', 'Detective', 'Manual', 'Weekly',
                'Nostro accounts are reconciled weekly; open items above 30 days escalate to Treasury management.',
                'Ensure correspondent bank positions are complete and accurate.'],
            ['Dual control over vault cash', 'Dual Control', 'Preventive', 'Manual', 'Daily',
                'Vault access requires two custodians with separate keys/combinations; movements are dual-signed.',
                'Safeguard vault cash from single-person access.'],
            ['ATM cash replenishment dual custody', 'Cash Handling', 'Preventive', 'Manual', 'Weekly',
                'ATM loading is performed by two officers with balancing confirmed against switch records.',
                'Prevent misappropriation during ATM replenishment.'],
            ['Teller till limits and end-of-day proof', 'Cash Handling', 'Detective', 'Manual', 'Daily',
                'Teller tills are subject to intraday limits and proved to the system position at close of business.',
                'Detect cash shortages/overages on the day they occur.'],
            ['Period-end cut-off procedures', 'Cut-off', 'Preventive', 'Manual', 'Monthly',
                'Transactions are recorded in the correct accounting period; late entries require CFO approval.',
                'Ensure financial reporting reflects the proper period.'],
            ['Data centre physical access control', 'Physical Security', 'Preventive', 'Hybrid', 'Monthly',
                'Data centre entry requires badge + biometric, with visitor logs reviewed monthly.',
                'Restrict physical access to critical infrastructure.'],
            ['Change advisory board approval', 'Change Management', 'Preventive', 'Manual', 'Monthly',
                'All production changes require CAB approval with documented rollback plans; emergency changes ratified within 48 hours.',
                'Prevent unauthorised or destabilising changes to production systems.'],
        ];

        foreach ($templates as $i => [$title, $category, $type, $nature, $frequency, $description, $objective]) {
            Control::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'control_ref' => 'TPL-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT)],
                [
                    'title' => $title,
                    'description' => $description,
                    'objective' => $objective,
                    'type' => $type,
                    'nature' => $nature,
                    'category_id' => $categories[$category]->id,
                    'frequency' => $frequency,
                    'is_key_control' => true,
                    'is_template' => true,
                    'status' => 'Active',
                    'framework_refs' => ['CBN Internal Control Guidelines'],
                ],
            );
        }
    }
}
