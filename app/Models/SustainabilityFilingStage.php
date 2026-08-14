<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SustainabilityFilingStage extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const STATUSES = ['Pending', 'In Progress', 'Submitted', 'Late', 'Waived'];

    public const OPEN_STATUSES = ['Pending', 'In Progress', 'Late'];

    protected $fillable = [
        'tenant_id', 'filing_id', 'stage_key', 'label', 'offset_months', 'due_at',
        'submitted_at', 'submitted_by', 'submission_reference', 'evidence_id',
        'status', 'note',
    ];

    protected $casts = [
        'due_at' => 'date',
        'submitted_at' => 'datetime',
        'offset_months' => 'integer',
    ];

    public function filing(): BelongsTo
    {
        return $this->belongsTo(SustainabilityFiling::class, 'filing_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Evidence::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES)
            ->whereDate('due_at', '<', now()->toDateString());
    }

    public function daysToDue(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->due_at->startOfDay(), false);
    }
}
