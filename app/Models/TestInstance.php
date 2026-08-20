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
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'due_date' => 'date',
        'is_ad_hoc' => 'boolean',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

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
            ->whereDate('due_date', '<', now());
    }
}
