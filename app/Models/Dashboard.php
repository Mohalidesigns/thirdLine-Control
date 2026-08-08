<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Dashboard extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    public const VISIBILITIES = ['private', 'role', 'tenant', 'public_link'];

    /** The 12-column grid the builder lays widgets out on. */
    public const GRID_COLUMNS = 12;

    protected $fillable = [
        'tenant_id', 'name', 'slug', 'description', 'owner_id', 'visibility',
        'role_ids', 'public_token', 'layout', 'refresh_interval_seconds',
        'is_default_for_role_id', 'is_system', 'sort_order',
    ];

    protected $hidden = ['public_token'];

    protected $casts = [
        'role_ids' => 'array',
        'layout' => 'array',
        'refresh_interval_seconds' => 'integer',
        'is_system' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function widgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class)->orderBy('sort_order');
    }

    /**
     * Dashboards this user may open. A private board is its owner's alone;
     * a role board is limited to the roles named on it. Membership is not a
     * substitute for permission — every widget is still filtered by the
     * viewer's permissions when its data is resolved (13.2 business rule).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $roleIds = $user->roles->pluck('id')->all();

        return $query->where(function (Builder $q) use ($user, $roleIds) {
            $q->where('visibility', 'tenant')
                ->orWhere(fn (Builder $own) => $own->where('visibility', 'private')->where('owner_id', $user->id));

            foreach ($roleIds as $roleId) {
                $q->orWhere(fn (Builder $role) => $role
                    ->where('visibility', 'role')
                    ->whereJsonContains('role_ids', $roleId));
            }
        });
    }

    /** The board this user lands on: their role default, else the first visible. */
    public static function defaultFor(User $user): ?self
    {
        $roleIds = $user->roles->pluck('id')->all();

        return static::query()
            ->visibleTo($user)
            ->whereIn('is_default_for_role_id', $roleIds ?: [0])
            ->orderBy('sort_order')
            ->first()
            ?? static::query()->visibleTo($user)->orderBy('sort_order')->orderBy('id')->first();
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'dashboard';
        $slug = $base;
        $suffix = 2;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn (Builder $q, int $id) => $q->whereKeyNot($id))
            ->withTrashed()
            ->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
