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
            DemoDataSeeder::class,
            ObligationDemoSeeder::class,
            // Phase 9: distribution, CSA/survey/attestation campaigns,
            // documents and improvements build on the demo dataset above.
            Phase9DemoSeeder::class,
        ]);
    }
}
