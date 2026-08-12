<?php

namespace App\Services;

use App\Exceptions\ResidencyViolationException;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\IntegrationConfig;
use App\Models\IntegrationSyncLog;
use App\Models\TestInstance;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Outbound publication to ThirdLine / NexusRisk (FR-11.1—11.7). Every
 * attempt is logged with payload and status; failures are replayable
 * from the sync log. Contract rules: every payload carries tenant_id,
 * external_ref, source_system, version_no, last_approved_at, and an
 * idempotency key.
 */
class IntegrationService
{
    public function publishControl(Control $control, string $event = 'upsert'): ?IntegrationSyncLog
    {
        $config = $this->activeConfig($control->tenant_id, 'ThirdLine');

        if (! $config || $config->sync_direction === 'Pull') {
            return null;
        }

        if (! $control->external_ref) {
            $control->updateQuietly(['external_ref' => 'SL-'.Str::uuid()]);
        }

        return $this->send($config, 'control', $control->id, [
            'tenant_id' => $control->tenant_id,
            'external_ref' => $control->external_ref,
            'source_system' => 'SecondLine',
            'version_no' => $control->current_version,
            'last_approved_at' => $control->approved_at?->toIso8601String(),
            'event' => $event,
            'control' => $control->only([
                'control_ref', 'title', 'description', 'objective', 'type', 'nature',
                'frequency', 'is_key_control', 'status',
            ]),
        ], $event === 'retire' ? "/api/v1/controls/{$control->external_ref}/retire" : '/api/v1/controls');
    }

    public function publishTestResult(TestInstance $instance): ?IntegrationSyncLog
    {
        $config = $this->activeConfig($instance->tenant_id, 'ThirdLine');

        if (! $config || $config->sync_direction === 'Pull') {
            return null;
        }

        $rating = $instance->effectivenessRating;

        return $this->send($config, 'test_result', $instance->id, [
            'tenant_id' => $instance->tenant_id,
            'external_ref' => $instance->control?->external_ref,
            'source_system' => 'SecondLine',
            'version_no' => 1,
            'last_approved_at' => $instance->reviewed_at?->toIso8601String(),
            'result' => [
                'reference' => $instance->reference,
                'period_label' => $instance->period_label,
                'status' => $instance->status,
                'design_effectiveness' => $rating?->design_effectiveness,
                'operating_effectiveness' => $rating?->operating_effectiveness,
                'overall_rating' => $rating?->overall_rating,
            ],
        ], '/api/v1/test-results');
    }

    public function publishException(ControlException $exception): ?IntegrationSyncLog
    {
        $config = $this->activeConfig($exception->tenant_id, 'ThirdLine');

        if (! $config || $config->sync_direction === 'Pull') {
            return null;
        }

        return $this->send($config, 'exception', $exception->id, [
            'tenant_id' => $exception->tenant_id,
            'external_ref' => $exception->control?->external_ref,
            'source_system' => 'SecondLine',
            'version_no' => 1,
            'last_approved_at' => $exception->updated_at?->toIso8601String(),
            'exception' => $exception->only([
                'reference', 'title', 'severity', 'status', 'age_days', 'is_overdue',
            ]),
        ], '/api/v1/exceptions');
    }

    /**
     * Inbound control upsert from ThirdLine (FR-11.3). Matches on
     * external_ref within the authenticated tenant only; conflict resolved
     * by the configured rule (default: latest last_approved_at wins).
     */
    public function ingestControl(IntegrationConfig $config, array $payload): array
    {
        $externalRef = $payload['external_ref'];

        $existing = Control::withoutGlobalScopes()
            ->where('tenant_id', $config->tenant_id)
            ->where('external_ref', $externalRef)
            ->first();

        $incomingApprovedAt = isset($payload['last_approved_at'])
            ? Carbon::parse($payload['last_approved_at'])
            : null;

        if ($existing && $config->conflict_rule === 'last_approved_at_wins'
            && $existing->approved_at && $incomingApprovedAt
            && $existing->approved_at->gte($incomingApprovedAt)) {
            $this->log($config, 'control', $existing->id, 'inbound', $payload, 'Success', 'Skipped — local version is newer.');

            return ['action' => 'skipped', 'reason' => 'local version newer', 'control_ref' => $existing->control_ref];
        }

        $attributes = [
            'title' => $payload['control']['title'],
            'description' => $payload['control']['description'] ?? null,
            'objective' => $payload['control']['objective'] ?? null,
            'type' => $payload['control']['type'] ?? 'Preventive',
            'nature' => $payload['control']['nature'] ?? 'Manual',
            'frequency' => $payload['control']['frequency'] ?? 'Monthly',
            'is_key_control' => (bool) ($payload['control']['is_key_control'] ?? false),
            'status' => 'Active',
            'approved_at' => $incomingApprovedAt,
            'sync_status' => 'synced_from_thirdline',
        ];

        if ($existing) {
            $existing->update($attributes);
            $control = $existing;
            $action = 'updated';
        } else {
            $control = Control::withoutGlobalScopes()->create([
                ...$attributes,
                'tenant_id' => $config->tenant_id,
                'control_ref' => 'TL-'.str_pad((string) (Control::withoutGlobalScopes()->where('tenant_id', $config->tenant_id)->count() + 1), 3, '0', STR_PAD_LEFT),
                'external_ref' => $externalRef,
            ]);
            $action = 'created';
        }

        $this->log($config, 'control', $control->id, 'inbound', $payload, 'Success');

        return ['action' => $action, 'control_ref' => $control->control_ref, 'external_ref' => $externalRef];
    }

