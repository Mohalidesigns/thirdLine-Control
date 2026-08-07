<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Global reference data — regulators are shipped in content packs and are
 * identical for every tenant, so this table carries no tenant_id.
 */
class Regulator extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'code', 'name', 'jurisdiction', 'sector', 'website', 'portal_url',
        'contact_details', 'feed_url', 'is_active',
    ];

    protected $casts = [
        'sector' => 'array',
        'contact_details' => 'array',
        'is_active' => 'boolean',
    ];

    public function obligations(): HasMany
    {
        return $this->hasMany(RegulatoryObligation::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(RegulatoryChange::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
