<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesReference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ImprovementAction extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, SoftDeletes;

    public const SOURCES = ['test', 'csa', 'spot_check', 'incident', 'audit', 'exception', 'survey', 'manual'];

    public const PRIORITIES = ['Low', 'Medium', 'High', 'Critical'];

    public const STATUSES = ['Proposed', 'Approved', 'In Progress', 'Implemented', 'Verified', 'Rejected'];

    protected $fillable = [
        'tenant_id', 'reference', 'source_type', 'source_id', 'title', 'description',
        'category', 'priority', 'owner_id', 'due_at', 'status', 'approved_by',
        'verified_by', 'verified_at', 'benefit_description', 'effort_estimate',
        'control_id', 'risk_id',
    ];

    protected $casts = [
        'due_at' => 'date',
        'verified_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['Verified', 'Rejected']);
    }
}
