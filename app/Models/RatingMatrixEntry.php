<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RatingMatrixEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'design_effectiveness', 'operating_effectiveness', 'overall_rating',
    ];

    /**
     * Resolve the overall rating for a design/operating pair, preferring a
     * tenant override over the system default (FR-7.4).
     */
    public static function resolve(string $design, string $operating, ?int $tenantId = null): string
    {
        $entry = static::query()
            ->where('design_effectiveness', $design)
            ->where('operating_effectiveness', $operating)
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->orderByRaw('tenant_id IS NULL')
            ->first();

        return $entry?->overall_rating ?? 'Not Tested';
    }
}
