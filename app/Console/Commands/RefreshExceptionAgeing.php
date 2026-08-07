<?php

namespace App\Console\Commands;

use App\Services\ExceptionService;
use Illuminate\Console\Command;

class RefreshExceptionAgeing extends Command
{
    protected $signature = 'secondline:refresh-ageing';

    protected $description = 'Recompute age_days and is_overdue on open exceptions; reopen expired risk acceptances';

    public function handle(ExceptionService $exceptionService): int
    {
        $count = $exceptionService->refreshAgeing();

        $this->info("Refreshed ageing on {$count} exception(s).");

        return self::SUCCESS;
    }
}
