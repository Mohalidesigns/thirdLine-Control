<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Order matters: permissions → roles → tenant + reference data →
     * users → demo domain records (standard §9.4).
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            TenantSeeder::class,
            RatingMatrixSeeder::class,
            ExchangeRateSeeder::class,
            FeatureFlagSeeder::class,
            NotificationEventSeeder::class,
            StarterControlLibrarySeeder::class,
            ReportTemplateSeeder::class,
            RetentionPolicySeeder::class,
            // Phase 8: holidays before content packs (due dates depend on
            // them), content packs before demo data (assignments need
            // obligations to point at).
            PublicHolidaySeeder::class,
            RegulatoryContentSeeder::class,
            // Phase 10: the taxonomy and assessment scales must exist before
            // any risk is scored, so they precede the demo register.
            RiskTaxonomySeeder::class,
            // Phase 11: the policy and complaint taxonomies are reference
            // data and must exist before any policy or complaint is created.
            GovernanceTaxonomySeeder::class,
            DemoDataSeeder::class,
            ObligationDemoSeeder::class,
            // Phase 9: distribution, CSA/survey/attestation campaigns,
            // documents and improvements build on the demo dataset above.
            Phase9DemoSeeder::class,
            // Phase 10 demo data depends on the risks, controls and users
            // created above.
            Phase10DemoSeeder::class,
            // Phase 11 depends on the controls, entities and users above, and
            // on the obligation register for its notification countdowns.
            Phase11DemoSeeder::class,
            // Phase 12 rules point at the controls seeded above and write
            // real snapshots, so it runs last.
            Phase12DemoSeeder::class,
            // Phase 13: the shipped dashboards and report definitions are
            // reference data, but their widgets and sections name live
            // datasets, so they seed after the domain data they describe.
            // The demo packs run last of all — they read the whole dataset.
            SystemDashboardSeeder::class,
            ReportDefinitionSeeder::class,
            Phase13DemoSeeder::class,
            // Phase 14: the prompt library is reference data and could seed
            // anywhere, but the AI demo pack indexes the whole dataset for
            // retrieval and reads it back, so it must run after every other
            // seeder has finished writing.
            AiPromptSeeder::class,
            Phase14AiSeeder::class,
        ]);
    }
}
