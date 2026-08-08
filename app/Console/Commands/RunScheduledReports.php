<?php

namespace App\Console\Commands;

use App\Models\ReportSchedule;
use App\Services\ReportDesignerService;
use App\Support\CronSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunScheduledReports extends Command
{
    protected $signature = 'reports:run-scheduled
        {--tenant= : Limit to one tenant}
        {--schedule= : Run one schedule by id, regardless of its next run time}';

    protected $description = 'Generate and distribute every report schedule that has fallen due (13.3)';

    public function handle(ReportDesignerService $designer): int
    {
        $schedules = ReportSchedule::query()
            ->withoutGlobalScope('tenant')
            ->when($this->option('tenant'), fn ($q, $tenant) => $q->where('tenant_id', $tenant))
            ->when(
                $this->option('schedule'),
                fn ($q, $id) => $q->whereKey($id),
                fn ($q) => $q->due(),
            )
            ->with(['definition', 'creator'])
            ->get();

        if ($schedules->isEmpty()) {
            $this->info('No report schedules are due.');

            return self::SUCCESS;
        }

        $generated = 0;
        $failed = 0;

        foreach ($schedules as $schedule) {
            // The schedule's creator is who the report runs *as*: a scheduled
            // report must not see more than the person who scheduled it, and
            // running as a privileged system identity would do exactly that.
            $actor = $schedule->creator;

            if (! $actor || ! $schedule->definition) {
                $this->warn("Skipping '{$schedule->name}' — no creator or definition to run it as.");
                $this->markRun($schedule, 'Skipped');

                continue;
            }

            try {
                $run = $designer->run(
                    $schedule->definition,
                    $actor,
                    $schedule->output_format,
                    $schedule->parameters ?? [],
                    $schedule,
                );

                $delivered = $designer->distribute($run, $schedule);

                $this->markRun($schedule, $run->status);

                $run->status === 'Completed' ? $generated++ : $failed++;

                $this->line(sprintf(
                    '  %s — %s (%d recipient(s))',
                    $schedule->name,
                    $run->status,
                    count($delivered),
                ));
            } catch (\Throwable $e) {
                $failed++;
                $this->markRun($schedule, 'Failed');

                Log::error('Scheduled report failed', [
                    'schedule' => $schedule->id,
                    'error' => $e->getMessage(),
                ]);

                $this->error("  {$schedule->name} — {$e->getMessage()}");
            }
        }

        $this->info("{$generated} report(s) generated, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function markRun(ReportSchedule $schedule, string $status): void
    {
        $schedule->forceFill([
            'last_run_at' => now(),
            'last_status' => $status,
            'next_run_at' => CronSchedule::nextRun($schedule->cron, $schedule->timezone),
        ])->save();
    }
}
