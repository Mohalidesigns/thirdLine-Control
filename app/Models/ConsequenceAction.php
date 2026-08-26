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

/**
 * What was done about it (CR-04 §C.5) — the disciplinary, recovery and
 * regulatory consequences that follow a finding.
 *
 * The recommender never approves their own recommendation; that rule is
 * enforced in ConsequenceService::approve() rather than here, because it
 * is a workflow rule and needs to produce a validation message a person
 * can read.
 */
class ConsequenceAction extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, SoftDeletes;

    public const ACTION_TYPES = [
        'query_issued', 'warning_letter', 'suspension', 'demotion', 'dismissal',
        'restitution_recovery', 'prosecution_police_report', 'regulatory_report',
        'training_counselling', 'process_change', 'no_action',
    ];

    public const STATUSES = ['recommended', 'approved', 'in_progress', 'implemented', 'rejected'];

    /** Types that bear on a person's employment and need a named subject. */
    public const PERSONAL_ACTION_TYPES = [
        'query_issued', 'warning_letter', 'suspension', 'demotion', 'dismissal',
        'restitution_recovery', 'prosecution_police_report', 'training_counselling',
    ];

    protected $fillable = [
        'tenant_id', 'investigation_id', 'investigation_subject_id', 'reference',
        'action_type', 'description', 'status',
        'recommended_by', 'recommended_on', 'approved_by', 'approved_on', 'rejection_reason',
        'due_date', 'implemented_on', 'implemented_by', 'implementation_note',
        'amount_recovered', 'evidence_id', 'improvement_action_id',
    ];

    protected $casts = [
        'recommended_on' => 'date',
        'approved_on' => 'date',
        'due_date' => 'date',
        'implemented_on' => 'date',
        'amount_recovered' => 'decimal:2',
    ];

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(InvestigationSubject::class, 'investigation_subject_id');
    }

    public function recommender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recommended_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function implementer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'implemented_by');
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Evidence::class);
    }

    public function improvementAction(): BelongsTo
    {
        return $this->belongsTo(ImprovementAction::class);
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', ['recommended', 'approved', 'in_progress']);
    }

    /** Approved or in progress, past its date, and still not done. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereIn('status', ['approved', 'in_progress'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString());
    }
}
