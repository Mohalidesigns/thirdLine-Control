<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Tracks the WhatsApp 24-hour customer-service window per recipient
 * (15.3). Phone numbers are salted-hashed on the way in; the raw number
 * never touches this table.
 */
class WhatsappSession extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'phone_hash', 'last_inbound_at',
    ];

    protected $casts = [
        'last_inbound_at' => 'datetime',
    ];

    public static function hashPhone(string $phone): string
    {
        return hash('sha256', config('app.key').'|whatsapp-session|'.$phone);
    }
}
