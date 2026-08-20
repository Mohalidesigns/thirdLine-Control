<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesReference;
use App\Models\Concerns\HasRichText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ControlException extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, HasRichText, SoftDeletes;

    public const STATUSES = ['Open', 'Assigned', 'In Progress', 'Remediated', 'Verified-Closed', 'Risk Accepted'];

    public const SEVERITIES = ['Critical', 'High', 'Medium', 'Low'];

    public const OPEN_STATUSES = ['Open', 'Assigned', 'In Progress', 'Remediated'];

    protected $table = 'control_exceptions';

    protected $fillable = [
        'tenant_id', 'reference', 'source_type', 'source_id', 'external_ref', 'control_id', 'risk_id',
        'check_result_id', 'title', 'description', 'root_cause', 'severity',
        'owner_id', 'responsible_party_id', 'unit_id', 'raised_by',
        'date_raised', 'target_closure_date', 'status', 'remediation_plan',
        'remediated_at', 'remediated_by', 'verification_method', 'verified_by',
        'verified_at', 'closure_notes', 'risk_acceptance_reason', 'risk_accepted_by',
        'risk_acceptance_expiry', 'age_days', 'is_overdue', 'is_recurring',
        'recurrence_of_exception_id', 'extension_count',
        // CR-01 denormalised caches — maintained by ExceptionEscalationService,
        // never the source of truth (the exception_escalations rows are).
        'escalation_status', 'first_escalated_at', 'last_response_at',
        'open_escalation_count', 'is_response_overdue', 'closure_type',
    ];

    /** Editor.js-backed fields — see HasRichText. */
    protected array $richText = ['description', 'root_cause', 'remediation_plan', 'closure_notes', 'risk_acceptance_reason'];

    protected $casts = [
        'date_raised' => 'date',
        'target_closure_date' => 'date',
        'remediated_at' => 'datetime',
        'verified_at' => 'datetime',
        'risk_acceptance_expiry' => 'date',
        'age_days' => 'integer',
        'is_overdue' => 'boolean',
        'is_recurring' => 'boolean',
        'extension_count' => 'integer',
        'first_escalated_at' => 'datetime',
        'last_response_at' => 'datetime',
        'open_escalation_count' => 'integer',
        'is_response_overdue' => 'boolean',
    ];

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function checkResult(): BelongsTo
    {
        return $this->belongsTo(CheckResult::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function responsibleParty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_party_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class, 'unit_id');
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ExceptionActivity::class, 'exception_id')->latest();
    }

    public function compensatingControls(): HasMany
    {
        return $this->hasMany(CompensatingControl::class, 'exception_id');
    }

    public function escalationEvents(): HasMany
    {
        return $this->hasMany(EscalationEvent::class, 'exception_id');
    }

    public function recurrenceOf(): BelongsTo
    {
        return $this->belongsTo(ControlException::class, 'recurrence_of_exception_id');
    }

    /** CR-01: the addressed departmental escalations issued on this exception. */
    public function escalations(): HasMany
    {
        return $this->hasMany(ExceptionEscalation::class, 'exception_id');
    }

    public function escalationResponses(): HasMany
    {
        return $this->hasMany(ExceptionResponse::class, 'exception_id');
    }

    public function escalationActions(): HasMany
    {
        return $this->hasMany(ExceptionAction::class, 'exception_id');
    }

    /** CR-01 (R-D): escalations that still block Verified-Closed. */
    public function openEscalations(): HasMany
    {
        return $this->escalations()->whereIn('status', ExceptionEscalation::OPEN_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->where('is_overdue', true);
    }

    public function logActivity(string $type, ?string $from = null, ?string $to = null, ?string $note = null): ExceptionActivity
    {
        return $this->activities()->create([
            'activity_type' => $type,
            'actor_id' => auth()->id(),
            'from_value' => $from,
            'to_value' => $to,
            'note' => $note,
        ]);
    }
}
