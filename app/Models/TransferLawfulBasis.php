<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenantOrGlobal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransferLawfulBasis extends Model
{
    use Auditable, BelongsToTenantOrGlobal, HasFactory;

    protected $table = 'transfer_lawful_bases';

    protected $fillable = [
        'tenant_id', 'code', 'name', 'description', 'legal_reference',
        'effective_from', 'effective_to', 'verification_status',
        'is_active', 'sort_order',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Bases a transfer may currently rest on. */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', today()))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', today()));
    }
}
