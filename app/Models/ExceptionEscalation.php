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

/**
 * CR-01: the formal, addressed instrument the control function issues to a
 * department, processor or external party. Append-mostly (R-F): rounds are
 * never edited or deleted, only superseded.
 */
class ExceptionEscalation extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory;

    public const STATUSES = [
        'Draft', 'Issued', 'Acknowledged', 'Responded', 'Under Review',
        'Accepted', 'Rejected', 'Reissued', 'Closed', 'Withdrawn',
    ];

    /** Statuses that block Verified-Closed on the parent exception (R-D). */
    public const OPEN_STATUSES = [
        'Draft', 'Issued', 'Acknowledged', 'Responded', 'Under Review',
        'Accepted', 'Rejected', 'Reissued',
    ];

    /** Statuses in which the addressed department still owes an answer. */
    public const AWAITING_RESPONSE_STATUSES = ['Issued', 'Acknowledged', 'Reissued'];

    public const TARGET_TYPES = ['unit', 'process', 'user', 'external_party'];

    public const REQUIRED_RESPONSES = ['root_cause', 'action_plan', 'both', 'confirmation'];

    protected $fillable = [
        'tenant_id', 'exception_id', 'reference', 'round_no', 'target_type',
        'unit_id', 'process_id', 'respondent_id',
        'external_name', 'external_email', 'external_organisation',
        'cc_user_ids', 'severity', 'issued_by', 'issued_at', 'issue_note',
        'required_response', 'acknowledge_due_at', 'response_due_at', 'sla_policy_id',
        'status', 'acknowledged_at', 'acknowledged_by',
        'first_response_at', 'last_response_at',
        'is_acknowledgement_late', 'is_response_late',
        'reminder_count', 'last_reminder_at', 'matrix_escalated_at',
        'closed_at', 'closed_by', 'closure_note',
        'withdrawn_at', 'withdrawn_by', 'withdrawal_reason',
        'notified_to', 'superseded_by_escalation_id',
    ];

    protected $casts = [
        'round_no' => 'integer',
        'cc_user_ids' => 'array',
        'issued_at' => 'datetime',
        'acknowledge_due_at' => 'datetime',
        'response_due_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'first_response_at' => 'datetime',
        'last_response_at' => 'datetime',
        'is_acknowledgement_late' => 'boolean',
        'is_response_late' => 'boolean',
        'reminder_count' => 'integer',
        'last_reminder_at' => 'datetime',
        'matrix_escalated_at' => 'datetime',
        'closed_at' => 'datetime',
        'withdrawn_at' => 'datetime',
        'notified_to' => 'array',
    ];

    public function exception(): BelongsTo
    {
        return $this->belongsTo(ControlException::class, 'exception_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class, 'unit_id');
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(BusinessProcess::class, 'process_id');
    }

    public function respondent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'respondent_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function withdrawer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }

    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(ExceptionSlaPolicy::class, 'sla_policy_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(ExceptionResponse::class, 'escalation_id')->orderBy('round_no');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ExceptionAction::class, 'escalation_id');
    }

    public function responseLinks(): HasMany
    {
        return $this->hasMany(ExceptionResponseLink::class, 'escalation_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isAwaitingResponse(): bool
    {
        return in_array($this->status, self::AWAITING_RESPONSE_STATUSES, true);
    }

    public function isExternal(): bool
    {
        return $this->target_type === 'external_party';
    }

    /** The department the escalation is addressed to, for display. */
    public function targetLabel(): string
    {
        return match ($this->target_type) {
            'unit' => $this->unit?->name ?? 'Unit',
            'process' => $this->process?->name ?? 'Process',
            'user' => $this->respondent?->name ?? 'User',
            'external_party' => trim(($this->external_organisation ?: $this->external_name) ?? 'External party'),
        };
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeAwaitingResponse(Builder $query): Builder
    {
        return $query->whereIn('status', self::AWAITING_RESPONSE_STATUSES);
    }

    public function scopeResponseOverdue(Builder $query): Builder
    {
        return $query->awaitingResponse()
            ->whereNotNull('response_due_at')
            ->where('response_due_at', '<', now());
    }
}
