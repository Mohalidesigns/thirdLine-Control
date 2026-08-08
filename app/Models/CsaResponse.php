<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CsaResponse extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const STATUSES = [
        'Not Started', 'In Progress', 'Submitted', 'Under Review', 'Accepted', 'Returned',
    ];

    protected $fillable = [
        'tenant_id', 'campaign_id', 'questionnaire_id', 'respondent_id', 'entity_id',
        'control_id', 'status', 'submitted_at', 'reviewed_by', 'reviewed_at',
        'review_notes', 'score', 'self_rating', 'reviewer_rating', 'variance_flag',
        'proposed_design_rating', 'rating_approved_by', 'rating_approved_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'rating_approved_at' => 'datetime',
        'score' => 'decimal:2',
        'variance_flag' => 'boolean',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CsaCampaign::class, 'campaign_id');
    }

    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(CsaQuestionnaire::class, 'questionnaire_id');
    }

    public function respondent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'respondent_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class, 'entity_id');
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function ratingApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rating_approved_by');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(CsaAnswer::class, 'response_id');
    }

    /**
     * Anonymous survey responses write unattributed audit events — no
     * user id, IP or agent ever reaches storage (9.4).
     */
    public function auditsAnonymously(): bool
    {
        return $this->respondent_id === null
            && (bool) ($this->campaign ?? CsaCampaign::find($this->campaign_id))?->is_anonymous;
    }
}
