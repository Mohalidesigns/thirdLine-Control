<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenantOrGlobal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiskCategory extends Model
{
    use Auditable, BelongsToTenantOrGlobal, HasFactory;

    protected $fillable = [
        'tenant_id', 'parent_id', 'code', 'name', 'description', 'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function risks(): HasMany
    {
        return $this->hasMany(Risk::class, 'risk_category_id');
    }

    public function appetites(): HasMany
    {
        return $this->hasMany(RiskAppetite::class, 'risk_category_id');
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id')->orderBy('sort_order');
    }

    /** Self plus every descendant id — used to roll risks up a branch. */
    public function descendantIds(): array
    {
        $ids = [$this->id];

        foreach ($this->children as $child) {
            $ids = [...$ids, ...$child->descendantIds()];
        }

        return $ids;
    }
}
