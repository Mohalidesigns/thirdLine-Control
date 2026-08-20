<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CR-01: the department's answer, one per round. Immutable once submitted
 * (R-F) — enforced at the model layer as the last line of defence.
 */
class ExceptionResponse extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const POSITIONS = [
        'Accepted', 'Partially Accepted', 'Disputed', 'Already Remediated',
        'More Information Required',
    ];

    public const ROOT_CAUSE_CATEGORIES = ['people', 'process', 'technology', 'governance', 'external'];

    public const REVIEW_STATUSES = ['Pending Review', 'Accepted', 'Rejected', 'Superseded'];

    protected $fillable = [
        'tenant_id', 'escalation_id', 'exception_id', 'round_no',
        'responder_id', 'responder_name', 'responder_email',
        'submitted_at', 'channel', 'position', 'management_comment',
        'root_cause', 'root_cause_category', 'agreed_action_plan',
        'proposed_target_date', 'is_late',
        'review_status', 'reviewed_by', 'reviewed_at', 'review_note',
        'rejection_reason', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'round_no' => 'integer',
        'submitted_at' => 'datetime',
        'proposed_target_date' => 'date',
        'is_late' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    /**
     * A submitted response is the department's on-the-record answer: the
     * substance can never be edited afterwards, only reviewed (R-F).
     */
    public static function booted(): void
    {
        static::updating(function (ExceptionResponse $response) {
            if (! $response->getOriginal('submitted_at')) {
                return;
            }

            $reviewOnly = [
                'review_status', 'reviewed_by', 'reviewed_at', 'review_note', 'rejection_reason', 'updated_at',
            ];

            foreach (array_keys($response->getDirty()) as $field) {
                if (! in_array($field, $reviewOnly, true)) {
                    throw new \LogicException(
                        "Exception response #{$response->id} was submitted and is immutable — '{$field}' cannot change."
                    );
                }
            }
        });
    }

    public function escalation(): BelongsTo
    {
        return $this->belongsTo(ExceptionEscalation::class, 'escalation_id');
    }

    public function exception(): BelongsTo
    {
        return $this->belongsTo(ControlException::class, 'exception_id');
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responder_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
