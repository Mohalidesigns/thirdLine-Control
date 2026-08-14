<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorScreening extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const TYPES = ['sanctions', 'pep', 'adverse_media', 'credit', 'litigation'];

    public const RESULTS = ['clear', 'potential_match', 'confirmed_match', 'error'];

    /** Results that require a named human to look at them before they close. */
    public const RESULTS_NEEDING_DISPOSITION = ['potential_match', 'confirmed_match'];

    protected $fillable = [
        'tenant_id', 'vendor_id', 'data_source_id', 'screening_type', 'screened_at',
        'result', 'hit_count', 'hits', 'summary', 'ai_interaction_id',
        'cleared_by', 'cleared_at', 'disposition', 'finding_id',
    ];

    protected $casts = [
        'screened_at' => 'datetime',
        'cleared_at' => 'datetime',
        'hits' => 'array',
        'hit_count' => 'integer',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function aiInteraction(): BelongsTo
    {
        return $this->belongsTo(AiInteraction::class);
    }

    public function clearer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by');
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(VendorFinding::class, 'finding_id');
    }

    /** A hit nobody has dispositioned yet. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('result', self::RESULTS_NEEDING_DISPOSITION)->whereNull('cleared_at');
    }

    public function needsDisposition(): bool
    {
        return in_array($this->result, self::RESULTS_NEEDING_DISPOSITION, true) && $this->cleared_at === null;
    }
}
