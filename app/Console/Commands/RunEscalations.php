<?php

namespace App\Console\Commands;

use App\Services\EscalationService;
use Illuminate\Console\Command;

class RunEscalations extends Command
{
    protected $signature = 'secondline:run-escalations';

    protected $description = 'Evaluate escalation matrices and fire tiered escalations for overdue and inactive items';

    public function handle(EscalationService $escalationService): int
    {
        $fired = $escalationService->run();

        $this->info("Fired {$fired} escalation(s).");

        return self::SUCCESS;
    }
}
