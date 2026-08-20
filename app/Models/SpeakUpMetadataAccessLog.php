<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The immutable Metadata Access Log (CR): every reveal request, decision
 * and Tier 2 view. Append-only — no UPDATED_AT, and no application path
 * mutates or deletes a row. Readable by the Head of Control and audit
 * ('speak_up.metadata.audit_log'), and by ThirdLine over the integration
 * API.
 */
class SpeakUpMetadataAccessLog extends Model
{
    use BelongsToTenant, HasFactory;

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    public const ACTIONS = ['requested', 'approved', 'denied', 'revealed', 'purged'];

    protected $fillable = [
        'tenant_id', 'report_id', 'reveal_request_id', 'action', 'requested_by',
        'approved_by', 'reason_code', 'justification', 'fields_revealed', 'occurred_at',
    ];

    protected $casts = [
        'fields_revealed' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(InvestigationCase::class, 'report_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
