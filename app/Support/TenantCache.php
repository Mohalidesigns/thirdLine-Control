<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Tenant-scoped caching (16.3). Every key carries the tenant id so two
 * tenants can never read each other's aggregates, and invalidation is
 * explicit — a service that writes calls forget() for the keys it knows
 * it stales. Works identically on Redis in production and the array
 * store under test.
 */
final class TenantCache
{
    public static function key(?int $tenantId, string $suffix): string
    {
        return 'tenant:'.($tenantId ?? 'global').':'.$suffix;
    }

    public static function remember(?int $tenantId, string $suffix, int $seconds, Closure $callback): mixed
    {
        return Cache::remember(self::key($tenantId, $suffix), $seconds, $callback);
    }

    public static function forget(?int $tenantId, string ...$suffixes): void
    {
        foreach ($suffixes as $suffix) {
            Cache::forget(self::key($tenantId, $suffix));
        }
    }
}
