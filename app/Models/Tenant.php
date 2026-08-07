<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'licence_key',
        'data_residency',
        'status',
        'settings',
        'locale',
        'timezone',
        'currency',
        'date_format',
        'fiscal_year_start_month',
        'mfa_enforced_roles',
        'mfa_grace_period_days',
        'mfa_enforced_at',
        'mfa_sms_opt_in',
        'break_glass_daily_cap',
    ];

    protected $casts = [
        'settings' => 'array',
        'fiscal_year_start_month' => 'integer',
        'mfa_enforced_roles' => 'array',
        'mfa_grace_period_days' => 'integer',
        'mfa_enforced_at' => 'datetime',
        'mfa_sms_opt_in' => 'boolean',
        'break_glass_daily_cap' => 'integer',
    ];

    /**
     * Localisation bundle shared with the frontend (R7): every date and
     * money value renders per tenant locale — never assumed USD or UTC.
     */
    public function localisation(): array
    {
        return [
            'locale' => $this->locale ?? 'en-NG',
            'timezone' => $this->timezone ?? 'Africa/Lagos',
            'currency' => $this->currency ?? 'NGN',
            'date_format' => $this->date_format ?? 'DD MMM YYYY',
            'fiscal_year_start_month' => $this->fiscal_year_start_month ?? 1,
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
