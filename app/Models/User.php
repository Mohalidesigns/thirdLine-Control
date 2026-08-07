<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
        'position',
        'unit_id',
        'reports_to',
        'is_active',
        'is_break_glass',
        'low_bandwidth_mode',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'mfa_secret',
        'mfa_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_break_glass' => 'boolean',
            'low_bandwidth_mode' => 'boolean',
            'mfa_secret' => 'encrypted',
            'mfa_confirmed_at' => 'datetime',
            'mfa_recovery_codes' => 'encrypted:array',
        ];
    }

    public function hasMfaEnabled(): bool
    {
        return $this->mfa_confirmed_at !== null && $this->mfa_secret !== null;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class, 'unit_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reports_to');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(User::class, 'reports_to');
    }

    /**
     * Second-line control function membership — the only users who may
     * verify and close exceptions or approve controls and ratings.
     */
    public function isControlFunction(): bool
    {
        return $this->hasAnyRole(['Control Function Head', 'Control Officer']);
    }

    public function isLeadership(): bool
    {
        return $this->hasAnyRole(['System Administrator', 'Control Function Head', 'Executive Viewer']);
    }
}
