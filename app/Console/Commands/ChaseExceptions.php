<?php

namespace App\Console\Commands;

use App\Models\ExceptionEscalation;
use App\Models\Tenant;
use App\Notifications\ExceptionAcknowledgementDueNotification;
use App\Notifications\ExceptionResponseOverdueNotification;
use App\Notifications\ExceptionResponseReminderNotification;
use App\Services\ExceptionEscalationService;
use App\Services\ExceptionSlaCalculator;
use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * CR1.6 — the chase engine. Daily and IDEMPOTENT: a second run the same day
 * sends nothing, because each escalation records the tenant-local day of its
 * last reminder set. Reminder days come from the SLA policy's business-day
 * reminder_offsets (R1); past escalate_after_days the escalation is stamped
 * for the FR-8 tier ladder, which EscalationService walks on its own run.
 */
class ChaseExceptions extends Command
{
    protected $signature = 'exceptions:chase';

    protected $description = 'Send acknowledgement/response reminders per SLA policy and hand overdue escalations to the escalation matrix';

    public function handle(
        ExceptionSlaCalculator $slaCalculator,
        ExceptionEscalationService $escalationService,
        NotificationDispatcher $dispatcher,
    ): int {
        $reminders = 0;
        $overdue = 0;
        $handedToMatrix = 0;

        // Recipient lookups run outside a request (R-I): tenant scoping is
        // explicit, the way EscalationService already does it.
        Tenant::query()->each(function (Tenant $tenant) use (
            $slaCalculator, $escalationService, $dispatcher, &$reminders, &$overdue, &$handedToMatrix
        ) {
            $today = $slaCalculator->todayFor($tenant);
            $touchedExceptions = [];

            ExceptionEscalation::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->awaitingResponse()
                ->whereNotNull('response_due_at')
                ->with(['slaPolicy', 'respondent', 'exception'])
                ->each(function (ExceptionEscalation $escalation) use (
                    $tenant, $today, $slaCalculator, $dispatcher, &$reminders, &$overdue, &$handedToMatrix, &$touchedExceptions
                ) {
                    $policy = $escalation->slaPolicy;

                    if (! $policy) {
                        return;
                    }

                    $timezone = $slaCalculator->timezone($tenant);
                    $isOverdue = now()->greaterThan($escalation->response_due_at);

                    // ── Reminder set for today (idempotent per tenant-day) ──
                    $alreadyToday = $escalation->last_reminder_at
                        && $escalation->last_reminder_at->copy()->setTimezone($timezone)->isSameDay($today);

                    $isReminderDay = $this->isReminderDay($escalation, $policy->reminder_offsets ?? [], $today, $tenant, $slaCalculator);

                    if ($isReminderDay && ! $alreadyToday && $escalation->reminder_count < $policy->max_reminders) {
                        $notification = $escalation->acknowledged_at
                            ? new ExceptionResponseReminderNotification($escalation)
                            : new ExceptionAcknowledgementDueNotification($escalation);

                        $eventKey = $escalation->acknowledged_at
                            ? 'exception.response.reminder'
                            : 'exception.escalation.acknowledgement_due';

                        if ($escalation->respondent) {
                            $dispatcher->send($escalation->respondent, $eventKey, $notification);
                        } elseif ($escalation->external_email) {
                            $dispatcher->sendExternal($escalation->external_email, $eventKey, $notification);
                        }

                        $escalation->update([
                            'reminder_count' => $escalation->reminder_count + 1,
                            'last_reminder_at' => now(),
                        ]);

                        $escalation->exception->logActivity(
                            'Reminder Sent',
                            null,
                            $escalation->targetLabel(),
                            "Reminder {$escalation->reminder_count} of {$policy->max_reminders} ({$escalation->reference})",
                        );

                        $reminders++;
                    }

                    // ── Overdue marking ────────────────────────────────────
                    if ($isOverdue && ! $escalation->is_response_late) {
                        $escalation->update(['is_response_late' => true]);

                        if ($escalation->respondent) {
                            $dispatcher->send($escalation->respondent, 'exception.response.overdue', new ExceptionResponseOverdueNotification($escalation));
                        } elseif ($escalation->external_email) {
                            $dispatcher->sendExternal($escalation->external_email, 'exception.response.overdue', new ExceptionResponseOverdueNotification($escalation));
                        }

                        $overdue++;
                    }

                    // ── Hand-off to the FR-8 tier ladder ──────────────────
                    if ($policy->auto_escalate_to_matrix
                        && ! $escalation->matrix_escalated_at
                        && $isOverdue
                        && $today->greaterThanOrEqualTo(
                            $slaCalculator->addBusinessDays(
                                $escalation->response_due_at->copy()->setTimezone($timezone)->startOfDay(),
                                $policy->escalate_after_days,
                                $tenant,
                            )
                        )) {
                        $escalation->update(['matrix_escalated_at' => now()]);
                        $handedToMatrix++;
                    }

                    if ($isOverdue) {
                        $touchedExceptions[$escalation->exception_id] = $escalation->exception;
                    }
                });

            foreach ($touchedExceptions as $exception) {
                $exception->updateQuietly(['is_response_overdue' => true]);
                $escalationService->refreshExceptionCache($exception->fresh());
            }
        });

        $this->info("Chase complete: {$reminders} reminder(s), {$overdue} newly overdue, {$handedToMatrix} handed to the escalation matrix.");

        return self::SUCCESS;
    }

    /**
     * Is the tenant-local today one of the policy's business-day offsets
     * around this escalation's response due date?
     *
     * @param  array<int, int>  $offsets
     */
    private function isReminderDay(
        ExceptionEscalation $escalation,
        array $offsets,
        Carbon $today,
        Tenant $tenant,
        ExceptionSlaCalculator $slaCalculator,
    ): bool {
        $dueLocal = $escalation->response_due_at
            ->copy()
            ->setTimezone($slaCalculator->timezone($tenant))
            ->startOfDay();

        foreach ($offsets as $offset) {
            if ($slaCalculator->addBusinessDays($dueLocal, (int) $offset, $tenant)->isSameDay($today)) {
                return true;
            }
        }

        return false;
    }
}
