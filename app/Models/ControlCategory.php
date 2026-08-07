<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ControlCategory extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'description', 'is_system',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    /**
     * System categories (tenant_id null) are visible to every tenant
     * alongside the tenant's own categories (FR-1.4).
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('tenant_id')
                ->orWhere('tenant_id', auth()->user()?->tenant_id);
        });
    }

    public function controls(): HasMany
    {
        return $this->hasMany(Control::class, 'category_id');
    }
}
