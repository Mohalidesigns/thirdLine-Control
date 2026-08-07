<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Feature flags gate every v2 module so partial deployments are safe.
 * Rows with tenant_id NULL are global defaults; a tenant-scoped row for
 * the same key overrides. Deliberately NOT BelongsToTenant — resolution
 * needs both scopes, handled explicitly in FeatureService.
 */
class FeatureFlag extends Model
{
    use Auditable;

    protected $fillable = [
        'tenant_id',
        'key',
        'is_enabled',
        'rollout_percentage',
        'description',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'rollout_percentage' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
