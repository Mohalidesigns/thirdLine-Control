<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    use BelongsToTenant;

    public const CHANNELS = ['in_app', 'email', 'whatsapp', 'sms', 'push'];

    public const DIGEST_FREQUENCIES = ['immediate', 'daily', 'weekly'];

    protected $fillable = [
        'tenant_id',
        'user_id',
        'event_key',
        'channel',
        'is_enabled',
        'digest_frequency',
        'quiet_hours_start',
        'quiet_hours_end',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
