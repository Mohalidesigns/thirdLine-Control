<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes every query and creation to the authenticated user's tenant.
 * The deployment model is branch-per-client, so a single active tenant
 * per installation is the norm — the scope is defence in depth.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if ($tenantId = static::currentTenantId()) {
                $builder->where($builder->getModel()->getTable().'.tenant_id', $tenantId);
            }
        });

        static::creating(function (Model $model) {
            if (! $model->tenant_id && ($tenantId = static::currentTenantId())) {
                $model->tenant_id = $tenantId;
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
}
