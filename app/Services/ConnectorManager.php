<?php

namespace App\Services;

use App\Integrations\ConnectorException;
use App\Integrations\ConnectorRegistry;
use App\Integrations\Contracts\Connector;
use App\Models\DataSource;
use App\Models\IntegrationSyncLog;
use App\Models\User;
use App\Notifications\DataSourceFailedNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Everything that wraps a connector rather than being one (12.1):
 * resolution, the circuit breaker, per-source rate limiting, and the
 * sync-log entry that makes an extraction replayable.
 *
 * The existing integration_sync_logs table is reused rather than a
 * parallel one — a failed pull from Finacle and a failed push to
 * ThirdLine are the same kind of event and belong in the same register.
 */
class ConnectorManager
{
    public function connector(DataSource $source): Connector
    {
        return ConnectorRegistry::resolve($source);
    }

    /**
     * A live connection test. Never throws: a broken configuration is a
     * result to display, not an exception to swallow upstream.
     *
     * @return array{ok: bool, message: string, latency_ms: int}
     */
    public function test(DataSource $source): array
    {
        try {
            $result = $this->connector($source)->testConnection();
        } catch (\Throwable $e) {
            $result = [
                'ok' => false,
                'message' => ConnectorException::redactMessage($e->getMessage()),
                'latency_ms' => 0,
            ];
        }

        $this->log($source, 'connection_test', $result['ok'] ? 'Success' : 'Failed', [
            'latency_ms' => $result['latency_ms'],
        ], $result['ok'] ? null : $result['message']);

        $source->auditAction('connection_tested', null, ['ok' => $result['ok']]);

        return $result;
    }

    /**
     * The gate every extraction passes. A source that is paused, not
     * approved, or over its rate limit does not get to run.
     */
    public function assertExtractable(DataSource $source): void
    {
        if ($source->approved_at === null) {
            throw new ConnectorException("Data source '{$source->name}' has not been approved.");
        }

        if (! $source->is_active) {
            throw new ConnectorException("Data source '{$source->name}' is inactive.");
        }

        if ($source->paused_at !== null) {
            throw new ConnectorException(
                "Data source '{$source->name}' is paused by the circuit breaker: {$source->paused_reason}"
            );
        }

        if (! $this->withinRateLimit($source)) {
            throw new ConnectorException(
                "Data source '{$source->name}' has hit its rate limit of "
                ."{$source->rate_limit_per_minute} calls per minute."
            );
        }
    }

    /**
     * Fixed-window counter per source. Deliberately simple: the point is
     * to stop a runaway rule flooding a bank's core, not to be a precise
     * token bucket.
     */
    public function withinRateLimit(DataSource $source): bool
    {
        $limit = max(1, (int) $source->rate_limit_per_minute);
        $key = "ccm:rate:{$source->id}:".now()->format('YmdHi');
        $used = (int) Cache::get($key, 0);

        if ($used >= $limit) {
            return false;
        }

        Cache::put($key, $used + 1, now()->addMinutes(2));

        return true;
    }

    /** A clean extraction resets the breaker and marks the source healthy. */
    public function recordSuccess(DataSource $source, bool $partial = false): void
    {
        $source->update([
            'consecutive_failures' => 0,
            'last_sync_at' => now(),
            'last_sync_status' => $partial ? 'Partial' : 'Success',
            'health_status' => $partial ? 'Degraded' : 'Healthy',
            'paused_at' => null,
            'paused_reason' => null,
        ]);
    }

    /**
     * The circuit breaker (12.1). N consecutive failures — N being the
     * source's own failure_threshold, not a constant — pauses the source,
     * marks it Failed and notifies the owner. Nothing retries a dead
     * endpoint every five minutes for a fortnight.
     */
    public function recordFailure(DataSource $source, string $message): void
    {
        $failures = $source->consecutive_failures + 1;
        $threshold = max(1, (int) $source->failure_threshold);
        $tripped = $failures >= $threshold;

        $source->update([
            'consecutive_failures' => $failures,
            'last_sync_at' => now(),
            'last_sync_status' => 'Failed',
            'health_status' => $tripped ? 'Failed' : 'Degraded',
            'paused_at' => $tripped ? now() : $source->paused_at,
            'paused_reason' => $tripped
                ? "Paused after {$failures} consecutive failures. Last error: ".ConnectorException::redactMessage($message)
                : $source->paused_reason,
        ]);

        if ($tripped) {
            $source->auditAction('circuit_breaker_tripped', null, [
                'consecutive_failures' => $failures,
                'threshold' => $threshold,
            ]);

            $this->notifyOwner($source, $failures);
        }
    }

    /**
     * Exponential backoff for the next attempt, in seconds, capped at an
     * hour so a recovered source is picked up on the next sweep.
     */
    public function backoffSeconds(DataSource $source): int
    {
        return (int) min(3600, 30 * (2 ** max(0, $source->consecutive_failures - 1)));
    }

    /** Reopening the breaker is a deliberate human act, and it is audited. */
    public function resume(DataSource $source, User $user): DataSource
    {
        if ($source->paused_at === null) {
            throw ValidationException::withMessages(['resume' => 'This source is not paused.']);
        }

        $source->update([
            'paused_at' => null,
            'paused_reason' => null,
            'consecutive_failures' => 0,
            'health_status' => 'Unknown',
        ]);

        $source->auditAction('circuit_breaker_reset', null, ['by' => $user->id]);

        return $source->fresh();
    }

    /**
     * Write an extraction attempt to the shared sync log. The payload
     * carries counts and configuration keys — never rows, never
     * credentials (R5, R9).
     *
     * @param  array<string, mixed>  $payload
     */
    public function log(
        DataSource $source,
        string $entityType,
        string $status,
        array $payload = [],
        ?string $error = null,
    ): ?IntegrationSyncLog {
        try {
            return IntegrationSyncLog::create([
                'config_id' => null,
                'tenant_id' => $source->tenant_id,
                'entity_type' => 'ccm:'.$entityType,
                'local_id' => $source->id,
                'external_ref' => $source->credentials_ref,
                'direction' => 'inbound',
                'idempotency_key' => (string) Str::uuid(),
                'payload' => ['data_source' => $source->name, 'vendor' => $source->vendor_key, ...$payload],
                'status' => $status,
                'error_message' => $error !== null ? ConnectorException::redactMessage($error) : null,
                'attempts' => 1,
                'attempted_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // A logging failure must never break the extraction (R3).
            report($e);

            return null;
        }
    }

    private function notifyOwner(DataSource $source, int $failures): void
    {
        $owner = $source->owner;

        if (! $owner) {
            return;
        }

        try {
            app(NotificationDispatcher::class)->send(
                $owner,
                'monitoring.source-failed',
                new DataSourceFailedNotification($source, $failures),
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
