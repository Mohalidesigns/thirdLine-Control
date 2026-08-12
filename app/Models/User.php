<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'phone',
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

    public function routeNotificationForWhatsapp(): ?string
    {
        return $this->phone;
    }

    public function routeNotificationForSms(): ?string
    {
        return $this->phone;
    }

    public function hasMfaEnabled(): bool
    {
        return $this->mfa_confirmed_at !== null && $this->mfa_secret !== null;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Legal entities this user was explicitly granted (16.1). Absence of a
     * grant is denial — group-level visibility is never implicit (R2).
     */
    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(Entity::class)
            ->withPivot(['granted_by', 'note', 'created_at']);
    }

    /** @return array<int, int> */
    public function accessibleEntityIds(): array
    {
        return $this->entities()->pluck('entities.id')->all();
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

    public function csaResponses(): HasMany
    {
        return $this->hasMany(CsaResponse::class, 'respondent_id');
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
