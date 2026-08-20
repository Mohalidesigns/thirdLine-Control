<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRichText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sub-unit of the Internal Control function (CR2-A): Head Office
 * Control, Information Systems Control, Branch Control, plus whatever a
 * tenant adds. Behaviour switches on `domain`, never on the name (R-B).
 */
class ControlUnit extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasRichText;

    public const DOMAINS = ['head_office', 'information_systems', 'branch', 'other'];

    protected $fillable = [
        'tenant_id', 'code', 'name', 'domain', 'description', 'description_rich',
        'head_user_id', 'sequence', 'is_active',
    ];

    /** Editor.js-backed fields — see HasRichText. */
    protected array $richText = ['description'];

    protected $casts = [
        'sequence' => 'integer',
        'is_active' => 'boolean',
    ];

    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    public function entities(): HasMany
    {
        return $this->hasMany(ControlEntity::class);
    }

    /** The register rows: active, non-template. */
    public function activeEntities(): HasMany
    {
        return $this->entities()->where('is_active', true)->where('is_template', false);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isBranchDomain(): bool
    {
        return $this->domain === 'branch';
    }
}
