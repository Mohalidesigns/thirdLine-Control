<?php

namespace App\Services;

use App\Jobs\WriteAuditRecord;
use App\Support\AuditEventCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuditTrailService
{
    /**
     * Attribute keys that are redacted from every payload, on every model,
     * with no opt-out — credential material has no business in a second
     * table regardless of what a model forgets to declare (12.1, R9).
     */
    private const GLOBAL_REDACT = [
        'password', 'password_confirmation', 'current_password',
        'remember_token', 'api_token', 'token', 'secret', 'api_key',
        'client_secret', 'private_key', 'licence_key', 'license_key',
        'webhook_secret', 'access_token', 'refresh_token',
    ];

    /**
     * Record an action against an entity. Never lets a logging failure
     * break the business operation it is recording.
     *
     * A model may declare auditsAnonymously(): the event is still written
     * (R3) but carries no user, IP or agent — anonymous survey responses
     * must never be de-anonymisable, even through the audit trail (9.4).
     *
     * A model may also declare auditRedacts(): those attributes are
     * replaced with a marker in the before/after payloads. The event is
     * still recorded in full — only the secret itself is withheld, because
     * a connection string or an API key has no business being copied into
     * a second table (12.1, R9).
     */
    public function record(string $action, Model $entity, ?array $before = null, ?array $after = null, ?string $description = null, bool $queued = true): void
    {
        try {
            $anonymous = method_exists($entity, 'auditsAnonymously') && $entity->auditsAnonymously();

            $redact = method_exists($entity, 'auditRedacts') ? $entity->auditRedacts() : [];

            $this->write([
                'tenant_id' => $entity->tenant_id ?? auth()->user()?->tenant_id,
                'entity_type' => $entity->getMorphClass(),
                'entity_id' => $entity->getKey(),
                'action' => $action,
                'subject_label' => $this->labelFor($entity),
                'description' => $description ?? sprintf(
                    '%s %s',
                    Str::headline(class_basename($entity)),
                    strtolower(AuditEventCatalog::label($action)),
                ),
                'before' => $this->redact($before, $redact),
                'after' => $this->redact($after, $redact),
            ], $anonymous, $queued);
        } catch (\Throwable $e) {
            Log::error('Audit trail write failed', [
                'action' => $action,
                'entity' => $entity->getMorphClass().'#'.$entity->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record an event with no (or no loaded) subject model — auth events,
     * denied requests, the request-fallback layer, console operations.
     * $topic stands in for entity_type; explicit actor fields cover events
     * where auth() is empty (a failed login) or already stale (a logout).
     *
     * @param  array<string, mixed>  $overrides  Extra column values
     *                                           (status_code, actor_*…).
     */
    public function recordEvent(string $action, string $topic = 'system', ?string $description = null, ?array $properties = null, array $overrides = []): void
    {
        try {
            $this->write(array_merge([
                'tenant_id' => auth()->user()?->tenant_id,
                'entity_type' => $topic,
                'entity_id' => 0,
                'action' => $action,
                'description' => $description,
                'after' => $this->redact($properties, []),
            ], $overrides), anonymous: false);
        } catch (\Throwable $e) {
            Log::error('Audit trail write failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Assemble the full row (actor snapshot, request context, human label,
     * batch id) and hand it to the queued writer. Everything the row needs
     * is captured HERE, synchronously — by the time the job runs, the
     * request is gone.
     *
     * @param  array<string, mixed>  $row
     */
    private function write(array $row, bool $anonymous, bool $queued = true): void
    {
        $request = request();
        $user = auth()->user();
        $agent = $anonymous ? null : (substr((string) $request?->userAgent(), 0, 500) ?: null);

        $row = array_merge([
            'user_id' => $anonymous ? null : ($row['user_id'] ?? $user?->id),
            'actor_name' => $anonymous ? null : ($row['actor_name'] ?? $user?->name),
            'actor_email' => $anonymous ? null : ($row['actor_email'] ?? $user?->email),
            'event_label' => AuditEventCatalog::label($row['action']),
            'ip_address' => $anonymous ? null : $request?->ip(),
            'user_agent' => $agent,
            'device_name' => $agent ? $this->deviceName($agent) : null,
            'method' => $request?->method(),
            'url' => $anonymous ? null : $request?->fullUrl(),
            'route_name' => $this->cleanRouteName($request),
            'batch_id' => $request ? app(ActivityContext::class)->batchId() : null,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ], array_filter($row, fn ($v) => $v !== null));

        if ($request) {
            app(ActivityContext::class)->markLogged();
        }

        // $queued=false is for writes that something reads back in the SAME
        // request — the break-glass cap counts its own rows — where a
        // deferred write would make the enforcement read stale data.
        if ($queued) {
            WriteAuditRecord::dispatch($row)->afterCommit();
        } else {
            (new WriteAuditRecord($row))->handle();
        }
    }

    /**
     * Key-based redaction: model-declared keys plus the global list, applied
     * recursively so a nested payload can't smuggle a secret through.
     *
     * @param  array<int, string>  $keys
     */
    private function redact(?array $payload, array $keys): ?array
    {
        if ($payload === null) {
            return null;
        }

        $keys = array_map('strtolower', array_merge($keys, self::GLOBAL_REDACT));

        $walk = function (array $data) use (&$walk, $keys): array {
            foreach ($data as $key => $value) {
                if (is_string($key) && in_array(strtolower($key), $keys, true)) {
                    $data[$key] = '[redacted]';
                } elseif (is_array($value)) {
                    $data[$key] = $walk($value);
                }
            }

            return $data;
        };

        return $walk($payload);
    }

    /**
     * Best-effort human label for a subject, snapshotted at event time so
     * the log stays readable after the record is renamed or deleted.
     */
    private function labelFor(Model $model): ?string
    {
        foreach (['name', 'title', 'reference', 'control_ref', 'code', 'subject', 'email'] as $attr) {
            $value = $model->getAttribute($attr);
            if (is_string($value) && $value !== '') {
                return substr($value, 0, 255);
            }
        }

        return class_basename($model).' #'.$model->getKey();
    }

    /** Coarse "Chrome on macOS" style device name — context, not tracking. */
    private function deviceName(string $agent): string
    {
        $os = match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Unknown OS',
        };

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Unknown browser',
        };

        return "{$browser} on {$os}";
    }

    /**
     * Never store Laravel's auto-generated name for an unnamed route —
     * "generated::…" is noise pretending to be identity.
     */
    private function cleanRouteName($request): ?string
    {
        $name = $request?->route()?->getName();

        return ($name && ! str_starts_with($name, 'generated::')) ? $name : null;
    }
}
