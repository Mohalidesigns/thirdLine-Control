<?php

namespace App\Console\Commands;

use App\Models\MonitoringRule;
use App\Services\MonitoringService;
use App\Services\SodService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Evaluate every due monitoring rule (12.5).
 *
 * `--no-capture` runs against the snapshots already on disk, which is
 * what a re-run after a rule fix wants: the same data, the corrected
 * rule, so the difference is attributable to the rule and not to the day.
 */
class RunMonitoringRules extends Command
{
    protected $signature = 'atheris:run-monitoring-rules
        {--tenant= : Limit to one tenant id}
        {--rule= : Run a single rule id, due or not}
        {--no-capture : Evaluate the latest stored snapshots instead of extracting fresh}';

    protected $description = 'Run every due monitoring rule, raise findings and exceptions, and expire SoD acceptances';

    public function handle(MonitoringService $monitoring, SodService $sod): int
    {
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;
        $captureFresh = ! $this->option('no-capture');

        if ($ruleId = $this->option('rule')) {
            $rule = MonitoringRule::withoutGlobalScopes()->findOrFail((int) $ruleId);

            try {
                $run = $monitoring->runRule($rule, 'manual', null, $captureFresh);
                $this->info(sprintf(
                    '%s completed: %d of %d record(s) failed (%.2f%%).',
                    $run->run_ref, $run->records_failed, $run->records_evaluated, $run->exception_rate * 100,
                ));

                return self::SUCCESS;
            } catch (\Throwable $e) {
                $this->error($rule->rule_ref.': '.$e->getMessage());

                return self::FAILURE;
            }
        }

        $expired = $sod->expireAcceptances($tenantId);
        $result = $monitoring->runDue($tenantId, CarbonImmutable::now(), $captureFresh);

        foreach ($result['errors'] as $error) {
            $this->line('  <fg=red>✗</> '.$error);
        }

        $this->info(sprintf(
            '%d rule(s) ran, %d failed to run, %d finding(s) raised, %d SoD acceptance(s) expired.',
            $result['ran'], $result['failed'], $result['findings'], $expired,
        ));

        return self::SUCCESS;
    }
}
