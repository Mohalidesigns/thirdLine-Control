<?php

namespace App\Console\Commands;

use App\Services\ControlTaskService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * CR-03 §C.5: observation tasks — BRANCH AMBIENCE, REVIEW OF VAULT /
 * ATM DOORS — have no deadline and never go overdue. They roll into a
 * fresh instance on the first of the month so the register has something
 * to report on, and last month's closes rather than being chased.
 */
class RollContinuousControlTasks extends Command
{
    protected $signature = 'atheris:roll-continuous-tasks
                            {--as-of= : Roll as at this date rather than today (Y-m-d)}
                            {--dry-run : Report what would roll and write nothing}';

    protected $description = 'Roll continuous (observation) control tasks into the current month and close the superseded ones';

    public function handle(ControlTaskService $service): int
    {
        $asOf = $this->option('as-of') ? CarbonImmutable::parse($this->option('as-of')) : CarbonImmutable::now();

        $totals = $service->rollContinuous($asOf, (bool) $this->option('dry-run'));

        $this->info(sprintf(
            '%s %d observation task(s); closed %d superseded.',
            $this->option('dry-run') ? 'Would open' : 'Opened',
            $totals['opened'], $totals['closed'],
        ));

        return self::SUCCESS;
    }
}
