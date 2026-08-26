<?php

namespace Database\Seeders;

use App\Models\RetentionPolicy;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class RetentionPolicySeeder extends Seeder
{
    /**
     * Placeholder policies — the BRD is explicit that actual retention
     * periods are a client DPO decision (§12), so these are illustrative
     * defaults the tenant overrides at onboarding.
     */
    public function run(): void
    {
        $tenant = Tenant::firstOrFail();

        $policies = [
            ['General Test Evidence', 'General', 72, 'Delete', true, 'Statutory record-keeping for financial institutions — confirm with DPO.', true],
            ['Customer Personal Data Evidence', 'Customer Personal Data', 60, 'Anonymise', true, 'NDPA 2023 data minimisation — confirm retention basis with DPO.', false],
            // CR-03 §C.7 mitigation 3. At the client's branch count the
            // departmental checklist writes tens of millions of line-level
            // results a year, and most of it is daily evidence nobody reads
            // after the examination that covered it. How long it must
            // survive before summarising is a compliance answer, not an
            // engineering one (§G.6) — 24 months is the plan's proposal and
            // the tenant's DPO overrides it at onboarding.
            ['Control Task Line Evidence', 'Control Task Evidence', 24, 'Delete', true, 'CR-03 §G.6 — CBN and the bank\'s own records policy govern; confirm before the first disposal run.', false],
        ];

        foreach ($policies as [$name, $class, $months, $action, $dual, $note, $default]) {
            RetentionPolicy::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $name],
                [
                    'evidence_class' => $class,
                    'retention_months' => $months,
                    'disposal_action' => $action,
                    'requires_dual_approval' => $dual,
                    'legal_basis_note' => $note,
                    'is_default' => $default,
                ],
            );
        }
    }
}
