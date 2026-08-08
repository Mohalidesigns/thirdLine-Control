<?php

namespace App\Services;

use App\Models\ControlException;
use App\Models\CsaCampaign;
use App\Models\EscalationEvent;
use App\Models\EscalationMatrix;
use App\Models\TestInstance;
use App\Models\User;
use App\Notifications\EscalationNotification;
use Illuminate\Support\Facades\Log;

class EscalationService
{
    /**
     * Evaluate every active matrix rule against open exceptions and overdue
     * tests (FR-8.2). Escalation is suspended for Remediated exceptions
     * (FR-8.8) and each (target, rule) pair fires once.
     */
    public function run(): int
    {
        $fired = 0;

        EscalationMatrix::withoutGlobalScopes()
            ->where('is_active', true)
            ->orderBy('tier_no')
            ->get()
            ->groupBy('tenant_id')
            ->each(function ($rules, $tenantId) use (&$fired) {
                foreach ($rules as $rule) {
                    $fired += match ($rule->trigger_condition) {
                        'exception_unassigned' => $this->escalateExceptions($rule, fn ($q) => $q
                            ->where('status', 'Open')
                            ->whereNull('responsible_party_id')
                            ->whereDate('date_raised', '<=', now()->subDays($rule->days_threshold))),
                        'exception_overdue' => $this->escalateExceptions($rule, fn ($q) => $q
                            ->where('is_overdue', true)
                            ->whereNotIn('status', ['Remediated'])),
                        'exception_inactive' => $this->escalateExceptions($rule, fn ($q) => $q
                            ->whereNotIn('status', ['Remediated'])
                            ->where('updated_at', '<=', now()->subDays($rule->days_threshold))),
                        'test_overdue' => $this->escalateOverdueTests($rule),
                        'attestation_overdue' => $this->escalateOverdueAttestations($rule),
                        default => 0,
                    };
                }
            });

        return $fired;
    }

    private function escalateExceptions(EscalationMatrix $rule, callable $constraint): int
    {
        $count = 0;

        $query = ControlException::withoutGlobalScopes()
            ->where('tenant_id', $rule->tenant_id)
            ->whereIn('status', ControlException::OPEN_STATUSES)
            ->where('severity', $rule->severity);

        $constraint($query);

        $query->each(function (ControlException $exception) use ($rule, &$count) {
            $already = EscalationEvent::withoutGlobalScopes()
                ->where('exception_id', $exception->id)
                ->where('matrix_id', $rule->id)
                ->exists();

            if ($already) {
                return;
            }

            $recipient = $this->resolveRecipient($rule, $exception);
            $this->fire($rule, $recipient, exception: $exception);
            $count++;
        });

        return $count;
    }

    private function escalateOverdueTests(EscalationMatrix $rule): int
    {
        $count = 0;

        TestInstance::withoutGlobalScopes()
            ->where('tenant_id', $rule->tenant_id)
            ->overdue()
            ->whereDate('due_date', '<=', now()->subDays($rule->days_threshold))
            ->each(function (TestInstance $instance) use ($rule, &$count) {
                $already = EscalationEvent::withoutGlobalScopes()
                    ->where('test_instance_id', $instance->id)
                    ->where('matrix_id', $rule->id)
                    ->exists();

                if ($already) {
                    return;
                }

                $recipient = $rule->recipient_user_id
                    ? User::find($rule->recipient_user_id)
                    : ($instance->tester ?? $instance->control?->owner);

                $this->fire($rule, $recipient, testInstance: $instance);
                $count++;
            });

        return $count;
    }

    /**
     * Overdue attestations (9.5): every user still outstanding on an
     * attestation campaign past its close date + threshold is escalated —
     * to themselves at tier 1 (a reminder) or up the recipient_role chain.
     */
    private function escalateOverdueAttestations(EscalationMatrix $rule): int
    {
        $count = 0;

        $campaigns = CsaCampaign::withoutGlobalScopes()
            ->where('tenant_id', $rule->tenant_id)
            ->where('campaign_type', 'attestation')
            ->whereIn('status', ['Open', 'Closed'])
            ->whereNotNull('closes_at')
            ->whereDate('closes_at', '<=', now()->subDays($rule->days_threshold))
            ->get();

        foreach ($campaigns as $campaign) {
            foreach (app(AttestationService::class)->outstanding($campaign) as $outstanding) {
                $subjectId = $outstanding['user']['id'] ?? null;

                if (! $subjectId) {
                    continue;
                }

                $already = EscalationEvent::withoutGlobalScopes()
                    ->where('campaign_id', $campaign->id)
                    ->where('subject_user_id', $subjectId)
                    ->where('matrix_id', $rule->id)
                    ->exists();

                if ($already) {
                    continue;
                }

                $subject = User::find($subjectId);

                $recipient = match ($rule->recipient_role) {
                    'Self' => $subject,
                    'Line Manager' => $subject?->manager,
                    default => $rule->recipient_user_id
                        ? User::find($rule->recipient_user_id)
                        : User::withoutGlobalScopes()
                            ->where('tenant_id', $rule->tenant_id)
                            ->role($rule->recipient_role)
                            ->first(),
                };

                $this->fire($rule, $recipient, campaign: $campaign, subject: $subject);
                $count++;
            }
        }

        return $count;
    }

    private function resolveRecipient(EscalationMatrix $rule, ControlException $exception): ?User
    {
        if ($rule->recipient_user_id) {
            return User::find($rule->recipient_user_id);
        }

        return match ($rule->recipient_role) {
            'Control Owner' => $exception->owner,
            'Line Manager' => $exception->owner?->manager ?? $exception->unit?->head,
            default => User::withoutGlobalScopes()
                ->where('tenant_id', $rule->tenant_id)
                ->role($rule->recipient_role)
                ->first(),
        };
    }

    private function fire(
        EscalationMatrix $rule,
        ?User $recipient,
        ?ControlException $exception = null,
        ?TestInstance $testInstance = null,
        ?CsaCampaign $campaign = null,
        ?User $subject = null,
    ): void {
        $summary = match (true) {
            $exception !== null => "{$exception->reference} [{$exception->severity}] {$exception->title}",
            $testInstance !== null => "{$testInstance->reference} overdue test — {$testInstance->control?->title}",
            default => "{$campaign->reference} attestation outstanding — {$subject?->name}",
        };

        $event = EscalationEvent::create([
            'tenant_id' => $rule->tenant_id,
            'exception_id' => $exception?->id,
            'test_instance_id' => $testInstance?->id,
            'campaign_id' => $campaign?->id,
            'subject_user_id' => $subject?->id,
            'matrix_id' => $rule->id,
            'tier_no' => $rule->tier_no,
            'recipient_user_id' => $recipient?->id,
            'channel' => $rule->channel,
            'triggered_at' => now(),
            'delivery_status' => 'Pending',
            'payload_summary' => str($summary)->limit(490),
        ]);

        if (! $recipient) {
            $event->update(['delivery_status' => 'Failed']);

            return;
        }

        try {
            app(NotificationDispatcher::class)->send($recipient, 'escalation.raised', new EscalationNotification($event));
            $event->update(['delivery_status' => 'Sent']);
            $exception?->logActivity('Escalation', null, "Tier {$rule->tier_no}: {$recipient->name}");
        } catch (\Throwable $e) {
            Log::error('Escalation delivery failed', ['event' => $event->id, 'error' => $e->getMessage()]);
            $event->update(['delivery_status' => 'Failed']);
        }
    }
}
