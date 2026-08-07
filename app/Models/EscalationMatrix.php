<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EscalationMatrix extends Model
{
    use BelongsToTenant, HasFactory;

    public const TIER_ROLES = [
        1 => 'Control Owner',
        2 => 'Line Manager',
        3 => 'Control Function Head',
        4 => 'Executive Viewer',
        5 => 'Board Committee',
    ];

    protected $table = 'escalation_matrices';

    protected $fillable = [
        'tenant_id', 'severity', 'tier_no', 'trigger_condition', 'days_threshold',
        'recipient_role', 'recipient_user_id', 'channel', 'is_active',
    ];

    protected $casts = [
        'tier_no' => 'integer',
        'days_threshold' => 'integer',
        'is_active' => 'boolean',
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
