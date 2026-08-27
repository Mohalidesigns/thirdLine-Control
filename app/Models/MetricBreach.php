<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRichText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricBreach extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasRichText;

    public const LEVELS = ['Amber', 'Red', 'Critical'];

    protected $fillable = [
        'tenant_id', 'metric_id', 'metric_value_id', 'level', 'detected_at',
        'acknowledged_by', 'acknowledged_at', 'root_cause', 'action_taken',
        'resolved_at', 'exception_id', 'incident_id', 'escalation_event_id',
    ];

    /** Spec §9 — Editor.js documents in {field}_rich; the plain column is the derived mirror. */
    protected array $richText = ['action_taken'];

    protected $casts = [
        'detected_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function metric(): BelongsTo
    {
        return $this->belongsTo(Metric::class);
    }

    public function metricValue(): BelongsTo
    {
        return $this->belongsTo(MetricValue::class);
    }

    public function exception(): BelongsTo
    {
        return $this->belongsTo(ControlException::class, 'exception_id');
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }
}
