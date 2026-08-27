<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesReference;
use App\Models\Concerns\HasRichText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SustainabilityFiling extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, HasRichText, SoftDeletes;

    public const REPORTING_CLASSES = ['pie', 'sme', 'public_sector', 'other'];

    public const REPORTING_CLASS_LABELS = [
        'pie' => 'Public interest entity',
        'sme' => 'SME',
        'public_sector' => 'Public sector',
        'other' => 'Other',
    ];

    public const STATUSES = ['Planned', 'In Progress', 'Complete', 'Cancelled'];

    protected $fillable = [
        'tenant_id', 'entity_id', 'regulator_id', 'reference', 'reporting_class',
        'adoption_year', 'fiscal_year_start', 'period_label', 'filing_stage_offsets',
        'verification_status', 'verification_note', 'owner_id', 'status',
        'submission_pack_id',
    ];

    /** Spec §9 — Editor.js documents in {field}_rich; the plain column is the derived mirror. */
    protected array $richText = ['verification_note'];

    protected $casts = [
        'fiscal_year_start' => 'date',
        'filing_stage_offsets' => 'array',
        'adoption_year' => 'integer',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function regulator(): BelongsTo
    {
        return $this->belongsTo(Regulator::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function submissionPack(): BelongsTo
    {
        return $this->belongsTo(SubmissionPack::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(SustainabilityFilingStage::class, 'filing_id')->orderBy('due_at');
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    public function reportingClassLabel(): string
    {
        return self::REPORTING_CLASS_LABELS[$this->reporting_class] ?? $this->reporting_class;
    }
}
