<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit trail record. Rows may only ever be inserted —
 * updates and deletes throw at the model layer (FR-12.4, NFR-6).
 */
class AuditTrail extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'actor_name',
        'actor_email',
        'entity_type',
        'entity_id',
        'action',
        'event_label',
        'subject_label',
        'description',
        'before',
        'after',
        'ip_address',
        'user_agent',
        'method',
        'url',
        'route_name',
        'status_code',
        'device_name',
        'batch_id',
        'previous_hash',
        'row_hash',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    public static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Audit trail records are immutable.'));
        static::deleting(fn () => throw new \LogicException('Audit trail records are immutable.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
