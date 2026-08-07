<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedView extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'resource',
        'name',
        'filters',
        'columns',
        'sort',
        'is_shared',
        'is_default',
    ];

    protected $casts = [
        'filters' => 'array',
        'columns' => 'array',
        'sort' => 'array',
        'is_shared' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Views visible to a user for a resource: their own, plus views shared
     * by colleagues holding at least one of the same roles.
     */
    public function scopeVisibleTo(Builder $query, User $user, string $resource): Builder
    {
        $roleNames = $user->getRoleNames();

        return $query->where('resource', $resource)
            ->where(fn (Builder $q) => $q
                ->where('user_id', $user->id)
                ->orWhere(fn (Builder $shared) => $shared
                    ->where('is_shared', true)
                    ->whereHas('user.roles', fn ($r) => $r->whereIn('name', $roleNames))));
    }
}
