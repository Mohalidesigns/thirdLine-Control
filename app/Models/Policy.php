<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesReference;
use App\Models\Concerns\HasVersions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Policy extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, HasVersions, SoftDeletes;

    protected array $versioned = [
        'title', 'description', 'policy_type', 'category_id', 'owner_id',
        'status', 'effective_from', 'effective_to', 'review_frequency',
        'applies_to', 'requires_attestation', 'mandatory_training',
    ];

    public const TYPES = ['policy', 'procedure', 'standard', 'guideline', 'charter', 'code'];

    public const STATUSES = [
        'Draft', 'Under Review', 'Pending Approval', 'Approved',
        'Published', 'Under Revision', 'Superseded', 'Withdrawn',
    ];

    /** A published policy is the one that governs; the rest are history or drafts. */
    public const LIVE_STATUSES = ['Published'];

    public const REVIEW_FREQUENCIES = [
        'none', 'monthly', 'quarterly', 'semi_annual', 'annual', 'biennial', 'triennial',
    ];

    public const ATTESTATION_FREQUENCIES = [
        'on_publication', 'annual', 'semi_annual', 'quarterly', 'on_change',
    ];

    /**
     * Guarded lifecycle (11.1). Superseded and Withdrawn are terminal.
     *
     * Under Review is an optional consultation stage, not a control: the
     * control is that the approver is never the owner, and it applies
     * whether or not a policy was circulated first. A short standard can
     * therefore go straight from Draft to Pending Approval.
     */
    public const TRANSITIONS = [
        'Draft' => ['Under Review', 'Pending Approval', 'Withdrawn'],
        'Under Review' => ['Pending Approval', 'Draft', 'Withdrawn'],
        'Pending Approval' => ['Approved', 'Draft', 'Withdrawn'],
        'Approved' => ['Published', 'Draft', 'Withdrawn'],
        'Published' => ['Under Revision', 'Superseded', 'Withdrawn'],
        'Under Revision' => ['Pending Approval', 'Published', 'Withdrawn'],
        'Superseded' => [],
        'Withdrawn' => [],
    ];

    protected $fillable = [
        'tenant_id', 'policy_ref', 'title', 'description', 'policy_type',
        'category_id', 'document_id', 'owner_id', 'approver_id', 'version_no',
        'status', 'effective_from', 'effective_to', 'review_frequency',
        'next_review_at', 'applies_to', 'requires_attestation', 'attestation_frequency',
        'supersedes_policy_id', 'attestation_campaign_id', 'approved_at',
        'published_at', 'withdrawal_reason', 'mandatory_training',
    ];

    protected $casts = [
        'version_no' => 'integer',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'next_review_at' => 'date',
        'applies_to' => 'array',
        'requires_attestation' => 'boolean',
        'mandatory_training' => 'boolean',
        'approved_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(PolicyCategory::class, 'category_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_policy_id');
    }

    public function attestationCampaign(): BelongsTo
    {
        return $this->belongsTo(CsaCampaign::class, 'attestation_campaign_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(PolicySection::class)->orderBy('sequence');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(PolicyException::class);
    }

    public function attestations(): MorphMany
    {
        return $this->morphMany(Attestation::class, 'attestable');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'Published');
    }

    public function scopeDueForReview(Builder $query, int $withinDays = 0): Builder
    {
        return $query->published()
            ->whereNotNull('next_review_at')
            ->whereDate('next_review_at', '<=', now()->addDays($withinDays)->toDateString());
    }

    /** In force on the given date — publication plus the effective window. */
    public function isInForce(?string $onDate = null): bool
    {
        $date = $onDate ?? now()->toDateString();

        return $this->status === 'Published'
            && ($this->effective_from === null || $this->effective_from->toDateString() <= $date)
            && ($this->effective_to === null || $this->effective_to->toDateString() >= $date);
    }

    /** Every requirement any section of this policy claims to govern. */
    public function governedRequirementIds(): array
    {
        return $this->sections
            ->flatMap(fn (PolicySection $section) => $section->requirement_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** The text a user actually attests to — headings and bodies, in order. */
    public function attestationText(): string
    {
        $body = $this->sections
            ->map(fn (PolicySection $s) => trim($s->heading."\n".(string) $s->body))
            ->implode("\n\n");

        return trim($this->title."\n\n".(string) $this->description."\n\n".$body);
    }
}
