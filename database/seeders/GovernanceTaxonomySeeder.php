<?php

namespace Database\Seeders;

use App\Models\ComplaintCategory;
use App\Models\PolicyCategory;
use Illuminate\Database\Seeder;

/**
 * Phase 11 reference data (R1): the policy taxonomy, the complaint subject
 * categories and the complaint root-cause categories, all shipped globally
 * (tenant_id NULL) and extendable per tenant.
 *
 * The complaint categories mirror the shape of a CBN Consumer Protection
 * Department return. `regulatory_code` is left NULL throughout: the exact
 * return codes have not been confirmed against the regulator's primary
 * document, and inventing them would put an unverified code on a filed
 * return (R10). A tenant sets them once they have the current template.
 */
class GovernanceTaxonomySeeder extends Seeder
{
    private const POLICY_CATEGORIES = [
        ['GOV', 'Governance', 'Board and committee charters, delegation of authority, corporate governance.'],
        ['RSK', 'Risk management', 'Risk appetite, risk management framework, stress testing.'],
        ['COM', 'Compliance', 'Regulatory compliance, AML/CFT, sanctions, consumer protection.'],
        ['FIN', 'Finance', 'Financial reporting, capital, liquidity, procurement and expenditure.'],
        ['OPS', 'Operations', 'Operational procedures, business continuity, outsourcing and third parties.'],
        ['TEC', 'Technology & information security', 'IT governance, cyber security, change and access management.'],
        ['DAT', 'Data protection & privacy', 'Personal data handling, retention, breach response, cross-border transfer.'],
        ['HRM', 'People', 'Code of conduct, conflicts of interest, whistleblowing, disciplinary.'],
        ['CUS', 'Customer', 'Customer onboarding, complaints handling, product governance, fair treatment.'],
        ['SUS', 'Sustainability', 'Environmental and social risk, climate disclosure, community impact.'],
    ];

    private const COMPLAINT_CATEGORIES = [
        ['ATM', 'ATM and card transactions'],
        ['EFT', 'Electronic funds transfer'],
        ['FEE', 'Fees, charges and interest'],
        ['LOA', 'Loans and credit facilities'],
        ['ACC', 'Account operations and statements'],
        ['FRD', 'Fraud and unauthorised transactions'],
        ['DIG', 'Digital channels — app, USSD, internet banking'],
        ['SVC', 'Service quality and staff conduct'],
        ['PRD', 'Product terms and disclosure'],
        ['DAT', 'Data privacy and confidentiality'],
        ['OTH', 'Other'],
    ];

    private const ROOT_CAUSES = [
        ['SYS', 'System or platform failure'],
        ['PRC', 'Process design weakness'],
        ['CTL', 'Control failure or override'],
        ['HUM', 'Human error'],
        ['TRN', 'Training or competence gap'],
        ['COM', 'Communication or disclosure gap'],
        ['TPY', 'Third-party or vendor failure'],
        ['PLY', 'Policy gap or ambiguity'],
        ['EXT', 'External factor outside our control'],
        ['CUS', 'Customer misunderstanding'],
    ];

    public function run(): void
    {
        foreach (self::POLICY_CATEGORIES as $index => [$code, $name, $description]) {
            PolicyCategory::updateOrCreate(
                ['tenant_id' => null, 'code' => $code],
                ['name' => $name, 'description' => $description, 'sort_order' => $index + 1, 'is_active' => true],
            );
        }

        foreach (self::COMPLAINT_CATEGORIES as $index => [$code, $name]) {
            ComplaintCategory::updateOrCreate(
                ['tenant_id' => null, 'kind' => 'category', 'code' => $code],
                ['name' => $name, 'sort_order' => $index + 1, 'is_active' => true, 'regulatory_code' => null],
            );
        }

        foreach (self::ROOT_CAUSES as $index => [$code, $name]) {
            ComplaintCategory::updateOrCreate(
                ['tenant_id' => null, 'kind' => 'root_cause', 'code' => $code],
                ['name' => $name, 'sort_order' => $index + 1, 'is_active' => true],
            );
        }
    }
}
