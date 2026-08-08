<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesReference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PolicyException extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, SoftDeletes;

    public const STATUSES = ['Requested', 'Under Review', 'Approved', 'Rejected', 'Expired', 'Revoked'];

    public const OPEN_STATUSES = ['Requested', 'Under Review', 'Approved'];

    public const TRANSITIONS = [
        'Requested' => ['Under Review', 'Approved', 'Rejected'],
        'Under Review' => ['Approved', 'Rejected'],
        'Approved' => ['Expired', 'Revoked'],
        'Rejected' => [],
        'Expired' => [],
        'Revoked' => [],
    ];

    protected $fillable = [
        'tenant_id', 'reference', 'policy_id', 'requested_by', 'entity_id',
        'justification', 'risk_assessment', 'compensating_measures',
        'requested_from', 'requested_to', 'status', 'approved_by', 'approved_at',
        'review_at', 'decision_notes', 'revoked_reason',
    ];

    protected $casts = [
        'requested_from' => 'date',
        'requested_to' => 'date',
        'review_at' => 'date',
        'approved_at' => 'datetime',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class, 'entity_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', 'Approved')
            ->whereDate('requested_to', '>=', now()->toDateString());
    }

    public function isExpired(): bool
    {
        return $this->status === 'Approved'
            && $this->requested_to !== null
            && $this->requested_to->isBefore(now()->startOfDay());
    }
}
