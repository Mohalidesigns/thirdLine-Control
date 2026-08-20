<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * CR-01 (R1 / R-E): the ONLY place an Exception Manager SLA lives. A
 * reviewer finding a day count in a PHP class must move it here.
 */
class ExceptionSlaPolicy extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'name', 'severity',
        'acknowledge_within_hours', 'respond_within_days',
        'reminder_offsets', 'max_reminders', 'escalate_after_days',
        'auto_escalate_to_matrix', 'is_active',
    ];

    protected $casts = [
        'acknowledge_within_hours' => 'integer',
        'respond_within_days' => 'integer',
        'reminder_offsets' => 'array',
        'max_reminders' => 'integer',
        'escalate_after_days' => 'integer',
        'auto_escalate_to_matrix' => 'boolean',
        'is_active' => 'boolean',
    ];

    /** The active policy for a severity within a tenant. */
    public static function activeFor(int $tenantId, string $severity): ?self
    {
        return static::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('severity', $severity)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }
}
