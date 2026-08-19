<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CR-01 (R-H): a hashed, expiring, revocable answer link for a respondent
 * outside the system. The plaintext token is shown once at generation and
 * never persisted; only the sha256 lives here.
 */
class ExceptionResponseLink extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const STATUSES = ['active', 'used', 'revoked', 'expired'];

    protected $fillable = [
        'tenant_id', 'escalation_id', 'token_hash', 'token_prefix',
        'created_by', 'expires_at', 'revoked_at', 'revoked_by', 'status',
        'access_count', 'last_accessed_at', 'submitted_at',
        'ip_address', 'user_agent',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'access_count' => 'integer',
        'last_accessed_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    protected $hidden = ['token_hash'];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function escalation(): BelongsTo
    {
        return $this->belongsTo(ExceptionEscalation::class, 'escalation_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Usable right now: active, not revoked, not past expiry. */
    public function isUsable(): bool
    {
        return $this->status === 'active'
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }
}
