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

class VendorFinding extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, SoftDeletes;

    public const SEVERITIES = ['Critical', 'High', 'Medium', 'Low'];

    public const STATUSES = ['Open', 'In Remediation', 'Accepted', 'Closed'];

    public const OPEN_STATUSES = ['Open', 'In Remediation'];

    protected $fillable = [
        'tenant_id', 'vendor_id', 'assessment_id', 'reference', 'title',
        'description', 'severity', 'status', 'owner_id', 'due_date',
        'improvement_action_id', 'resolution', 'closed_at', 'closed_by', 'raised_by',
    ];

    protected $casts = [
        'due_date' => 'date',
        'closed_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(VendorAssessment::class, 'assessment_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function improvementAction(): BelongsTo
    {
        return $this->belongsTo(ImprovementAction::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true)
            && $this->due_date !== null
            && $this->due_date->isPast();
    }
}
