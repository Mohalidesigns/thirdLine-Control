<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentAction extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const TYPES = ['containment', 'corrective', 'preventive', 'communication', 'regulatory'];

    public const STATUSES = ['Open', 'In Progress', 'Completed', 'Verified', 'Cancelled'];

    public const CLOSED_STATUSES = ['Completed', 'Verified', 'Cancelled'];

    protected $fillable = [
        'tenant_id', 'incident_id', 'action_type', 'title', 'description',
        'owner_id', 'due_at', 'status', 'is_mandatory', 'completed_at',
        'verified_by', 'verified_at', 'evidence_id',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'verified_at' => 'datetime',
        'is_mandatory' => 'boolean',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Evidence::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::CLOSED_STATUSES);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereNotNull('due_at')->where('due_at', '<', now());
    }
}
