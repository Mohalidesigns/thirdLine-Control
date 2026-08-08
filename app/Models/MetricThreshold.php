<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricThreshold extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const LEVELS = ['Green', 'Amber', 'Red', 'Critical'];

    public const OPERATORS = ['gt', 'gte', 'lt', 'lte', 'between', 'outside'];

    protected $fillable = [
        'tenant_id', 'metric_id', 'level', 'operator', 'value_from', 'value_to',
        'colour_hex', 'action_required', 'escalate_to_role', 'sort_order',
    ];

    protected $casts = [
        'value_from' => 'float',
        'value_to' => 'float',
        'sort_order' => 'integer',
    ];

    public function metric(): BelongsTo
    {
        return $this->belongsTo(Metric::class);
    }

    /**
     * Does $value fall in this band? `between` is inclusive of both bounds,
     * `outside` is its complement — the two are exact opposites so a pair of
     * bands can never leave a value unclassified.
     */
    public function matches(float $value): bool
    {
        return match ($this->operator) {
            'gt' => $this->value_from !== null && $value > $this->value_from,
            'gte' => $this->value_from !== null && $value >= $this->value_from,
            'lt' => $this->value_from !== null && $value < $this->value_from,
            'lte' => $this->value_from !== null && $value <= $this->value_from,
            'between' => $this->value_from !== null && $this->value_to !== null
                && $value >= $this->value_from && $value <= $this->value_to,
            'outside' => $this->value_from !== null && $this->value_to !== null
                && ($value < $this->value_from || $value > $this->value_to),
            default => false,
        };
    }
}
