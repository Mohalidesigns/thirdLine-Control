<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One measure of an objective: a Phase 10 metric plus the baseline and
 * target this objective is judged against (17.1).
 */
class ObjectiveMetric extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'objective_id', 'metric_id', 'baseline_value',
        'target_value', 'weight', 'note',
    ];

    protected $casts = [
        'baseline_value' => 'float',
        'target_value' => 'float',
        'weight' => 'integer',
    ];

    public function objective(): BelongsTo
    {
        return $this->belongsTo(Objective::class);
    }

    public function metric(): BelongsTo
    {
        return $this->belongsTo(Metric::class);
    }

    /**
     * Achievement of this measure as a percentage, from the metric's latest
     * reading against baseline → target. Works in either direction because
     * the target may be below the baseline (a loss ratio being driven down
     * is as much an achievement as revenue being driven up), so the sign of
     * (target − baseline) is what orients the scale rather than any
     * assumption about "higher is better".
     */
    public function achievement(?float $latest): ?int
    {
        if ($latest === null || $this->target_value === null) {
            return null;
        }

        $baseline = $this->baseline_value ?? 0.0;
        $span = $this->target_value - $baseline;

        // A target equal to the baseline is a "hold this line" measure: it
        // is met while the reading has not moved away from the target.
        if (abs($span) < 1e-9) {
            return abs($latest - $this->target_value) < 1e-9 ? 100 : 0;
        }

        return (int) round(max(0, min(100, (($latest - $baseline) / $span) * 100)));
    }
}
