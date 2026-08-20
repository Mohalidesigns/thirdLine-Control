<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Attestation extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const METHODS = ['web', 'mobile', 'whatsapp', 'email', 'ussd', 'offline'];

    protected $fillable = [
        'tenant_id', 'attestable_type', 'attestable_id', 'user_id', 'campaign_id',
        'attested_at', 'attestation_text_snapshot', 'ip_address', 'user_agent',
        'method', 'version_attested',
    ];

    protected $casts = [
        'attested_at' => 'datetime',
    ];

    public function attestable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CsaCampaign::class, 'campaign_id');
    }
}
