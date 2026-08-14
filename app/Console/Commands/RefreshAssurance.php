<?php

namespace App\Console\Commands;

use App\Services\AssuranceService;
use Illuminate\Console\Command;

class RefreshAssurance extends Command
{
    protected $signature = 'atheris:refresh-assurance {--tenant= : Limit to one tenant id} {--period= : Limit to one period label}';

    protected $description = 'Raise an exception for every significant risk carrying no live independent assurance';

    public function handle(AssuranceService $assurance): int
    {
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;

        $result = $assurance->escalateGaps($tenantId, $this->option('period'));

        $this->info(sprintf(
            '%d assurance gap(s) found, %d newly raised as exceptions.',
            $result['gaps'],
            $result['raised'],
        ));

        return self::SUCCESS;
    }
}
