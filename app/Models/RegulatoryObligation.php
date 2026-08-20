<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenantOrGlobal;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RegulatoryObligation extends Model
{
    use Auditable, BelongsToTenantOrGlobal, HasFactory, SoftDeletes;

    public const TYPES = [
        'filing', 'disclosure', 'notification', 'approval', 'registration',
        'payment', 'assessment', 'training', 'retention', 'operational',
    ];

    public const TRIGGER_TYPES = ['calendar', 'event', 'threshold', 'on_demand'];

    public const FREQUENCIES = [
        'one_off', 'daily', 'weekly', 'monthly', 'quarterly',
        'semi_annual', 'annual', 'biennial', 'triennial', 'event_driven',
    ];

    public const PENALTY_BASES = ['fixed', 'per_day', 'per_week', 'per_instance', 'percentage'];

    protected $fillable = [
        'tenant_id', 'regulator_id', 'framework_id', 'requirement_id', 'obligation_ref',
        'title', 'description', 'obligation_type', 'applies_to', 'trigger_type',
        'frequency', 'due_rule', 'grace_period_days', 'penalty_description',
        'penalty_amount_minor', 'penalty_fixed_amount_minor', 'penalty_currency',
        'penalty_basis', 'legal_reference',
        'source_url', 'effective_from', 'effective_to', 'verification_status', 'is_active',
    ];

    protected $casts = [
        'applies_to' => 'array',
        'due_rule' => 'array',
        'grace_period_days' => 'integer',
        'penalty_amount_minor' => 'integer',
        'penalty_fixed_amount_minor' => 'integer',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    protected $appends = ['penalty'];

    public function regulator(): BelongsTo
    {
        return $this->belongsTo(Regulator::class);
    }

    public function framework(): BelongsTo
    {
        return $this->belongsTo(Framework::class);
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(FrameworkRequirement::class, 'requirement_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ObligationAssignment::class, 'obligation_id');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(ObligationInstance::class, 'obligation_id');
    }

    /** R7: never a float — integer minor units plus an ISO-4217 code. */
    public function penaltyMoney(): ?Money
    {
        if ($this->penalty_amount_minor === null || ! $this->penalty_currency) {
            return null;
        }

        return Money::of((int) $this->penalty_amount_minor, $this->penalty_currency);
    }

    public function getPenaltyAttribute(): ?array
    {
        return $this->penaltyMoney()?->jsonSerialize();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** R10: only verified obligations may enter a generated submission. */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('verification_status', 'verified');
    }

    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }
}