    public function replay(IntegrationSyncLog $failed): IntegrationSyncLog
    {
        $config = $failed->config;

        return $this->send(
            $config,
            $failed->entity_type,
            $failed->local_id,
            $failed->payload,
            $failed->payload['_endpoint'] ?? '/api/v1/controls',
            replayedFrom: $failed->id,
        );
    }

    private function activeConfig(?int $tenantId, string $target): ?IntegrationConfig
    {
        return IntegrationConfig::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('target_system', $target)
            ->where('is_active', true)
            ->first();
    }

    private function send(IntegrationConfig $config, string $entityType, ?int $localId, array $payload, string $endpoint, ?int $replayedFrom = null): IntegrationSyncLog
    {
        $payload['_endpoint'] = $endpoint;

        $log = IntegrationSyncLog::create([
            'config_id' => $config->id,
            'tenant_id' => $config->tenant_id,
            'entity_type' => $entityType,
            'local_id' => $localId,
            'external_ref' => $payload['external_ref'] ?? null,
            'direction' => 'outbound',
            'idempotency_key' => (string) Str::uuid(),
            'payload' => $payload,
            'status' => 'Pending',
            'replayed_from_id' => $replayedFrom,
        ]);

        if (! $config->base_url) {
            $log->update(['status' => 'Failed', 'error_message' => 'No base URL configured.', 'attempts' => 1, 'attempted_at' => now()]);

            return $log;
        }

        // Residency guard at the integration layer (16.2): an endpoint
        // declared to sit outside the tenant's country is blocked before
        // any byte leaves. The log row keeps the attempt replayable once
        // the endpoint map is corrected; the guard has already audited it.
        try {
            app(ResidencyGuard::class)->assertEndpointAllowed($config->base_url, $config->tenant);
        } catch (ResidencyViolationException $e) {
            $log->update(['status' => 'Failed', 'error_message' => $e->getMessage(), 'attempts' => 1, 'attempted_at' => now()]);

            return $log;
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'X-Api-Key' => $config->credentials,
                    'Idempotency-Key' => $log->idempotency_key,
                ])
                ->post(rtrim($config->base_url, '/').$endpoint, collect($payload)->except('_endpoint')->all());

            $log->update([
                'status' => $response->successful() ? 'Success' : 'Failed',
                'error_message' => $response->successful() ? null : 'HTTP '.$response->status().': '.str($response->body())->limit(500),
                'attempts' => $log->attempts + 1,
                'attempted_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $log->update([
                'status' => 'Failed',
                'error_message' => str($e->getMessage())->limit(500),
                'attempts' => $log->attempts + 1,
                'attempted_at' => now(),
            ]);
        }

        return $log;
    }

    private function log(IntegrationConfig $config, string $entityType, ?int $localId, string $direction, array $payload, string $status, ?string $note = null): IntegrationSyncLog
    {
        return IntegrationSyncLog::create([
            'config_id' => $config->id,
            'tenant_id' => $config->tenant_id,
            'entity_type' => $entityType,
            'local_id' => $localId,
            'external_ref' => $payload['external_ref'] ?? null,
            'direction' => $direction,
            'idempotency_key' => request()?->header('Idempotency-Key'),
            'payload' => $payload,
            'status' => $status,
            'error_message' => $note,
            'attempts' => 1,
            'attempted_at' => now(),
        ]);
    }
}
