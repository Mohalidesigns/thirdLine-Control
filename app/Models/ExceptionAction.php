<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesReference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CR-01: the remediation a department committed to in an accepted response.
 * Owned by the department; validated by the control function before the
 * escalation can close.
 */
class ExceptionAction extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory;

    public const STATUSES = ['Not Started', 'In Progress', 'Completed', 'Overdue', 'Cancelled'];

    public const ACTION_TYPES = ['corrective', 'preventive', 'detective'];

    public const VALIDATION_STATUSES = ['Effective', 'Partially Effective', 'Ineffective'];

    protected $fillable = [
        'tenant_id', 'exception_id', 'escalation_id', 'response_id',
        'reference', 'title', 'description', 'action_type',
        'owner_id', 'unit_id', 'target_date', 'revised_target_date',
        'extension_reason', 'extension_approved_by',
        'status', 'progress_percentage', 'progress_note',
        'completed_at', 'completed_by',
        'validation_status', 'validated_by', 'validated_at', 'validation_note',
    ];

    protected $casts = [
        'target_date' => 'date',
        'revised_target_date' => 'date',
        'progress_percentage' => 'decimal:2',
        'completed_at' => 'datetime',
        'validated_at' => 'datetime',
    ];

    public function exception(): BelongsTo
    {
        return $this->belongsTo(ControlException::class, 'exception_id');
    }

    public function escalation(): BelongsTo
    {
        return $this->belongsTo(ExceptionEscalation::class, 'escalation_id');
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(ExceptionResponse::class, 'response_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class, 'unit_id');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['Completed', 'Cancelled']);
    }
}
