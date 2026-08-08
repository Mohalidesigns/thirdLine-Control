<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A regulatory notification an incident owes. The window is never stored:
 * due_at is recomputed from the obligation's due_rule every time the
 * resolver runs, so editing the obligation moves the countdown (R1).
 */
class IncidentNotification extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const STATUSES = ['Pending', 'Notified', 'Not Required', 'Waived'];

    protected $fillable = [
        'tenant_id', 'incident_id', 'obligation_id', 'regulator_id', 'entity_id',
        'due_at', 'status', 'is_required', 'notified_at', 'notification_reference',
        'notified_by', 'notes', 'verification_status',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'notified_at' => 'datetime',
        'is_required' => 'boolean',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(RegulatoryObligation::class, 'obligation_id');
    }

    public function regulator(): BelongsTo
    {
        return $this->belongsTo(Regulator::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class, 'entity_id');
    }

    public function notifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notified_by');
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('is_required', true)->where('status', 'Pending');
    }

    public function isBreached(): bool
    {
        return $this->status === 'Pending'
            && $this->is_required
            && $this->due_at !== null
            && $this->due_at->isPast();
    }

    /** Seconds remaining on the wall clock; negative once the window closes. */
    public function secondsRemaining(): ?int
    {
        return $this->due_at ? (int) now()->diffInSeconds($this->due_at, false) : null;
    }
}
