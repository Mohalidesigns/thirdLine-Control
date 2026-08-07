<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The seeded notification event catalogue (rule R1 — events are data,
 * not hard-coded). tenant_id NULL rows are the platform catalogue; a
 * tenant row with the same key overrides its defaults. NOT BelongsToTenant
 * — resolution needs both scopes, handled in NotificationDispatcher.
 */
class NotificationEvent extends Model
{
    use Auditable;

    protected $fillable = [
        'tenant_id',
        'key',
        'label',
        'description',
        'category',
        'default_channels',
        'is_user_configurable',
    ];

    protected $casts = [
        'default_channels' => 'array',
        'is_user_configurable' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Tenant override first, platform catalogue row second. */
    public static function forKey(string $key, ?int $tenantId = null): ?self
    {
        return static::query()
            ->where('key', $key)
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->orderByRaw('tenant_id is null')
            ->first();
    }
}
