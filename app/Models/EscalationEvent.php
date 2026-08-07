<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EscalationEvent extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'exception_id', 'test_instance_id', 'matrix_id', 'tier_no',
        'recipient_user_id', 'channel', 'triggered_at', 'delivery_status', 'payload_summary',
    ];

    protected $casts = [
        'tier_no' => 'integer',
        'triggered_at' => 'datetime',
    ];

    public function exception(): BelongsTo
    {
        return $this->belongsTo(ControlException::class, 'exception_id');
    }

    public function testInstance(): BelongsTo
    {
        return $this->belongsTo(TestInstance::class);
    }

    public function matrix(): BelongsTo
    {
        return $this->belongsTo(EscalationMatrix::class, 'matrix_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
