<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentTimelineEntry extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected $table = 'incident_timeline';

    protected $fillable = [
        'tenant_id', 'incident_id', 'occurred_at', 'actor_id',
        'event_type', 'description', 'is_material',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'is_material' => 'boolean',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
