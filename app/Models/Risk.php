<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesReference;
use App\Models\Concerns\HasRichText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Risk extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, HasRichText, SoftDeletes;

    public const TYPES = ['qualitative', 'quantitative', 'both'];

    public const SOURCES = ['internal', 'external', 'both'];

    public const VELOCITIES = ['Slow', 'Medium', 'Fast'];

    public const RATING_BANDS = ['Low', 'Moderate', 'High', 'Critical'];

    /** Basel II operational-risk event types (level 1). */
    public const BASEL_EVENT_TYPES = [
        'Internal Fraud',
        'External Fraud',
        'Employment Practices & Workplace Safety',
        'Clients Products & Business Practices',
        'Damage to Physical Assets',
        'Business Disruption & System Failures',
        'Execution Delivery & Process Management',
    ];

    protected $fillable = [
        'tenant_id', 'external_ref', 'code', 'title', 'description', 'category',
        'inherent_likelihood', 'inherent_impact', 'inherent_rating',
        'residual_rating', 'risk_owner_id', 'source', 'status',
        // Phase 10 — enterprise risk management
        'risk_category_id', 'risk_type', 'risk_source', 'taxonomy_ref',
        'basel_event_type', 'velocity', 'is_emerging', 'horizon',
        'appetite_id', 'parent_risk_id', 'second_line_reviewer_id',
        'entity_id', 'process_id',
        'residual_likelihood', 'residual_impact', 'target_likelihood', 'target_impact',
        'target_rating', 'current_rating_band',
        'financial_impact_minor', 'expected_loss_minor', 'var_95_minor', 'var_99_minor',
        'loss_currency', 'appetite_breached', 'appetite_breach_at',
    ];

    /** Editor.js-backed fields — see HasRichText. */
    protected array $richText = ['description'];

    protected $casts = [
        'inherent_likelihood' => 'integer',
        'inherent_impact' => 'integer',
        'inherent_rating' => 'integer',
        'residual_rating' => 'float',
        'residual_likelihood' => 'integer',
        'residual_impact' => 'integer',
        'target_likelihood' => 'integer',
        'target_impact' => 'integer',
        'target_rating' => 'float',
        'is_emerging' => 'boolean',
        'appetite_breached' => 'boolean',
        'appetite_breach_at' => 'datetime',
        'financial_impact_minor' => 'integer',
        'expected_loss_minor' => 'integer',
        'var_95_minor' => 'integer',
        'var_99_minor' => 'integer',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'risk_owner_id');
    }

    public function secondLineReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'second_line_reviewer_id');
    }

    public function riskCategory(): BelongsTo
    {
        return $this->belongsTo(RiskCategory::class, 'risk_category_id');
    }

    public function appetite(): BelongsTo
    {
        return $this->belongsTo(RiskAppetite::class, 'appetite_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_risk_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_risk_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class, 'entity_id');
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(BusinessProcess::class, 'process_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(RiskAssessment::class)->orderByDesc('assessed_at');
    }

    public function treatments(): HasMany
    {
        return $this->hasMany(RiskTreatment::class);
    }

    public function controls(): BelongsToMany
    {
        return $this->belongsToMany(Control::class, 'risk_control_map')
            ->withPivot(['contribution_weight', 'mapped_by', 'mapped_at'])
            ->withTimestamps();
    }

    /** Risks with no active mapped control — control gaps (FR-2.5). */
    public function scopeControlGaps(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->whereDoesntHave('controls', fn (Builder $q) => $q->where('status', 'Active'));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeBreachingAppetite(Builder $query): Builder
    {
        return $query->where('appetite_breached', true);
    }

    /** The current residual position, falling back to inherent when untested. */
    public function currentScore(): float
    {
        return (float) ($this->residual_rating ?? $this->inherent_rating);
    }

    /** How far the residual position sits above the agreed target. */
    public function targetGap(): ?float
    {
        if ($this->target_rating === null) {
            return null;
        }

        return round($this->currentScore() - (float) $this->target_rating, 2);
    }

    public function latestAssessment(string $type): ?RiskAssessment
    {
        return $this->assessments()->ofType($type)->published()->first();
    }
}
