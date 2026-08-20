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

class MonitoringRun extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory;

    public const STATUSES = ['Queued', 'Running', 'Completed', 'Failed', 'Partial'];

    protected $fillable = [
        'tenant_id', 'rule_id', 'run_ref', 'started_at', 'completed_at', 'status',
        'records_evaluated', 'records_passed', 'records_failed', 'exception_rate',
        'result_summary', 'error_message', 'snapshot_ids', 'test_instance_id',
        'sampling_seed', 'duration_ms', 'trigger', 'triggered_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'result_summary' => 'array',
        'snapshot_ids' => 'array',
        'records_evaluated' => 'integer',
        'records_passed' => 'integer',
        'records_failed' => 'integer',
        'exception_rate' => 'float',
        'sampling_seed' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(MonitoringRule::class, 'rule_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(MonitoringFinding::class, 'run_id');
    }

    public function testInstance(): BelongsTo
    {
        return $this->belongsTo(TestInstance::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereIn('status', ['Completed', 'Partial']);
    }
}
