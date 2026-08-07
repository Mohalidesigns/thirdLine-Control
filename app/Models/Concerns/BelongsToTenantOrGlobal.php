<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For catalogue tables that hold both shipped content (tenant_id NULL —
 * frameworks, requirements, obligations installed from a content pack) and
 * tenant-authored or tenant-customised rows. A tenant sees the global
 * catalogue plus its own records, never another tenant's.
 *
 * Unlike BelongsToTenant this trait never stamps tenant_id on create: a
 * record is global unless the caller says otherwise, so the content-pack
 * installer can seed the catalogue without an authenticated user.
 */
trait BelongsToTenantOrGlobal
{
    public static function bootBelongsToTenantOrGlobal(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if ($tenantId = static::currentTenantId()) {
                $table = $builder->getModel()->getTable();

                $builder->where(fn (Builder $q) => $q
                    ->where($table.'.tenant_id', $tenantId)
                    ->orWhereNull($table.'.tenant_id'));
            }
        });
    }

    public static function currentTenantId(): ?int
    {
        return auth()->user()?->tenant_id;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Shipped catalogue content, identical for every tenant. */
    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull($query->getModel()->getTable().'.tenant_id');
    }

    /** Rows the tenant authored or customised — these win over shipped content. */
    public function scopeTenantOwned(Builder $query): Builder
    {
        return $query->whereNotNull($query->getModel()->getTable().'.tenant_id');
    }

    public function isGlobal(): bool
    {
        return $this->tenant_id === null;
    }
}
