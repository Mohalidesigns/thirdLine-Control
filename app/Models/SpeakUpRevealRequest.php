<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A break-glass request to see Tier 2 (identifying) reporter metadata (CR).
 * Second-person approval, never self-approved; an approval expires after
 * config('speakup.reveal_validity_hours') rather than standing forever.
 */
class SpeakUpRevealRequest extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const STATUSES = ['pending', 'approved', 'denied', 'expired'];

    protected $fillable = [
        'tenant_id', 'report_id', 'requested_by', 'reason_code', 'justification',
        'status', 'decided_by', 'decision_note', 'decided_at', 'expires_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(InvestigationCase::class, 'report_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** Approved, not lapsed — the only state that opens Tier 2. */
    public function isUsable(): bool
    {
        return $this->status === 'approved'
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
