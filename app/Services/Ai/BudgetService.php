<?php

namespace App\Services\Ai;

use App\Models\AiBudget;
use App\Models\AiConfiguration;
use App\Models\AiInteraction;
use App\Services\Ai\Exceptions\BudgetExceededException;
use App\Services\Ai\Exceptions\RateLimitedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The cost ceiling and the throttle (Part C §C.1.5, §C.4 steps 3 and 4).
 *
 * Both run before context is assembled, so a tenant at its limit spends
 * nothing at all — not a retrieval query, not a redaction pass, and
 * certainly not a request.
 */
class BudgetService
{
    /**
     * Throws when the period budget is spent and hard_stop is on.
     *
     * A soft budget records the overrun and lets the call through, which is
     * a legitimate choice for a tenant that would rather explain a bill than
     * a blocked filing — but it is a choice someone has to make, and the
     * default is hard.
     */
    public function assertWithinBudget(int $tenantId, string $capabilityKey): AiBudget
    {
        $budget = $this->current($tenantId);

        if ($budget->isExhausted() && $budget->hard_stop) {
            throw new BudgetExceededException(sprintf(
                'The AI budget for %s is exhausted (%s%% used). Ask an administrator to raise the limit for this period.',
                $budget->period_label,
                number_format($budget->percentUsed(), 1),
            ));
        }

        $this->assertWithinCapabilityBudget($tenantId, $capabilityKey, $budget);

        return $budget;
    }

    /**
     * Per-capability ceiling, where a tenant has set one. Prevents a single
     * expensive capability — a reasoning-model framework mapping run across
     * a whole standard — from consuming the month on its own.
     */
    private function assertWithinCapabilityBudget(int $tenantId, string $capabilityKey, AiBudget $budget): void
    {
        $cap = AiConfiguration::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('capability_key', $capabilityKey)
            ->value('monthly_token_budget');

        if (! $cap) {
            return;
        }

        $used = (int) AiInteraction::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('capability_key', $capabilityKey)
            ->whereBetween('created_at', [$budget->period_start->startOfDay(), $budget->period_end->endOfDay()])
            ->sum(DB::raw('input_tokens + output_tokens'));

        if ($used >= $cap) {
            throw new BudgetExceededException(sprintf(
                'The token budget for this capability (%s) is exhausted for %s.',
                $capabilityKey,
                $budget->period_label,
            ));
        }
    }

    /**
     * Per-user, per-tenant and per-capability throttles.
     *
     * Deliberately hit in that order. The narrowest limit is the one most
     * likely to be a person clicking Assist repeatedly, and telling them
     * that is more useful than telling them the tenant is busy.
     */
    public function assertWithinRateLimit(int $tenantId, int $userId, string $capabilityKey): void
    {
        $limits = [
            ["ai:user:{$userId}", (int) config('ai.rate_limits.per_user', 30),
                'You have made too many AI requests in the last minute. Please wait a moment.'],
            ["ai:cap:{$userId}:{$capabilityKey}", CapabilityRegistry::rateLimit($capabilityKey),
                'You have used this assistant too many times in the last minute. Please wait a moment.'],
            ["ai:tenant:{$tenantId}", (int) config('ai.rate_limits.per_tenant', 120),
                'Your organisation has reached its AI request limit for this minute. Please try again shortly.'],
        ];

        foreach ($limits as [$key, $max, $message]) {
            if ($max <= 0) {
                continue;
            }

            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new RateLimitedException($message, RateLimiter::availableIn($key));
            }
        }

        // Only counted once every limit has passed, so a request refused by
        // the tenant limit does not also consume the user's allowance.
        foreach ($limits as [$key, $max]) {
            if ($max > 0) {
                RateLimiter::hit($key, 60);
            }
        }
    }

    /**
     * Record what a call actually cost. Called for every interaction that
     * reached the provider, successful or not — a request that returned
     * malformed JSON still consumed tokens and still has to be paid for.
     */
    public function recordUsage(int $tenantId, int $tokens, int $costMinor): void
    {
        $budget = $this->current($tenantId);

        $budget->increment('tokens_used', max(0, $tokens));
        $budget->increment('cost_used_minor', max(0, $costMinor));

        $this->recordThresholdCrossings($budget->refresh());
    }

    /**
     * Cost of one call in minor units of the pricing currency.
     *
     * Integer minor units throughout (R7): a float here compounds across
     * thousands of calls into a figure that does not reconcile with the
     * provider's invoice. Rounded up, so the platform never under-reports
     * what a tenant has spent.
     */
    public function costMinor(string $model, int $inputTokens, int $outputTokens): int
    {
        $pricing = (array) config("services.ollama.pricing.{$model}", []);

        if ($pricing === []) {
            return 0;
        }

        $dollars = ($inputTokens / 1_000_000) * (float) ($pricing['input'] ?? 0)
            + ($outputTokens / 1_000_000) * (float) ($pricing['output'] ?? 0);

        return (int) ceil($dollars * 100);
    }

    /**
     * The budget row for the current month, created from configuration on
     * first use so a tenant is never blocked by a missing row.
     */
    public function current(int $tenantId, ?string $periodLabel = null): AiBudget
    {
        $periodLabel ??= now()->format('Y-m');

        return AiBudget::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId, 'period_label' => $periodLabel],
            [
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'token_budget' => (int) config('ai.budget.default_monthly_tokens'),
                'cost_budget_minor' => (int) config('ai.budget.default_monthly_cost_minor'),
                'currency' => (string) config('ai.budget.currency', 'USD'),
                'hard_stop' => (bool) config('ai.budget.hard_stop', true),
                'alert_thresholds' => (array) config('ai.budget.alert_thresholds', []),
                'alerted_at' => [],
            ],
        );
    }

    /**
     * Stamp each alert threshold the first time it is crossed. Recording it
     * on the budget rather than firing a notification here keeps this method
     * safe to call inside the request path; the governance page and the
     * scheduled digest both read these stamps.
     */
    private function recordThresholdCrossings(AiBudget $budget): void
    {
        $percent = $budget->percentUsed();
        $alerted = (array) ($budget->alerted_at ?? []);
        $changed = false;

        foreach ((array) ($budget->alert_thresholds ?? []) as $threshold) {
            $key = (string) $threshold;

            if ($percent >= (float) $threshold && ! isset($alerted[$key])) {
                $alerted[$key] = now()->toIso8601String();
                $changed = true;
            }
        }

        if ($changed) {
            $budget->update(['alerted_at' => $alerted]);
        }
    }
}
