<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FrameworkRequirement extends Model
{
    use Auditable, HasFactory;

    public const TYPES = [
        'component', 'principle', 'domain', 'objective',
        'control', 'clause', 'article', 'section',
    ];

    protected $fillable = [
        'framework_id', 'parent_id', 'ref_code', 'title', 'description', 'guidance',
        'level', 'sort_order', 'requirement_type', 'is_testable',
        'suggested_frequency', 'suggested_evidence', 'verification_status', 'source_reference',
    ];

    protected $casts = [
        'level' => 'integer',
        'sort_order' => 'integer',
        'is_testable' => 'boolean',
        'suggested_evidence' => 'array',
    ];

    public function framework(): BelongsTo
    {
        return $this->belongsTo(Framework::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(FrameworkRequirement::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(FrameworkRequirement::class, 'parent_id')->orderBy('sort_order');
    }

    /** Mappings where this requirement is the source. */
    public function outgoingMappings(): HasMany
    {
        return $this->hasMany(FrameworkMapping::class, 'source_requirement_id');
    }

    public function incomingMappings(): HasMany
    {
        return $this->hasMany(FrameworkMapping::class, 'target_requirement_id');
    }

    public function controlMappings(): HasMany
    {
        return $this->hasMany(ControlRequirementMap::class, 'requirement_id');
    }

    public function controls(): BelongsToMany
    {
        return $this->belongsToMany(Control::class, 'control_requirement_map', 'requirement_id', 'control_id')
            ->withPivot(['coverage', 'mapped_by', 'mapped_at', 'approved_by', 'approved_at'])
            ->withTimestamps();
    }

    public function scopeTestable(Builder $query): Builder
    {
        return $query->where('is_testable', true);
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('verification_status', 'verified');
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }
}
