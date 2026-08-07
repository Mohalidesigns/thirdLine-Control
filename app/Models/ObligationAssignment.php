<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ObligationAssignment extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'obligation_id', 'entity_id', 'owner_id', 'reviewer_id',
        'is_applicable', 'non_applicability_reason', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'is_applicable' => 'boolean',
        'approved_at' => 'datetime',
    ];

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(RegulatoryObligation::class, 'obligation_id')->withoutGlobalScope('tenant');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class, 'entity_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(ObligationInstance::class, 'assignment_id');
    }

    /** Only applicable assignments generate calendar instances. */
    public function scopeApplicable(Builder $query): Builder
    {
        return $query->where('is_applicable', true);
    }

    /**
     * A non-applicability declaration only takes effect once approved —
     * an unapproved declaration keeps generating instances.
     */
    public function generatesInstances(): bool
    {
        return $this->is_applicable || $this->approved_at === null;
    }
}
