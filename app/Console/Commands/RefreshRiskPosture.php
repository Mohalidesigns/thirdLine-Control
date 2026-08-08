<?php

namespace App\Console\Commands;

use App\Services\AppetiteService;
use App\Services\TreatmentService;
use Illuminate\Console\Command;

class RefreshRiskPosture extends Command
{
    protected $signature = 'atheris:refresh-risk-posture {--tenant= : Limit to one tenant id}';

    protected $description = 'Re-evaluate risk appetite breaches and flag overdue treatment plans and milestones';

    public function handle(AppetiteService $appetite, TreatmentService $treatments): int
    {
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;

        $overdue = $treatments->refreshOverdue($tenantId);
        $breaches = $appetite->evaluateAll($tenantId);

        $this->info("{$overdue} treatment record(s) flagged overdue, {$breaches} appetite position(s) changed.");

        return self::SUCCESS;
    }
}
