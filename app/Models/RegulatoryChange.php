<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegulatoryChange extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const STATUSES = ['New', 'Under Review', 'Impact Assessed', 'Actioned', 'Not Applicable'];

    public const TRANSITIONS = [
        'New' => ['Under Review', 'Not Applicable'],
        'Under Review' => ['Impact Assessed', 'Not Applicable'],
        'Impact Assessed' => ['Actioned', 'Not Applicable'],
        'Actioned' => [],
        'Not Applicable' => ['Under Review'],
    ];

    protected $fillable = [
        'tenant_id', 'regulator_id', 'title', 'reference', 'published_at',
        'effective_at', 'document_url', 'summary', 'impact_assessment', 'status',
        'assessed_by', 'assessed_at', 'actioned_by', 'actioned_at',
        'affected_obligation_ids', 'affected_control_ids', 'source', 'source_guid',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'effective_at' => 'date',
        'assessed_at' => 'datetime',
        'actioned_at' => 'datetime',
        'affected_obligation_ids' => 'array',
        'affected_control_ids' => 'array',
    ];

    public function regulator(): BelongsTo
    {
        return $this->belongsTo(Regulator::class);
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    public function actioner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['New', 'Under Review', 'Impact Assessed']);
    }
}
