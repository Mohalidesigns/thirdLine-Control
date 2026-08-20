<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesReference;
use App\Models\Concerns\HasVersions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A continuous test of a control against extracted data (12.3).
 *
 * Every rule type is data-driven: `rule_definition` is JSON validated
 * against the type's schema in RuleEngineService, so adding a threshold
 * or a reconciliation is configuration, not a release (R1).
 */
class MonitoringRule extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, HasVersions, SoftDeletes;

    public const TYPES = [
        'threshold', 'reconciliation', 'duplicate', 'gap', 'sod_conflict',
        'exception_list', 'completeness', 'timeliness', 'trend', 'pattern', 'custom_sql',
    ];

    public const FREQUENCIES = ['Hourly', 'Daily', 'Weekly', 'Monthly', 'Quarterly', 'Ad hoc'];

    public const STATUSES = ['Draft', 'Pending Approval', 'Active', 'Paused', 'Retired'];

    /**
     * Changing any of these on a live rule is a change to what the control
     * actually tests, so it drops the rule back to Pending Approval and a
     * second person has to look at it again (12.4, R2).
     */
    public const APPROVAL_SENSITIVE = [
        'rule_type', 'dataset_ids', 'rule_definition', 'sample_config', 'tolerance',
        'severity', 'control_id', 'auto_create_exception', 'auto_create_incident',
    ];

    protected $fillable = [
        'tenant_id', 'control_id', 'rule_ref', 'name', 'description', 'rule_type',
        'dataset_ids', 'rule_definition', 'severity', 'frequency', 'schedule_cron',
        'sample_config', 'tolerance', 'exception_template', 'auto_create_exception',
        'auto_create_incident', 'owner_id', 'created_by', 'approved_by', 'approved_at',
        'status', 'version_no', 'last_run_at', 'next_run_at',
    ];

    protected $casts = [
        'dataset_ids' => 'array',
        'rule_definition' => 'array',
        'sample_config' => 'array',
        'tolerance' => 'array',
        'exception_template' => 'array',
        'auto_create_exception' => 'boolean',
        'auto_create_incident' => 'boolean',
        'approved_at' => 'datetime',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
        'version_no' => 'integer',
    ];

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(MonitoringRun::class, 'rule_id');
    }

    public function findings(): HasManyThrough
    {
        return $this->hasManyThrough(MonitoringFinding::class, MonitoringRun::class, 'rule_id', 'run_id');
    }

    /** @return Collection<int, DataSourceDataset> */
    public function datasets()
    {
        return DataSourceDataset::query()
            ->whereIn('id', (array) ($this->dataset_ids ?? []))
            ->with('dataSource')
            ->get();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'Active');
    }

    public function scopeDue(Builder $query, ?\DateTimeInterface $asOf = null): Builder
    {
        return $query->active()->where(function (Builder $q) use ($asOf) {
            $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', $asOf ?? now());
        });
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null && $this->approved_by !== null;
    }
}
