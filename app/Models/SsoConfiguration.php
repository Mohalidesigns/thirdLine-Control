<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

class SsoConfiguration extends Model
{
    use Auditable, BelongsToTenant;

    /**
     * Fields a maker may propose and a checker must approve. Everything
     * else (approval metadata) is never mass-assignable.
     */
    public const CONFIGURABLE_FIELDS = [
        'protocol', 'display_name', 'entity_id', 'sso_url', 'slo_url', 'x509_cert',
        'oidc_issuer', 'oidc_client_id', 'oidc_client_secret', 'oidc_discovery_url',
        'attribute_map', 'jit_provisioning', 'default_role_id',
        'allowed_email_domains', 'is_enabled',
    ];

    protected $fillable = [
        'tenant_id', 'protocol', 'display_name', 'entity_id', 'sso_url', 'slo_url',
        'x509_cert', 'oidc_issuer', 'oidc_client_id', 'oidc_client_secret',
        'oidc_discovery_url', 'attribute_map', 'jit_provisioning', 'default_role_id',
        'allowed_email_domains', 'is_enabled', 'created_by',
    ];

    protected $hidden = ['x509_cert', 'oidc_client_secret'];

    protected $casts = [
        'x509_cert' => 'encrypted',
        'oidc_client_secret' => 'encrypted',
        'attribute_map' => 'array',
        'jit_provisioning' => 'boolean',
        'allowed_email_domains' => 'array',
        'is_enabled' => 'boolean',
        'verified_at' => 'datetime',
        'approved_at' => 'datetime',
        'pending_changes' => 'array',
        'pending_changes_at' => 'datetime',
    ];

    /** Approved + enabled: usable at login. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('approved_at')->where('is_enabled', true);
    }

    public function isActive(): bool
    {
        return $this->approved_at !== null && $this->is_enabled;
    }

    public function hasPendingChanges(): bool
    {
        return $this->pending_changes !== null;
    }

    public function defaultRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'default_role_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pending_changes_by');
    }
}
