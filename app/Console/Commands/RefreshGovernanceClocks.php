<?php

namespace App\Console\Commands;

use App\Models\Complaint;
use App\Models\Incident;
use App\Models\IncidentNotification;
use App\Models\Policy;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\GovernanceClockNotification;
use App\Services\ComplaintService;
use App\Services\NotificationDispatcher;
use App\Services\NotificationObligationResolver;
use App\Services\PolicyService;
use Illuminate\Console\Command;

/**
 * The Phase 11 clock sweep. Everything that ticks in policy, incident and
 * complaint handling is recomputed here rather than being trusted to have
 * been maintained by whoever last touched a screen:
 *
 *  • complaint SLA state and live penalty exposure (11.3);
 *  • incident notification windows, recomputed from the obligation records
 *    so an edited obligation moves every open countdown (11.2, R1);
 *  • policy reviews falling due and waivers past their end date (11.1).
 */
class RefreshGovernanceClocks extends Command
{
    protected $signature = 'atheris:refresh-governance-clocks {--tenant= : Limit to one tenant id}';

    protected $description = 'Refresh complaint SLA clocks and penalty exposure, incident notification windows, policy reviews and expired waivers';

    public function handle(
        ComplaintService $complaints,
        PolicyService $policies,
        NotificationObligationResolver $resolver,
        NotificationDispatcher $dispatcher,
    ): int {
        $tenantIds = $this->option('tenant')
            ? [(int) $this->option('tenant')]
            : Tenant::pluck('id')->all();

        $totals = ['complaints' => 0, 'incidents' => 0, 'waivers' => 0, 'reviews' => 0, 'alerts' => 0];

        foreach ($tenantIds as $tenantId) {
            $totals['complaints'] += $complaints->refreshAll($tenantId);
            $totals['incidents'] += $this->refreshIncidentNotifications($tenantId, $resolver);
            $totals['waivers'] += $policies->expireWaivers($tenantId);
            $totals['reviews'] += $this->notifyPolicyReviews($tenantId, $dispatcher);
            $totals['alerts'] += $this->alertOnClosingWindows($tenantId, $dispatcher);
        }

        $this->info(sprintf(
            '%d complaint(s) refreshed, %d incident notification set(s) recomputed, %d waiver(s) expired, '
            .'%d policy review reminder(s) and %d closing-window alert(s) sent.',
            $totals['complaints'], $totals['incidents'], $totals['waivers'], $totals['reviews'], $totals['alerts'],
        ));

        return self::SUCCESS;
    }

    private function refreshIncidentNotifications(int $tenantId, NotificationObligationResolver $resolver): int
    {
        $count = 0;

        Incident::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', Incident::OPEN_STATUSES)
            ->chunkById(100, function ($incidents) use ($resolver, &$count) {
                foreach ($incidents as $incident) {
                    $resolver->resolve($incident);
                    $count++;
                }
            });

        return $count;
    }

    private function notifyPolicyReviews(int $tenantId, NotificationDispatcher $dispatcher): int
    {
        $sent = 0;

        Policy::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->dueForReview(30)
            ->with('owner')
            ->each(function (Policy $policy) use ($dispatcher, &$sent) {
                if (! $policy->owner) {
                    return;
                }

                $dispatcher->send($policy->owner, 'policy.review-due', new GovernanceClockNotification(
                    'Policy review due',
                    "{$policy->policy_ref} — {$policy->title} is due for review on "
                        .$policy->next_review_at?->toFormattedDateString().'.',
                    route('policies.show', $policy),
                ));

                $sent++;
            });

        return $sent;
    }

    /**
     * Warn before a window closes, not after. Recipients are the incident
     * owner and the complaint handler — the people who can still act.
     */
    private function alertOnClosingWindows(int $tenantId, NotificationDispatcher $dispatcher): int
    {
        $sent = 0;

        IncidentNotification::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->outstanding()
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now()->addDay())
            ->with(['incident.owner', 'regulator'])
            ->each(function (IncidentNotification $notification) use ($dispatcher, &$sent) {
                $recipient = $notification->incident?->owner ?? $notification->incident?->investigator;

                if (! $recipient) {
                    return;
                }

                $dispatcher->send($recipient, 'incident.notification-due', new GovernanceClockNotification(
                    'Regulatory notification window closing',
                    ($notification->regulator?->name ?? 'A regulator').' must be notified about '
                        .$notification->incident->incident_ref.' by '.$notification->due_at->toDayDateTimeString().'.',
                    route('incidents.show', $notification->incident),
                ));

                $sent++;
            });

        Complaint::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->unacknowledged()
            ->whereNotNull('acknowledgement_due_at')
            ->where('acknowledgement_due_at', '<=', now()->addHours(6))
            ->with('assignee')
            ->each(function (Complaint $complaint) use ($dispatcher, &$sent) {
                $recipient = $complaint->assignee ?? $this->complaintsLead($complaint->tenant_id);

                if (! $recipient) {
                    return;
                }

                $dispatcher->send($recipient, 'complaint.acknowledgement-due', new GovernanceClockNotification(
                    'Complaint acknowledgement window closing',
                    "{$complaint->complaint_ref} ({$complaint->tracking_id}) must be acknowledged by "
                        .$complaint->acknowledgement_due_at->toDayDateTimeString().'.',
                    route('complaints.show', $complaint),
                ));

                $sent++;
            });

        return $sent;
    }

    private function complaintsLead(int $tenantId): ?User
    {
        return User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->role('Control Function Head')
            ->first();
    }
}
