<?php

namespace App\Console\Commands;

use App\Services\ControlTaskService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * CR-03 §E.1: the nightly run that turns Frequency of Activity into
 * work. Runs at 00:45, deliberately before the existing 01:00
 * test-instance job and the 01:15 ageing refresh, so a task that fell
 * due overnight is already in somebody's queue when the 07:00 escalation
 * sweep walks it.
 */
class GenerateControlTasks extends Command
{
    protected $signature = 'atheris:generate-control-tasks
                            {--as-of= : Generate as at this date rather than today (Y-m-d)}
                            {--tenant= : Restrict the run to one tenant}
                            {--dry-run : Report what would be generated and write nothing}';

    protected $description = 'Generate scheduled departmental control function tasks per unit, branch and frequency (idempotent)';

    public function handle(ControlTaskService $service): int
    {
        $asOf = $this->option('as-of') ? CarbonImmutable::parse($this->option('as-of')) : CarbonImmutable::now();
        $dryRun = (bool) $this->option('dry-run');

        $totals = $this->option('tenant')
            ? $service->generateForTenant((int) $this->option('tenant'), $asOf, $dryRun)
            : $service->generateAll($asOf, $dryRun);

        $verb = $dryRun ? 'Would create' : 'Created';

        $this->info(sprintf(
            '%s %d control task(s); %d already existed for the period.',
            $verb, $totals['created'], $totals['skipped'],
        ));

        if ($totals['unassigned'] > 0) {
            // A subset of the tasks just created, not an alternative to
            // them. Surfaced rather than logged quietly: an unassigned
            // task is an unperformed control, and the unit head has been
            // told about each one.
            $this->warn(sprintf(
                '%d of those could not be assigned — the desk or branch has no control officer and the function has no owner.',
                $totals['unassigned'],
            ));
        }

        return self::SUCCESS;
    }
}
