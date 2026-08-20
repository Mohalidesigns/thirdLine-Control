<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Technical metadata captured with a confidential Speak Up submission (CR).
 *
 * Two invariants:
 *
 *   1. A row never exists for an anonymous submission — the anonymous
 *      route captures nothing, and SpeakUpMetadataService::capture()
 *      refuses an anonymous case outright.
 *   2. Tier 2 (identifying) fields never serialise by default. They are in
 *      $hidden, so an accidental ->toArray() on any screen, export, API
 *      response or PDF carries Tier 1 only; the sole path to Tier 2 is
 *      tier2(), reached through an approved break-glass reveal that writes
 *      the Metadata Access Log.
 */
class SpeakUpReportMetadata extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'speak_up_report_metadata';

    public const HOSTNAME_SOURCES = ['sso_session', 'agent_header', 'unavailable'];

    /** Tier 2 — reachable only via tier2(), never via serialisation. */
    protected $hidden = [
        'ip_address', 'ip_forwarded_chain', 'hostname', 'reporter_user_id',
        'asn', 'isp', 'geo_city', 'user_agent_raw', 'device_model',
        'screen_resolution', 'locale', 'referrer',
    ];

    protected $fillable = [
        'tenant_id', 'report_id', 'ip_address', 'ip_forwarded_chain', 'asn', 'isp',
        'geo_country', 'geo_region', 'geo_city', 'user_agent_raw', 'browser',
        'browser_version', 'os', 'os_version', 'device_type', 'device_model',
        'hostname', 'hostname_source', 'screen_resolution', 'timezone', 'locale',
        'fingerprint_hash', 'session_duration_seconds', 'referrer', 'is_authenticated',
        'reporter_user_id', 'capture_sources', 'notice_version', 'notice_acknowledged_at',
        'captured_at', 'purge_after',
    ];

    protected $casts = [
        'ip_address' => 'encrypted',
        'ip_forwarded_chain' => 'encrypted',
        'hostname' => 'encrypted',
        'reporter_user_id' => 'encrypted',
        'capture_sources' => 'array',
        'is_authenticated' => 'boolean',
        'notice_version' => 'integer',
        'session_duration_seconds' => 'integer',
        'notice_acknowledged_at' => 'datetime',
        'captured_at' => 'datetime',
        'purge_after' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(InvestigationCase::class, 'report_id');
    }

    /**
     * Tier 1 — non-identifying. Visible to any control officer on the case
     * who holds 'speak_up.metadata.view_basic'; no reveal needed.
     *
     * @return array<string, mixed>
     */
    public function tier1(): array
    {
        return [
            'device_type' => $this->device_type,
            'browser' => $this->browser,
            'browser_version' => $this->browser_version,
            'os' => $this->os,
            'os_version' => $this->os_version,
            'geo_country' => $this->geo_country,
            'geo_region' => $this->geo_region,
            'timezone' => $this->timezone,
            'session_duration_seconds' => $this->session_duration_seconds,
            'is_authenticated' => $this->is_authenticated,
            'fingerprint_short' => $this->fingerprint_hash ? substr($this->fingerprint_hash, 0, 12) : null,
            'captured_at' => $this->captured_at?->toIso8601String(),
            'notice_version' => $this->notice_version,
            'capture_sources' => $this->capture_sources,
        ];
    }

    /**
     * Tier 2 — identifying. Callers reach this ONLY through
     * SpeakUpMetadataService::reveal(), which enforces the approved
     * break-glass request and writes the access log.
     *
     * @return array<string, mixed>
     */
    public function tier2(): array
    {
        return [
            'ip_address' => $this->ip_address,
            'ip_forwarded_chain' => $this->ip_forwarded_chain,
            'hostname' => $this->hostname,
            'hostname_source' => $this->hostname_source,
            'asn' => $this->asn,
            'isp' => $this->isp,
            'geo_city' => $this->geo_city,
            'user_agent_raw' => $this->user_agent_raw,
            'device_model' => $this->device_model,
            'screen_resolution' => $this->screen_resolution,
            'locale' => $this->locale,
            'referrer' => $this->referrer,
            'reporter_user_id' => $this->reporter_user_id !== null ? (int) $this->reporter_user_id : null,
        ];
    }

    /** The field names tier2() exposes — recorded on every access log row. */
    public static function tier2FieldNames(): array
    {
        return [
            'ip_address', 'ip_forwarded_chain', 'hostname', 'asn', 'isp',
            'geo_city', 'user_agent_raw', 'device_model', 'screen_resolution',
            'locale', 'referrer', 'reporter_user_id',
        ];
    }
}
