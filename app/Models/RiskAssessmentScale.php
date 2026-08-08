<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per level per scale. The heatmap grid size is COUNT(levels) —
 * a tenant switching to a 4×4 matrix deletes a level, it does not ask for
 * a code change (R1).
 */
class RiskAssessmentScale extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const TYPES = ['likelihood', 'impact', 'velocity', 'control_effect', 'rating_band'];

    /**
     * Impact dimensions the platform scores by default. Weightings are
     * tenant data (tenants->settings), not constants.
     */
    public const DIMENSIONS = [
        'financial', 'regulatory', 'reputational', 'operational', 'customer', 'people',
    ];

    protected $fillable = [
        'tenant_id', 'scale_type', 'dimension', 'level', 'label', 'description',
        'lower_bound_minor', 'upper_bound_minor', 'score_from', 'score_to',
        'currency', 'colour_hex', 'sort_order',
    ];

    protected $casts = [
        'level' => 'integer',
        'lower_bound_minor' => 'integer',
        'upper_bound_minor' => 'integer',
        'score_from' => 'float',
        'score_to' => 'float',
        'sort_order' => 'integer',
    ];

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('scale_type', $type);
    }

    /** The headline scale for a type — the dimension-agnostic rows. */
    public function scopeHeadline(Builder $query): Builder
    {
        return $query->whereNull('dimension');
    }
}
