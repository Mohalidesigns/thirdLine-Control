<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ObligationInstance extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const STATUSES = [
        'Not Started', 'In Progress', 'Submitted', 'Accepted',
        'Rejected', 'Overdue', 'Waived',
    ];

    /** Guarded transitions. Overdue is set by the scheduled generator. */
    public const TRANSITIONS = [
        'Not Started' => ['In Progress', 'Submitted', 'Overdue', 'Waived'],
        'In Progress' => ['Submitted', 'Overdue', 'Waived'],
        'Overdue' => ['In Progress', 'Submitted', 'Waived'],
        'Submitted' => ['Accepted', 'Rejected'],
        'Rejected' => ['In Progress', 'Submitted'],
        'Accepted' => [],
        'Waived' => [],
    ];

    /** Days before the due date at which graduated reminders fire. */
    public const REMINDER_OFFSETS = [90, 60, 30, 14, 7, 3, 1, 0];

    protected $fillable = [
        'tenant_id', 'obligation_id', 'assignment_id', 'period_label',
        'period_start', 'period_end', 'due_at', 'status', 'submitted_at',
        'submitted_by', 'submission_reference', 'acknowledgement_ref',
        'reviewed_by', 'reviewed_at', 'evidence_count', 'days_overdue',
        'penalty_exposure_minor', 'penalty_currency', 'reminders_sent', 'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'due_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'evidence_count' => 'integer',
        'days_overdue' => 'integer',
        'penalty_exposure_minor' => 'integer',
        'reminders_sent' => 'array',
    ];

    protected $appends = ['penalty_exposure'];

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(RegulatoryObligation::class, 'obligation_id')->withoutGlobalScope('tenant');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ObligationAssignment::class, 'assignment_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function evidence(): MorphMany
    {
        return $this->morphMany(Evidence::class, 'linked', 'linked_type', 'linked_id');
    }

    public function penaltyExposureMoney(): ?Money
    {
        if (! $this->penalty_currency) {
            return null;
        }

        return Money::of((int) $this->penalty_exposure_minor, $this->penalty_currency);
    }

    public function getPenaltyExposureAttribute(): ?array
    {
        return $this->penaltyExposureMoney()?->jsonSerialize();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['Not Started', 'In Progress', 'Overdue', 'Rejected']);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', 'Overdue');
    }

    public function scopeDueBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('due_at', [$from, $to]);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['Accepted', 'Waived'], true);
    }
}
