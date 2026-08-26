<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The case diary (CR-04 §C.6), and the source of the report's Chronology.
 *
 * The TYPES / MANUAL_TYPES split is the substance of this model: six types
 * a human may log, eight the service writes as a by-product of the
 * workflow. Without it the diary becomes a free-text notes field and the
 * chronology stops being evidence of what happened.
 */
class InvestigationActivity extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const TYPES = [
        'case_created', 'status_changed', 'team_assigned', 'interview_conducted',
        'evidence_collected', 'document_requested', 'site_visit', 'finding_added',
        'report_issued', 'action_recommended', 'case_completed', 'case_archived',
        'comment', 'confidential_view',
    ];

    /** The only types a request may supply; everything else is written by the service. */
    public const MANUAL_TYPES = [
        'interview_conducted', 'evidence_collected', 'document_requested',
        'site_visit', 'report_issued', 'comment',
    ];

    protected $fillable = [
        'tenant_id', 'investigation_id', 'activity_type', 'title', 'description',
        'activity_date', 'performed_by', 'linked_type', 'linked_id',
    ];

    protected $casts = ['activity_date' => 'datetime'];

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function linked(): MorphTo
    {
        return $this->morphTo('linked', 'linked_type', 'linked_id');
    }
}
