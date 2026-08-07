<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * FX reference rates. Rows with tenant_id NULL are platform reference data;
 * a tenant-scoped row for the same pair and date overrides it. Deliberately
 * NOT BelongsToTenant — reference rows must stay visible to every tenant,
 * so resolution scopes explicitly instead of via the global scope.
 */
class ExchangeRate extends Model
{
    use Auditable;

    protected $fillable = [
        'tenant_id',
        'base_currency',
        'quote_currency',
        'rate',
        'effective_date',
        'source',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'rate' => 'string', // decimal(20,10) — kept as string so it never becomes a float
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Latest rate for a pair on or before the given date. A tenant override
     * wins over the platform reference rate for the same pair.
     */
    public static function resolve(string $base, string $quote, ?Carbon $on = null, ?int $tenantId = null): ?string
    {
        $on ??= now();
        $tenantId ??= auth()->user()?->tenant_id;

        if ($base === $quote) {
            return '1';
        }

        return static::query()
            ->where('base_currency', strtoupper($base))
            ->where('quote_currency', strtoupper($quote))
            ->whereDate('effective_date', '<=', $on)
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->orderByRaw('tenant_id is null')   // tenant rows first
            ->orderByDesc('effective_date')
            ->value('rate');
    }
}
