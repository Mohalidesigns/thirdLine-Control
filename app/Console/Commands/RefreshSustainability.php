<?php

namespace App\Console\Commands;

use App\Services\SustainabilityService;
use Illuminate\Console\Command;

class RefreshSustainability extends Command
{
    protected $signature = 'atheris:refresh-sustainability {--tenant= : Limit to one tenant id}';

    protected $description = 'Mark overdue IFRS S1/S2 pre-reporting filing stages late';

    public function handle(SustainabilityService $sustainability): int
    {
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;

        $late = $sustainability->refreshFilingStages($tenantId);

        $this->info("{$late} filing stage(s) marked late.");

        return self::SUCCESS;
    }
}
