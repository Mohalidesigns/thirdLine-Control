<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenantOrGlobal;
use App\Models\Concerns\HasVersions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Framework extends Model
{
    use Auditable, BelongsToTenantOrGlobal, HasFactory, HasVersions, SoftDeletes;

    public const CATEGORIES = [
        'internal_control', 'risk', 'security', 'privacy', 'governance',
        'financial', 'sustainability', 'sector',
    ];

    public const VERIFICATION_STATUSES = ['verified', 'unverified', 'draft'];

    protected $fillable = [
        'tenant_id', 'code', 'name', 'issuing_body', 'jurisdiction', 'version',
        'category', 'effective_from', 'effective_to', 'supersedes_framework_id',
        'is_certifiable', 'source_url', 'verification_status', 'verified_by',
        'verified_at', 'description', 'is_active',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_certifiable' => 'boolean',
        'is_active' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function requirements(): HasMany
    {
        return $this->hasMany(FrameworkRequirement::class);
    }

    /** Top-level nodes of the requirement tree. */
    public function rootRequirements(): HasMany
    {
        return $this->hasMany(FrameworkRequirement::class)->whereNull('parent_id')->orderBy('sort_order');
    }

    public function obligations(): HasMany
    {
        return $this->hasMany(RegulatoryObligation::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(Framework::class, 'supersedes_framework_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** R10: only verified content may enter a generated regulatory submission. */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('verification_status', 'verified');
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }
}
