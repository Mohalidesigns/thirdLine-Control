<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringFinding extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const STATUSES = [
        'Open', 'Under Review', 'Confirmed', 'False Positive', 'Remediated', 'Accepted',
    ];

    /** Statuses that still need somebody to look at them. */
    public const OPEN_STATUSES = ['Open', 'Under Review'];

    protected $fillable = [
        'tenant_id', 'run_id', 'record_identifier', 'record_data', 'failure_reason',
        'severity', 'status', 'reviewed_by', 'reviewed_at', 'review_notes',
        'exception_id', 'incident_id',
    ];

    protected $casts = [
        'record_data' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(MonitoringRun::class, 'run_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function exception(): BelongsTo
    {
        return $this->belongsTo(ControlException::class, 'exception_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }
}
