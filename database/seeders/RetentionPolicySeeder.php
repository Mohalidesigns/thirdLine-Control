<?php

namespace Database\Seeders;

use App\Models\RetentionPolicy;
use Illuminate\Database\Seeder;

class RetentionPolicySeeder extends Seeder
{
    use Concerns\ResolvesDemoTenant;

    /**
     * Placeholder policies — the BRD is explicit that actual retention
     * periods are a client DPO decision (§12), so these are illustrative
     * defaults the tenant overrides at onboarding.
     */
    public function run(): void
    {
        $tenant = $this->demoTenantOrFail();

        $policies = [
            ['General Test Evidence', 'General', 72, 'Delete', true, 'Statutory record-keeping for financial institutions — confirm with DPO.', true],
            ['Customer Personal Data Evidence', 'Customer Personal Data', 60, 'Anonymise', true, 'NDPA 2023 data minimisation — confirm retention basis with DPO.', false],
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
