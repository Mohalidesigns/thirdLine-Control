<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationSyncLog extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'config_id', 'tenant_id', 'entity_type', 'local_id', 'external_ref',
        'direction', 'idempotency_key', 'payload', 'status', 'error_message',
        'attempts', 'attempted_at', 'replayed_from_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'attempted_at' => 'datetime',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(IntegrationConfig::class, 'config_id');
    }
}
