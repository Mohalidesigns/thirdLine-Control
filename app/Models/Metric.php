<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Metric extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    public const TYPES = ['KRI', 'KPI', 'KCI', 'KPI_control'];

    public const DATA_TYPES = ['number', 'percentage', 'currency', 'ratio', 'count', 'duration'];

    public const DIRECTIONS = ['higher_is_better', 'lower_is_better', 'target_is_best'];

    public const FREQUENCIES = ['Daily', 'Weekly', 'Monthly', 'Quarterly', 'Semi-annual', 'Annual'];

    public const SOURCES = ['manual', 'integration', 'calculated'];

    public const AGGREGATIONS = ['sum', 'average', 'last', 'max', 'min'];

    protected $fillable = [
        'tenant_id', 'metric_type', 'code', 'name', 'description', 'category_id',
        'formula', 'unit', 'data_type', 'currency', 'direction', 'frequency',
        'owner_id', 'source', 'source_config', 'calculation_expression',
        'entity_id', 'is_active', 'first_period', 'aggregation', 'target_value',
    ];

    protected $casts = [
        'source_config' => 'array',
        'is_active' => 'boolean',
        'target_value' => 'float',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(RiskCategory::class, 'category_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class, 'entity_id');
    }

    public function thresholds(): HasMany
    {
        return $this->hasMany(MetricThreshold::class)->orderBy('sort_order');
    }

    public function values(): HasMany
    {
        return $this->hasMany(MetricValue::class)->orderBy('period_start');
    }

    public function breaches(): HasMany
    {
        return $this->hasMany(MetricBreach::class);
    }

    public function latestValue(): ?MetricValue
    {
        return $this->values()->reorder()->orderByDesc('period_start')->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeCalculated(Builder $query): Builder
    {
        return $query->where('source', 'calculated')->whereNotNull('calculation_expression');
    }
}
