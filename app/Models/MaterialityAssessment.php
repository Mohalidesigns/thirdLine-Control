<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaterialityAssessment extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    public const BASES = ['financial', 'double'];

    public const STATUSES = ['Draft', 'Submitted', 'Approved', 'Rejected'];

    protected $fillable = [
        'tenant_id', 'entity_id', 'topic_id', 'period_label', 'basis',
        'financial_materiality_score', 'impact_materiality_score', 'is_material',
        'rationale', 'stakeholders_consulted', 'assessed_by', 'assessed_at',
        'approved_by', 'approved_at', 'status',
    ];

    protected $casts = [
        'financial_materiality_score' => 'integer',
        'impact_materiality_score' => 'integer',
        'is_material' => 'boolean',
        'stakeholders_consulted' => 'array',
        'assessed_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function topic(): BelongsTo
    {
        return $this->belongsTo(SustainabilityTopic::class, 'topic_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Topics that carry a disclosure duty for the period. */
    public function scopeMaterial(Builder $query): Builder
    {
        return $query->where('status', 'Approved')->where('is_material', true);
    }
}
