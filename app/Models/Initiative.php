<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesReference;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A project delivering a strategic objective (17.1). */
class Initiative extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, SoftDeletes;

    public const STATUSES = ['Proposed', 'Approved', 'In Progress', 'On Hold', 'Delivered', 'Cancelled'];

    /** Statuses that still contribute to an objective's roll-up. */
    public const LIVE_STATUSES = ['Proposed', 'Approved', 'In Progress', 'On Hold', 'Delivered'];

    public const PROGRESS_MODES = ['auto', 'manual'];

    protected $fillable = [
        'tenant_id', 'objective_id', 'entity_id', 'reference', 'title', 'description',
        'owner_id', 'status', 'start_date', 'end_date', 'budget_minor', 'spend_minor',
        'currency', 'progress', 'progress_mode', 'weight', 'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'budget_minor' => 'integer',
        'spend_minor' => 'integer',
        'progress' => 'integer',
        'weight' => 'integer',
    ];

    public function objective(): BelongsTo
    {
        return $this->belongsTo(Objective::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(InitiativeMilestone::class)->orderBy('sort_order');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', self::LIVE_STATUSES);
    }

    public function budgetMoney(): ?Money
    {
        return $this->budget_minor === null ? null : Money::of((int) $this->budget_minor, $this->currency);
    }

    public function spendMoney(): ?Money
    {
        return $this->spend_minor === null ? null : Money::of((int) $this->spend_minor, $this->currency);
    }

    /** Spend as a percentage of budget; null when no budget was set. */
    public function budgetUtilisation(): ?int
    {
        if (! $this->budget_minor) {
            return null;
        }

        return (int) round(((int) $this->spend_minor) / $this->budget_minor * 100);
    }
}
