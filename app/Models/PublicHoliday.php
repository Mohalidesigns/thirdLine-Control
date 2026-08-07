<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenantOrGlobal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PublicHoliday extends Model
{
    use Auditable, BelongsToTenantOrGlobal, HasFactory;

    protected $fillable = [
        'tenant_id', 'jurisdiction', 'holiday_date', 'name', 'is_recurring', 'source',
    ];

    protected $casts = [
        'holiday_date' => 'date',
        'is_recurring' => 'boolean',
    ];
}
