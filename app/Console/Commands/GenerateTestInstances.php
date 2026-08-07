<?php

namespace App\Console\Commands;

use App\Services\TestingService;
use Illuminate\Console\Command;

class GenerateTestInstances extends Command
{
    protected $signature = 'secondline:generate-test-instances';

    protected $description = 'Generate scheduled test instances from each active control\'s testing frequency (idempotent, run daily)';

    public function handle(TestingService $testingService): int
    {
        $created = $testingService->generateScheduledInstances();

        $this->info("Created {$created} test instance(s).");

        return self::SUCCESS;
    }
}
