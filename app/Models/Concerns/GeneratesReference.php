<?php

namespace App\Models\Concerns;

/**
 * Human-readable business keys per the ThirdLine standard §9.2:
 * PREFIX-[YEAR-]NNN, tenant-scoped, rendered in font-mono in the UI.
 */
trait GeneratesReference
{
    public static function nextReference(string $prefix, bool $withYear = true, string $column = 'reference'): string
    {
        $year = now()->year;
        $stem = $withYear ? "{$prefix}-{$year}-" : "{$prefix}-";

        $last = static::withoutGlobalScopes()
            ->where($column, 'like', $stem.'%')
            ->when(auth()->user()?->tenant_id, fn ($q, $t) => $q->where('tenant_id', $t))
            ->orderByDesc('id')
            ->value($column);

        $next = $last ? ((int) substr($last, strlen($stem))) + 1 : 1;

        return $stem.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
}
