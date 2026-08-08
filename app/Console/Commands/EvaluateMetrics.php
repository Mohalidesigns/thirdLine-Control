<?php

namespace App\Console\Commands;

use App\Services\MetricService;
use Illuminate\Console\Command;

class EvaluateMetrics extends Command
{
    protected $signature = 'atheris:evaluate-metrics {--tenant= : Limit to one tenant id}';

    protected $description = 'Compute calculated metrics, evaluate thresholds, open KRI breaches and escalate them';

    public function handle(MetricService $metrics): int
    {
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;

        $result = $metrics->runEvaluation($tenantId);

        $this->info(sprintf(
            '%d calculated metric(s) computed, %d metric(s) evaluated, %d breach(es) opened.',
            $result['calculated'],
            $result['evaluated'],
            $result['breaches'],
        ));

        return self::SUCCESS;
    }
}
