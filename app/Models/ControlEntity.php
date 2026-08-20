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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A row of the control universe (CR2-A): a Head Office department, an IS
 * control domain, a branch, or an activity under a branch. Distinct from
 * organisation_units (the operational tree, which branch rows bridge via
 * organisation_unit_id) and from entities (the Phase-16 legal-entity
 * register) — never conflate the three in code or copy.
 */
class ControlEntity extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, HasRichText;

    public const KINDS = ['department', 'domain', 'branch', 'activity'];

    public const RISK_RATINGS = ['Critical', 'High', 'Medium', 'Low'];

    public const REVIEW_FREQUENCIES = ['monthly', 'quarterly', 'semiannual', 'annual'];

    protected $fillable = [
        'tenant_id', 'control_unit_id', 'parent_id', 'reference', 'name',
        'description', 'description_rich', 'entity_kind', 'organisation_unit_id',
        'business_process_id', 'owner_id', 'risk_rating', 'review_frequency',
        'last_reviewed_at', 'next_review_due_at', 'is_template', 'sequence', 'is_active',
    ];

    /** Editor.js-backed fields — see HasRichText. */
    protected array $richText = ['description'];

    protected $casts = [
        'last_reviewed_at' => 'date',
        'next_review_due_at' => 'date',
        'is_template' => 'boolean',
        'sequence' => 'integer',
        'is_active' => 'boolean',
    ];

    public function controlUnit(): BelongsTo
    {
        return $this->belongsTo(ControlUnit::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ControlEntity::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ControlEntity::class, 'parent_id');
    }

    /** The bridge to the operational tree — never a replacement for it (R-A). */
    public function organisationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class);
    }

    public function businessProcess(): BelongsTo
    {
        return $this->belongsTo(BusinessProcess::class);
    }

    /** The second-line relationship officer. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** The controls this entity oversees, through the structure pivot. */
    public function controls(): BelongsToMany
    {
        return $this->belongsToMany(Control::class, 'control_entity_control')
            ->withPivot(['id', 'tenant_id', 'is_key'])
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_template', false);
    }

    public function scopeTemplates(Builder $query): Builder
    {
        return $query->where('is_template', true);
    }

    public function isBranch(): bool
    {
        return $this->entity_kind === 'branch';
    }
}
