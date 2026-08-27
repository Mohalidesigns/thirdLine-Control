<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Spec §5.3 — an investigation report moving through review.
 *
 * Draft → Manager Review → Group Head Internal Control Review → Approved
 * → Issued, and no other path. The map below is the whole workflow;
 * `returnTo` is the only way backwards and it always lands on `draft`,
 * because a report sent back is a report the preparer must re-submit, not
 * one that resumes halfway up the chain.
 *
 * Issued is terminal. Once a report is issued its `snapshot` is the
 * document: the case may keep moving, but -R01 says what it said on the
 * day it was signed. Anything else is a later version.
 */
class InvestigationReport extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    public const STATES = ['draft', 'manager_review', 'ghic_review', 'approved', 'issued'];

    /**
     * Forward transitions, and the permission each one needs.
     *
     * The specification lists a `review_ghic` permission and an `approve`
     * permission as separate things. They are the same transition — at the
     * GHIC review node the available action IS "Approve" — so this is one
     * permission, named for the act rather than the node.
     */
    public const TRANSITIONS = [
        'draft' => ['manager_review' => 'edit investigations'],
        'manager_review' => ['ghic_review' => 'review investigation-reports'],
        'ghic_review' => ['approved' => 'approve investigation-reports'],
        'approved' => ['issued' => 'issue investigation-reports'],
        'issued' => [],
    ];

    /** States a reviewer may send back to the preparer from. */
    public const RETURNABLE = ['manager_review', 'ghic_review'];

    protected $fillable = [
        'tenant_id', 'investigation_id', 'report_number', 'version',
        'workflow_state', 'report_run_id', 'prepared_by_id',
        'manager_reviewed_by_id', 'manager_reviewed_at', 'manager_comment',
        'ghic_reviewed_by_id', 'ghic_reviewed_at', 'ghic_comment',
        'approved_by_id', 'approved_at', 'issued_at', 'issue_date',
        'snapshot', 'returned_reason', 'created_by',
    ];

    protected $casts = [
        'version' => 'integer',
        'manager_reviewed_at' => 'datetime',
        'ghic_reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'issued_at' => 'datetime',
        // §7.1 — a calendar date, serialised as one.
        'issue_date' => 'date:Y-m-d',
        'snapshot' => 'array',
    ];

    // ── Relations ────────────────────────────────────────────────────────

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ReportRun::class, 'report_run_id');
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by_id');
    }

    public function managerReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_reviewed_by_id');
    }

    public function ghicReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ghic_reviewed_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    // ── State ────────────────────────────────────────────────────────────

    public function isIssued(): bool
    {
        return $this->workflow_state === 'issued';
    }

    /** Everything except an issued report may still change. */
    public function isEditable(): bool
    {
        return ! $this->isIssued();
    }

    public function mayTransitionTo(string $state): bool
    {
        return array_key_exists($state, self::TRANSITIONS[$this->workflow_state] ?? []);
    }

    public function permissionFor(string $state): ?string
    {
        return self::TRANSITIONS[$this->workflow_state][$state] ?? null;
    }

    public function isReturnable(): bool
    {
        return in_array($this->workflow_state, self::RETURNABLE, true);
    }

    /**
     * The report number for the next version of a case's report.
     * INV-2026-001-R01, -R02, and so on.
     */
    public static function numberFor(Investigation $investigation, int $version): string
    {
        return sprintf('%s-R%02d', $investigation->reference, $version);
    }
}
