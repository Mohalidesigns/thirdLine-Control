<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One emissions figure with its lineage (17.3): the activity data, the
 * factor, the factor's source, the control over it and the evidence
 * behind it.
 */
class GhgDataPoint extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    public const SCOPES = ['1', '2', '3'];

    public const DATA_QUALITIES = ['measured', 'calculated', 'estimated', 'proxy'];

    public const METHODOLOGIES = ['location_based', 'market_based', 'not_applicable'];

    public const STATUSES = ['Draft', 'Prepared', 'Verified', 'Rejected', 'Deferred'];

    protected $fillable = [
        'tenant_id', 'entity_id', 'scope', 'category', 'period_label',
        'period_start', 'period_end', 'activity_data', 'activity_unit',
        'emission_factor', 'emission_factor_source', 'tco2e', 'data_quality',
        'methodology', 'control_id', 'evidence_id', 'prepared_by', 'prepared_at',
        'verified_by', 'verified_at', 'verification_note', 'is_deferred',
        'deferral_reason', 'status',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'activity_data' => 'float',
        'emission_factor' => 'float',
        'tco2e' => 'float',
        'prepared_at' => 'datetime',
        'verified_at' => 'datetime',
        'is_deferred' => 'boolean',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Evidence::class);
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('status', 'Verified');
    }

    public function scopeForScope(Builder $query, string $scope): Builder
    {
        return $query->where('scope', $scope);
    }

    /**
     * A figure is assurable only when it can be traced: activity data, a
     * factor with a named source, a control over it and evidence behind
     * it. A tCO2e number missing any of those is a number an assurance
     * provider will not sign.
     */
    public function lineageGaps(): array
    {
        $gaps = [];

        if ($this->activity_data === null) {
            $gaps[] = 'activity data';
        }

        if ($this->emission_factor === null) {
            $gaps[] = 'emission factor';
        }

        if (! $this->emission_factor_source) {
            $gaps[] = 'factor source';
        }

        if (! $this->control_id) {
            $gaps[] = 'control';
        }

        if (! $this->evidence_id) {
            $gaps[] = 'evidence';
        }

        return $gaps;
    }

    public function isAssurable(): bool
    {
        return $this->lineageGaps() === [];
    }
}
