<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRichText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MetricValue extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasRichText;

    protected $fillable = [
        'tenant_id', 'metric_id', 'period_label', 'period_start', 'period_end',
        'value', 'target', 'threshold_level_hit', 'breach_flag', 'variance_from_target',
        'source', 'captured_by', 'captured_at', 'approved_by', 'comment',
        'supporting_evidence_id',
    ];

    /** Spec §9 — Editor.js documents in {field}_rich; the plain column is the derived mirror. */
    protected array $richText = ['comment'];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'value' => 'float',
        'target' => 'float',
        'variance_from_target' => 'float',
        'breach_flag' => 'boolean',
        'captured_at' => 'datetime',
    ];

    public function metric(): BelongsTo
    {
        return $this->belongsTo(Metric::class);
    }

    public function capturer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Evidence::class, 'supporting_evidence_id');
    }

    public function breach(): HasOne
    {
        return $this->hasOne(MetricBreach::class);
    }
}
