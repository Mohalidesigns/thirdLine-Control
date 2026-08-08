<?php

namespace App\Services;

use App\Models\ControlException;
use App\Models\CsaCampaign;
use App\Models\EscalationEvent;
use App\Models\EscalationMatrix;
use App\Models\Metric;
use App\Models\MetricBreach;
use App\Models\Risk;
use App\Models\RiskAppetite;
use App\Models\RiskTreatment;
use App\Models\TestInstance;
use App\Models\User;
use App\Notifications\EscalationNotification;
use Illuminate\Support\Collection;
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
                        'treatment_overdue' => $this->escalateOverdueTreatments($rule),
                        'appetite_breach' => $this->escalateStandingAppetiteBreaches($rule),
                        'kri_breach' => $this->escalateStandingMetricBreaches($rule),
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

    // ── Phase 10: risk appetite, KRI breaches and treatment plans ────

    /**
     * Fired the moment a risk crosses its tolerance (10.3) rather than
     * waiting for the nightly sweep — a board-level breach should not sit
     * unannounced for up to 24 hours. Each (risk, rule) pair fires once.
     */
    public function escalateAppetiteBreach(Risk $risk, ?RiskAppetite $appetite = null): int
    {
        $severity = $this->severityForBand($risk->current_rating_band);
        $count = 0;

        foreach ($this->rulesFor($risk->tenant_id, 'appetite_breach', $severity) as $rule) {
            if ($this->alreadyFired($rule, ['risk_id' => $risk->id])) {
                continue;
            }

            $this->fire($rule, $this->roleRecipient($rule, $risk->owner), risk: $risk, appetite: $appetite);
            $count++;
        }

        return $count;
    }

    /** A Red or Critical KRI reading escalates immediately (10.5). */
    public function escalateMetricBreach(MetricBreach $breach): int
    {
        $metric = $breach->metric()->withoutGlobalScopes()->first();
        $severity = match ($breach->level) {
            'Critical' => 'Critical',
            'Red' => 'High',
            default => 'Medium',
        };

        $count = 0;

        foreach ($this->rulesFor($breach->tenant_id, 'kri_breach', $severity) as $rule) {
            if ($this->alreadyFired($rule, ['metric_id' => $breach->metric_id])) {
                continue;
            }

            $event = $this->fire($rule, $this->roleRecipient($rule, $metric?->owner), metric: $metric, breach: $breach);
            $breach->update(['escalation_event_id' => $event?->id]);
            $count++;
        }

        return $count;
    }

    /** Nightly sweep for breaches that were still standing at the threshold. */
    private function escalateStandingAppetiteBreaches(EscalationMatrix $rule): int
    {
        $count = 0;

        Risk::withoutGlobalScopes()
            ->where('tenant_id', $rule->tenant_id)
            ->where('status', 'active')
            ->where('appetite_breached', true)
            ->whereNotNull('appetite_breach_at')
            ->where('appetite_breach_at', '<=', now()->subDays($rule->days_threshold))
            ->each(function (Risk $risk) use ($rule, &$count) {
                if ($this->severityForBand($risk->current_rating_band) !== $rule->severity
                    || $this->alreadyFired($rule, ['risk_id' => $risk->id])) {
                    return;
                }

                $this->fire($rule, $this->roleRecipient($rule, $risk->owner), risk: $risk);
                $count++;
            });

        return $count;
    }

    private function escalateStandingMetricBreaches(EscalationMatrix $rule): int
    {
        $count = 0;

        MetricBreach::withoutGlobalScopes()
            ->where('tenant_id', $rule->tenant_id)
            ->unresolved()
            ->where('detected_at', '<=', now()->subDays($rule->days_threshold))
            ->with('metric')
            ->each(function (MetricBreach $breach) use ($rule, &$count) {
                if ($this->alreadyFired($rule, ['metric_id' => $breach->metric_id])) {
                    return;
                }

                $this->fire($rule, $this->roleRecipient($rule, $breach->metric?->owner), metric: $breach->metric, breach: $breach);
                $count++;
            });

        return $count;
    }

    /**
     * Treatment alerting (10.4) — configurable per treatment: an owner can
     * mute alerts on a plan, and the lead time before the due date is a
     * per-treatment field, not a constant.
     */
    private function escalateOverdueTreatments(EscalationMatrix $rule): int
    {
        $count = 0;

        RiskTreatment::withoutGlobalScopes()
            ->where('tenant_id', $rule->tenant_id)
            ->where('alerts_enabled', true)
            ->open()
            ->whereNotNull('due_at')
            ->whereDate('due_at', '<=', now()->subDays($rule->days_threshold))
            ->with(['risk', 'owner'])
            ->each(function (RiskTreatment $treatment) use ($rule, &$count) {
                if ($this->severityForBand($treatment->risk?->current_rating_band) !== $rule->severity
                    || $this->alreadyFired($rule, ['treatment_id' => $treatment->id])) {
                    return;
                }

                $this->fire($rule, $this->roleRecipient($rule, $treatment->owner), treatment: $treatment);
                $treatment->updateQuietly(['last_alert_at' => now(), 'status' => 'Overdue']);
                $count++;
            });

        return $count;
    }

    /** @return Collection<int, EscalationMatrix> */
    private function rulesFor(int $tenantId, string $trigger, string $severity)
    {
        return EscalationMatrix::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('trigger_condition', $trigger)
            ->where('severity', $severity)
            ->orderBy('tier_no')
            ->get();
    }

    private function alreadyFired(EscalationMatrix $rule, array $subject): bool
    {
        return EscalationEvent::withoutGlobalScopes()
            ->where('matrix_id', $rule->id)
            ->where($subject)
            ->exists();
    }

    private function roleRecipient(EscalationMatrix $rule, ?User $fallback): ?User
    {
        if ($rule->recipient_user_id) {
            return User::find($rule->recipient_user_id);
        }

        return User::withoutGlobalScopes()
            ->where('tenant_id', $rule->tenant_id)
            ->role($rule->recipient_role)
            ->first() ?? $fallback;
    }

    /** Risk rating band → the matrix's severity vocabulary. */
    private function severityForBand(?string $band): string
    {
        return match ($band) {
            'Critical' => 'Critical',
            'High' => 'High',
            'Moderate' => 'Medium',
            'Low' => 'Low',
            default => 'Medium',
        };
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
        ?Risk $risk = null,
        ?RiskAppetite $appetite = null,
        ?Metric $metric = null,
        ?MetricBreach $breach = null,
        ?RiskTreatment $treatment = null,
    ): ?EscalationEvent {
        $summary = match (true) {
            $exception !== null => "{$exception->reference} [{$exception->severity}] {$exception->title}",
            $testInstance !== null => "{$testInstance->reference} overdue test — {$testInstance->control?->title}",
            $risk !== null => "{$risk->code} outside risk appetite — score {$risk->currentScore()}"
                .($appetite ? " vs tolerance {$appetite->tolerance_upper}" : ''),
            $metric !== null => "{$metric->code} {$breach?->level} breach — {$metric->name}",
            $treatment !== null => "{$treatment->reference} overdue treatment — {$treatment->title}",
            default => "{$campaign->reference} attestation outstanding — {$subject?->name}",
        };

        $event = EscalationEvent::create([
            'tenant_id' => $rule->tenant_id,
            'exception_id' => $exception?->id,
            'test_instance_id' => $testInstance?->id,
            'campaign_id' => $campaign?->id,
            'subject_user_id' => $subject?->id,
            'risk_id' => $risk?->id,
            'metric_id' => $metric?->id,
            'treatment_id' => $treatment?->id,
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

            return $event;
        }

        try {
            app(NotificationDispatcher::class)->send($recipient, 'escalation.raised', new EscalationNotification($event));
            $event->update(['delivery_status' => 'Sent']);
            $exception?->logActivity('Escalation', null, "Tier {$rule->tier_no}: {$recipient->name}");
        } catch (\Throwable $e) {
            Log::error('Escalation delivery failed', ['event' => $event->id, 'error' => $e->getMessage()]);
            $event->update(['delivery_status' => 'Failed']);
        }

        return $event;
    }
}
