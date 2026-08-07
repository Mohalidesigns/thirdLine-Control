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
            DemoDataSeeder::class,
        ]);
    }
}
