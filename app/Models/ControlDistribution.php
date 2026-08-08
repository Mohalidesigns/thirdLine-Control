<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ControlDistribution extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const STATUSES = [
        'Pending', 'Acknowledged', 'In Progress', 'Completed', 'Decline Requested', 'Declined',
    ];

    protected $fillable = [
        'tenant_id', 'control_id', 'entity_id', 'child_control_id', 'assigned_owner_id',
        'distributed_by', 'distributed_at', 'due_at', 'status', 'acknowledged_at',
        'completed_at', 'local_adaptations', 'decline_reason',
        'decline_approved_by', 'decline_approved_at', 'progress',
    ];

    protected $casts = [
        'distributed_at' => 'datetime',
        'due_at' => 'date',
        'acknowledged_at' => 'datetime',
        'completed_at' => 'datetime',
        'decline_approved_at' => 'datetime',
        'progress' => 'integer',
    ];

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class, 'entity_id');
    }

    public function childControl(): BelongsTo
    {
        return $this->belongsTo(Control::class, 'child_control_id');
    }

    public function assignedOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_owner_id');
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'distributed_by');
    }

    public function declineApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decline_approved_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(DistributionTask::class, 'distribution_id')->orderBy('sequence');
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['Completed', 'Declined']);
    }

    /** A declined control still appears in coverage reporting — as a gap. */
    public function isGap(): bool
    {
        return $this->status === 'Declined';
    }
}
