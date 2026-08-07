<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class IntegrationConfig extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'target_system', 'base_url', 'auth_type',
        'credentials_encrypted', 'inbound_key_hash', 'sync_direction',
        'master_system', 'conflict_rule', 'entities_enabled', 'is_active',
    ];

    protected $casts = [
        'entities_enabled' => 'array',
        'is_active' => 'boolean',
    ];

    protected $hidden = ['credentials_encrypted', 'inbound_key_hash'];

    /** Outbound credentials are encrypted at rest and never logged (FR-11.6). */
    protected function credentials(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->credentials_encrypted ? Crypt::decryptString($this->credentials_encrypted) : null,
            set: fn (?string $value) => ['credentials_encrypted' => $value ? Crypt::encryptString($value) : null],
        );
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(IntegrationSyncLog::class, 'config_id');
    }
}
