<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A replayed offline-outbox action (15.1). One row per client-generated
 * idempotency key; the stored outcome is what a duplicate replay gets
 * back instead of a second application.
 */
class OfflineAction extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['applied', 'rejected', 'conflict'];

    protected $fillable = [
        'tenant_id', 'user_id', 'client_id', 'action_type', 'payload',
        'status', 'result', 'reason', 'acted_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'acted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
