<?php

namespace App\Console\Commands;

use App\Services\ObjectiveService;
use Illuminate\Console\Command;

class RollUpObjectives extends Command
{
    protected $signature = 'atheris:roll-up-objectives {--tenant= : Limit to one tenant id}';

    protected $description = 'Recompute strategic objective progress from measures, initiatives and cascaded objectives';

    public function handle(ObjectiveService $objectives): int
    {
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;

        $count = $objectives->rollUpAll($tenantId);

        $this->info("{$count} objective(s) rolled up.");

        return self::SUCCESS;
    }
}
