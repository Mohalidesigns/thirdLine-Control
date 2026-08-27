<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRichText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * A registered system SecondLine extracts from (12.1).
 *
 * Both the connection config and the credentials are encrypted at rest,
 * hidden from serialisation, and never reach an Inertia prop or an API
 * response. The frontend gets `masked_credentials` and nothing else (R9).
 */
class DataSource extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasRichText, SoftDeletes;

    public const SOURCE_TYPES = [
        'rest_api', 'soap', 'database', 'sftp', 'file_upload', 'webhook', 'odata', 'graphql', 'jdbc',
    ];

    public const SYSTEM_CATEGORIES = [
        'core_banking', 'erp', 'hrms', 'itsm', 'identity', 'payments', 'tax',
        'crm', 'ticketing', 'log', 'spreadsheet', 'other',
    ];

    public const VENDOR_KEYS = [
        'finacle', 'flexcube', 't24', 'bankone', 'imal', 'bancs', 'nibss', 'interswitch',
        'remita', 'sap', 'dynamics365', 'sage', 'odoo', 'm365', 'entra', 'servicenow',
        'freshservice', 'firs_mbs', 'custom',
    ];

    public const AUTH_TYPES = ['none', 'basic', 'api_key', 'oauth2', 'certificate', 'jwt'];

    public const HEALTH_STATUSES = ['Healthy', 'Degraded', 'Failed', 'Unknown'];

    protected $fillable = [
        'tenant_id', 'name', 'source_type', 'system_category', 'vendor_key',
        'connection_config', 'auth_type', 'credentials_ref', 'credentials',
        'schedule_cron', 'timezone', 'is_active', 'last_sync_at', 'last_sync_status',
        'consecutive_failures', 'health_status', 'failure_threshold', 'rate_limit_per_minute',
        'timeout_seconds', 'paused_at', 'paused_reason', 'data_residency_note',
        'owner_id', 'created_by', 'approved_by', 'approved_at',
    ];

    /** Spec §9 — Editor.js documents in {field}_rich; the plain column is the derived mirror. */
    protected array $richText = ['data_residency_note'];

    protected $casts = [
        'is_active' => 'boolean',
        'last_sync_at' => 'datetime',
        'approved_at' => 'datetime',
        'paused_at' => 'datetime',
        'consecutive_failures' => 'integer',
        'failure_threshold' => 'integer',
        'rate_limit_per_minute' => 'integer',
        'timeout_seconds' => 'integer',
    ];

    /**
     * Neither secret is ever serialised. `connection_config` itself is
     * hidden too: a JDBC string carries a host, a port and often a user.
     */
    protected $hidden = ['connection_config', 'credentials', 'credentials_encrypted', 'credentials_ref'];

    protected $appends = ['masked_credentials', 'is_approved'];

    /**
     * Decrypted connection settings. Reading a row written under a rotated
     * key must not fatal the page — an unreadable config reads as empty and
     * the connection test reports it.
     */
    public function connectionConfig(): Attribute
    {
        return Attribute::make(
            get: function () {
                $raw = $this->attributes['connection_config'] ?? null;

                if (! $raw) {
                    return [];
                }

                try {
                    return json_decode(Crypt::decryptString($raw), true) ?: [];
                } catch (\Throwable $e) {
                    Log::warning('Data source connection config could not be decrypted', [
                        'data_source' => $this->id,
                    ]);

                    return [];
                }
            },
            set: fn (?array $value) => [
                'connection_config' => $value ? Crypt::encryptString(json_encode($value)) : null,
            ],
        );
    }

    /** Write-only credentials, mirroring IntegrationConfig (FR-11.6). */
    public function credentials(): Attribute
    {
        return Attribute::make(
            get: function () {
                $raw = $this->attributes['credentials_encrypted'] ?? null;

                if (! $raw) {
                    return null;
                }

                try {
                    return Crypt::decryptString($raw);
                } catch (\Throwable) {
                    return null;
                }
            },
            set: fn (?string $value) => [
                'credentials_encrypted' => $value ? Crypt::encryptString($value) : null,
            ],
        );
    }

    /** What the UI is allowed to see: whether a secret exists, not what it is. */
    public function getMaskedCredentialsAttribute(): ?string
    {
        // Null-coalesced because a partial select (a dashboard list, say)
        // legitimately never loads the column.
        return ($this->attributes['credentials_encrypted'] ?? null) ? '•••••••• (stored)' : null;
    }

    public function getIsApprovedAttribute(): bool
    {
        return $this->approved_at !== null;
    }

    /**
     * The audit trail records that the connection changed, never what it
     * changed to — a ciphertext blob in a second table is still a copy of
     * a secret (R3 + R9).
     *
     * @return array<int, string>
     */
    public function auditRedacts(): array
    {
        return ['connection_config', 'credentials_encrypted'];
    }

    public function datasets(): HasMany
    {
        return $this->hasMany(DataSourceDataset::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('paused_at');
    }

    /** Live only when approved, active and not tripped by the breaker. */
    public function isExtractable(): bool
    {
        return $this->is_active && $this->approved_at !== null && $this->paused_at === null;
    }
}
