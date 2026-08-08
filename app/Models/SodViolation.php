<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SodViolation extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const STATUSES = ['Open', 'Mitigated', 'Accepted', 'Remediated', 'False Positive'];

    protected $fillable = [
        'tenant_id', 'rule_id', 'subject_identifier', 'subject_name', 'entity_id',
        'detected_at', 'snapshot_id', 'status', 'mitigation_notes', 'accepted_by',
        'accepted_until', 'remediated_at', 'exception_id',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
        'remediated_at' => 'datetime',
        'accepted_until' => 'date',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(SodConflictRule::class, 'rule_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class, 'entity_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(DataSnapshot::class, 'snapshot_id');
    }

    public function acceptor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function exception(): BelongsTo
    {
        return $this->belongsTo(ControlException::class, 'exception_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'Open');
    }
}
