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

class SubmissionPack extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory;

    public const STATUSES = [
        'Draft', 'Under Review', 'Approved', 'Submitted',
        'Acknowledged', 'Rejected', 'Resubmitted',
    ];

    /**
     * Maker-checker, spelled out. A pack moves forward only along these
     * edges, and the person moving it is checked against the people who
     * moved it before (R2).
     */
    public const TRANSITIONS = [
        'Draft' => ['Under Review'],
        'Under Review' => ['Approved', 'Draft'],
        'Approved' => ['Submitted', 'Draft'],
        'Submitted' => ['Acknowledged', 'Rejected'],
        'Rejected' => ['Resubmitted'],
        'Resubmitted' => ['Acknowledged', 'Rejected'],
        'Acknowledged' => [],
    ];

    protected $fillable = [
        'tenant_id', 'obligation_instance_id', 'pack_ref', 'pack_type', 'period_label',
        'status', 'generated_at', 'generated_by', 'content', 'evidence_ids',
        'completeness_score', 'unverified_items', 'validation_errors',
        'reviewed_by', 'reviewed_at', 'approved_by', 'approved_at', 'locked_at',
        'submitted_at', 'submitted_by', 'submission_reference', 'acknowledgement_path',
        'acknowledgement_ref', 'acknowledged_at', 'rejection_reason', 'report_run_id',
        'checksum', 'supersedes_id', 'version_no',
    ];

    protected $casts = [
        'content' => 'array',
        'evidence_ids' => 'array',
        'unverified_items' => 'array',
        'validation_errors' => 'array',
        'generated_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'locked_at' => 'datetime',
        'submitted_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'completeness_score' => 'integer',
        'version_no' => 'integer',
    ];

    public function obligationInstance(): BelongsTo
    {
        return $this->belongsTo(ObligationInstance::class, 'obligation_instance_id');
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reportRun(): BelongsTo
    {
        return $this->belongsTo(ReportRun::class, 'report_run_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function supersededBy(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['Draft', 'Under Review', 'Approved', 'Rejected']);
    }

    /** Approved and beyond: the document is evidence now and never changes. */
    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }
}
