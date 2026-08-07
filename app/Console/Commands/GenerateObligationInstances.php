<?php

namespace App\Console\Commands;

use App\Models\ObligationAssignment;
use App\Models\ObligationInstance;
use App\Notifications\ObligationReminderNotification;
use App\Services\NotificationDispatcher;
use App\Services\ObligationService;
use Illuminate\Console\Command;

/**
 * The compliance calendar's heartbeat. Idempotent: safe to run repeatedly,
 * creates no duplicate periods, corrects due dates that have moved, and
 * fires each graduated reminder at most once per instance.
 */
class GenerateObligationInstances extends Command
{
    protected $signature = 'atheris:generate-obligation-instances
        {--horizon=400 : Rolling horizon in days}
        {--tenant= : Restrict to a single tenant id}
        {--skip-reminders : Generate and refresh without dispatching reminders}';

    protected $description = 'Generate upcoming regulatory obligation instances, refresh overdue status and penalty exposure, and send graduated reminders (idempotent, run daily)';

    public function handle(ObligationService $obligations, NotificationDispatcher $dispatcher): int
    {
        $horizon = max(1, (int) $this->option('horizon'));
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;

        $created = 0;

        ObligationAssignment::withoutGlobalScope('tenant')
            ->when($tenantId, fn ($q, $t) => $q->where('tenant_id', $t))
            ->with(['obligation.regulator', 'entity.tenant'])
            ->chunkById(200, function ($assignments) use ($obligations, $horizon, &$created) {
                foreach ($assignments as $assignment) {
                    $created += $obligations->generateInstances($assignment, $horizon);
                }
            });

        $refreshed = 0;

        ObligationInstance::withoutGlobalScope('tenant')
            ->when($tenantId, fn ($q, $t) => $q->where('tenant_id', $t))
            ->open()
            ->with(['obligation'])
            ->chunkById(200, function ($instances) use ($obligations, &$refreshed) {
                foreach ($instances as $instance) {
                    $obligations->refreshInstance($instance);
                    $refreshed++;
                }
            });

        $reminders = $this->option('skip-reminders') ? 0 : $this->sendReminders($dispatcher, $tenantId);

        $this->info("Created {$created} obligation instance(s), refreshed {$refreshed}, sent {$reminders} reminder(s).");

        return self::SUCCESS;
    }

    /**
     * Reminders at T-90/-60/-30/-14/-7/-3/-1/0 and once per day overdue,
     * each recorded on the instance so a re-run never repeats one.
     */
    private function sendReminders(NotificationDispatcher $dispatcher, ?int $tenantId): int
    {
        $sent = 0;

        ObligationInstance::withoutGlobalScope('tenant')
            ->when($tenantId, fn ($q, $t) => $q->where('tenant_id', $t))
            ->open()
            ->with(['obligation.regulator', 'assignment.owner', 'assignment.reviewer'])
            ->chunkById(200, function ($instances) use ($dispatcher, &$sent) {
                foreach ($instances as $instance) {
                    $sent += $this->remindFor($dispatcher, $instance) ? 1 : 0;
                }
            });

        return $sent;
    }

    private function remindFor(NotificationDispatcher $dispatcher, ObligationInstance $instance): bool
    {
        $owner = $instance->assignment?->owner;

        if (! $owner) {
            return false;
        }

        $daysRemaining = (int) floor(now()->diffInDays($instance->due_at, false));
        $alreadySent = $instance->reminders_sent ?? [];

        if ($daysRemaining >= 0) {
            $bucket = $this->reminderBucket($daysRemaining);

            if ($bucket === null) {
                return false;
            }

            $marker = 'T-'.$bucket;
        } else {
            // One overdue notice per day, not one per run.
            $marker = 'overdue:'.now()->toDateString();
        }

        if (in_array($marker, $alreadySent, true)) {
            return false;
        }

        $recipients = collect([$owner, $instance->assignment?->reviewer])->filter()->unique('id');

        $dispatcher->send(
            $recipients,
            $daysRemaining < 0 ? 'obligation.overdue' : 'obligation.reminder',
            new ObligationReminderNotification($instance, $daysRemaining),
        );

        $instance->forceFill(['reminders_sent' => [...$alreadySent, $marker]])->save();

        return true;
    }

    /** The reminder offset this instance has just crossed, or null. */
    private function reminderBucket(int $daysRemaining): ?int
    {
        return in_array($daysRemaining, ObligationInstance::REMINDER_OFFSETS, true) ? $daysRemaining : null;
    }
}
