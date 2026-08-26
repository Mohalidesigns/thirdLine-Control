<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesReference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class TestInstance extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, SoftDeletes;

    public const STATUSES = ['Scheduled', 'In Progress', 'Submitted', 'Reviewed', 'Closed', 'Reopened'];

    protected $fillable = [
        'tenant_id', 'control_id', 'test_script_id', 'reference', 'period_label',
        'period_start', 'period_end', 'due_date', 'assigned_tester_id', 'reviewer_id',
        'status', 'is_ad_hoc', 'population_size', 'sample_size', 'sampling_method',
        'sample_items', 'review_notes', 'reopen_reason',
        'started_at', 'submitted_at', 'reviewed_at',
        // Phase 12 — a continuous rule materialises a real test instance
        // rather than a shadow record, so the source is recorded on it.
        'source', 'monitoring_rule_id',
        // CR-03 §C.3 — which desk or branch this occurrence belongs to,
        // and which rhythm produced it. scope_key is derived, never
        // assigned: see the saving hook below.
        'control_entity_id', 'frequency_id', 'period_year',
        'trigger_event', 'trigger_context',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'due_date' => 'date',
        'is_ad_hoc' => 'boolean',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'period_year' => 'integer',
        'trigger_context' => 'array',
    ];

    /**
     * CR-03 §C.3: scope_key and period_year are DERIVED. MySQL does not
     * collide NULLs inside a unique index, so a nullable
     * control_entity_id alone would let the nightly job write a second
     * global instance for a period it already generated. Deriving the key
     * on save is what keeps generation idempotent, and it is the reason
     * nothing outside this hook may write the column.
     */
    protected static function booted(): void
    {
        static::saving(function (self $instance) {
            $instance->scope_key = $instance->control_entity_id
                ? 'e'.$instance->control_entity_id
                : 'global';

            $instance->period_year = $instance->period_end
                ? (int) $instance->period_end->format('Y')
                : null;
        });
    }

    protected $appends = ['is_overdue'];

    public function getIsOverdueAttribute(): bool
    {
        return ! in_array($this->status, ['Reviewed', 'Closed'], true)
            && $this->due_date !== null
            && $this->due_date->isPast();
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    /** The desk or branch this occurrence belongs to (CR-03 §C.3). */
    public function controlEntity(): BelongsTo
    {
        return $this->belongsTo(ControlEntity::class);
    }

    /** The rhythm that produced this occurrence. */
    public function frequency(): BelongsTo
    {
        return $this->belongsTo(ControlFrequency::class, 'frequency_id');
    }

    public function testScript(): BelongsTo
    {
        return $this->belongsTo(TestScript::class);
    }

    public function tester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_tester_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** The continuous rule that produced this instance, where one did (12.4). */
    public function monitoringRule(): BelongsTo
    {
        return $this->belongsTo(MonitoringRule::class, 'monitoring_rule_id');
    }

    public function isAutomated(): bool
    {
        return $this->source === 'automated';
    }

    public function checkResults(): HasMany
    {
        return $this->hasMany(CheckResult::class);
    }

    public function effectivenessRating(): HasOne
    {
        return $this->hasOne(EffectivenessRating::class);
    }

    public function isLocked(): bool
    {
        return in_array($this->status, ['Reviewed', 'Closed'], true);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['Reviewed', 'Closed'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now());
    }

    /** Instances belonging to a given desk or branch (CR-03 §C.3). */
    public function scopeForEntity(Builder $query, int|array $entityIds): Builder
    {
        return $query->whereIn('control_entity_id', (array) $entityIds);
    }

    /**
     * A continuous observation task: it has a reporting window but no
     * deadline, and closes when it rolls, not when a period ends (§C.5).
     */
    public function isContinuous(): bool
    {
        return $this->due_date === null && $this->frequency?->isContinuous() === true;
    }
}
