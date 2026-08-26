<?php

namespace App\Console\Commands;

use App\Models\ConsequenceAction;
use App\Models\Investigation;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ConsequenceActionDueNotification;
use App\Notifications\InvestigationOverdueNotification;
use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CR-04 — the investigation chase.
 *
 * Two things are chased and neither of them is "the case is taking a
 * while": an investigation past the date it said it would finish, and an
 * approved consequence past the date it said it would be carried out. The
 * second matters more than it looks — a dismissal approved and never
 * effected is the gap a disciplinary appeal walks straight through.
 *
 * SUSPENDED cases are skipped. A case waiting six months on a police
 * report is not a case nobody is working on, and chasing its lead weekly
 * teaches everyone to ignore the chase (§H.5-6).
 *
 * Idempotent within a day: a second run sends nothing, because each send
 * is checked against the notifications already written today for that
 * record. The reminder then repeats weekly rather than daily — a daily
 * nag about a three-month investigation is noise, and noise is how a real
 * chase gets filtered to a folder.
 */
class ChaseInvestigations extends Command
{
    protected $signature = 'investigations:chase';

    protected $description = 'Remind leads of investigations past their target date, and owners of consequence actions falling due';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $overdue = 0;
        $consequences = 0;

        // Recipient lookups run outside a request, so tenant scoping is
        // explicit rather than inherited from an authenticated user.
        Tenant::query()->each(function (Tenant $tenant) use ($dispatcher, &$overdue, &$consequences) {
            $today = now()->startOfDay();

            Investigation::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('is_archived', false)
                ->whereIn('status', Investigation::OPEN_STATUSES)
                ->whereNotNull('target_completion_date')
                ->whereNotNull('lead_investigator_id')
                ->whereDate('target_completion_date', '<', $today->toDateString())
                ->with('leadInvestigator')
                ->each(function (Investigation $investigation) use ($dispatcher, $today, &$overdue) {
                    $daysOverdue = (int) $today->diffInDays($investigation->target_completion_date, absolute: true);

                    // Day 1, then every seventh day after it: 1, 8, 15, 22.
                    if ($daysOverdue < 1 || $daysOverdue % 7 !== 1) {
                        return;
                    }

                    $lead = $investigation->leadInvestigator;

                    if (! $lead || $this->alreadyNotifiedToday($lead, 'investigation_overdue', 'investigation_id', $investigation->id)) {
                        return;
                    }

                    $dispatcher->send($lead, 'investigation.overdue', new InvestigationOverdueNotification($investigation));
                    $overdue++;
                });

            ConsequenceAction::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->whereIn('status', ['approved', 'in_progress'])
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<=', $today->toDateString())
                ->with('investigation.leadInvestigator')
                ->each(function (ConsequenceAction $action) use ($dispatcher, &$consequences) {
                    $lead = $action->investigation?->leadInvestigator;

                    if (! $lead || $this->alreadyNotifiedToday($lead, 'investigation_consequence_due', 'consequence_action_id', $action->id)) {
                        return;
                    }

                    $dispatcher->send($lead, 'investigation.consequence-due', new ConsequenceActionDueNotification($action));
                    $consequences++;
                });
        });

        $this->info("Investigations chased: {$overdue}. Consequence actions chased: {$consequences}.");

        return self::SUCCESS;
    }

    /**
     * The idempotency check. Laravel's own notifications table is the
     * record of what was sent, so no extra column is needed to remember
     * it — and a record that already exists is the only honest evidence
     * that a reminder went out.
     */
    private function alreadyNotifiedToday(User $recipient, string $type, string $key, int $id): bool
    {
        return DB::table('notifications')
            ->where('notifiable_type', $recipient->getMorphClass())
            ->where('notifiable_id', $recipient->getKey())
            ->whereDate('created_at', now()->toDateString())
            ->where('data', 'like', '%"'.$type.'"%')
            ->where('data', 'like', '%"'.$key.'":'.$id.'%')
            ->exists();
    }
}
