<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenantOrGlobal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sustainability topic (17.3). Shipped IFRS S1/S2 rows are global
 * (tenant_id NULL); a tenant adds its own. Nothing here is authoritative
 * until somebody has checked it against the standard — hence
 * `verification_status` on every row (R10).
 */
class SustainabilityTopic extends Model
{
    use Auditable, BelongsToTenantOrGlobal, HasFactory;

    public const PILLARS = ['environmental', 'social', 'governance'];

    public const PILLAR_LABELS = [
        'environmental' => 'Environmental',
        'social' => 'Social',
        'governance' => 'Governance',
    ];

    protected $fillable = [
        'tenant_id', 'code', 'title', 'description', 'pillar',
        'standard_reference', 'standard', 'verification_status',
        'verification_note', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function materialityAssessments(): HasMany
    {
        return $this->hasMany(MaterialityAssessment::class, 'topic_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('verification_status', 'verified');
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    public function pillarLabel(): string
    {
        return self::PILLAR_LABELS[$this->pillar] ?? $this->pillar;
    }
}
