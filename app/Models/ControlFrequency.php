<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CR-03 §C.1: a rhythm the control function works to. Behaviour keys on
 * `cycle` and `generation_mode`; `label` is display only, so a tenant can
 * rename "Semi-annual" to "Half yearly" without changing a period
 * calculation.
 *
 * Rows with tenant_id NULL are the platform catalogue; a tenant row of
 * the same code shadows it.
 */
class ControlFrequency extends Model
{
    use HasFactory;

    public const CYCLES = [
        'daily', 'weekly', 'monthly', 'quarterly', 'semiannual', 'annual',
        'continuous', 'event',
    ];

    public const GENERATION_MODES = ['scheduled', 'continuous', 'event'];

    protected $fillable = [
        'tenant_id', 'code', 'label', 'cycle', 'generation_mode',
        'grace_days', 'trigger_event', 'legacy_frequency', 'sequence', 'is_active',
    ];

    protected $casts = [
        'grace_days' => 'integer',
        'sequence' => 'integer',
        'is_active' => 'boolean',
    ];

    public function aliases(): HasMany
    {
        return $this->hasMany(FrequencyAlias::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Rhythms the nightly generator manufactures instances for. */
    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('generation_mode', 'scheduled');
    }

    public function isScheduled(): bool
    {
        return $this->generation_mode === 'scheduled';
    }

    public function isContinuous(): bool
    {
        return $this->generation_mode === 'continuous';
    }

    public function isEventDriven(): bool
    {
        return $this->generation_mode === 'event';
    }
}
