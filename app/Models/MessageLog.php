<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Delivery log for every WhatsApp/SMS/Push message (15.3/15.4). This IS
 * the audit record for outbound messaging, so it does not use Auditable —
 * mirroring every log row into audit_trails would double-write. It never
 * stores a full phone number (masked only) and an anonymised inbound
 * stores no recipient at all.
 */
class MessageLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'user_id', 'channel', 'direction', 'template_key',
        'recipient_masked', 'status', 'window', 'provider_message_id',
        'error', 'segments', 'cost_minor', 'cost_currency',
        'was_anonymised', 'meta', 'delivered_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'was_anonymised' => 'boolean',
        'delivered_at' => 'datetime',
        'cost_minor' => 'integer',
        'segments' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** "+2348012345678" → "***678": enough to recognise, never to dial. */
    public static function mask(?string $recipient): ?string
    {
        if ($recipient === null || $recipient === '') {
            return null;
        }

        return '***'.substr($recipient, -3);
    }
}
