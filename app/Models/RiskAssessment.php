<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RiskAssessment extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    public const TYPES = ['inherent', 'residual', 'target'];

    public const RATINGS = ['Low', 'Moderate', 'High', 'Critical'];

    public const METHODS = ['workshop', 'interview', 'data_driven', 'scenario', 'monte_carlo'];

    public const STATUSES = ['Draft', 'Pending Review', 'Published', 'Rejected'];

    protected $fillable = [
        'tenant_id', 'risk_id', 'assessment_type', 'assessed_at', 'assessed_by',
        'approved_by', 'approved_at', 'status',
        'likelihood_level', 'impact_level', 'likelihood_rationale', 'impact_rationale',
        'score', 'rating', 'financial_impact_minor', 'currency',
        'loss_min_minor', 'loss_likely_minor', 'loss_max_minor', 'likelihood_percent',
        'expected_loss_minor', 'var_95_minor', 'var_99_minor', 'loss_curve', 'simulated_at',
        'impact_dimensions', 'driving_dimension', 'confidence', 'method',
        'period_label', 'notes',
    ];

    protected $casts = [
        'assessed_at' => 'datetime',
        'approved_at' => 'datetime',
        'simulated_at' => 'datetime',
        'likelihood_level' => 'integer',
        'impact_level' => 'integer',
        'score' => 'float',
        'financial_impact_minor' => 'integer',
        'loss_min_minor' => 'integer',
        'loss_likely_minor' => 'integer',
        'loss_max_minor' => 'integer',
        'likelihood_percent' => 'integer',
        'expected_loss_minor' => 'integer',
        'var_95_minor' => 'integer',
        'var_99_minor' => 'integer',
        'loss_curve' => 'array',
        'impact_dimensions' => 'array',
    ];

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'Published');
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('assessment_type', $type);
    }
}
