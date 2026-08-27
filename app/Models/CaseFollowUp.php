<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRichText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Spec §5.4 — one tracked action on a Speak Up submission.
 *
 * Not the investigation's consequence actions. Most concerns never become
 * an investigation, and the ones that do still need their own handling
 * recorded on the intake side: the officer who screened the report is
 * often not on the investigation team, and must be able to work and close
 * out a concern without reaching into the case file.
 *
 * This model carries no visibility scope of its own. It is reachable only
 * through its case, and the case's allowlist scope is what decides who may
 * see it — one rule, in one place, rather than two that can drift.
 */
class CaseFollowUp extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasRichText;

    protected $fillable = [
        'tenant_id', 'case_id', 'action', 'detail', 'owner_id',
        'due_date', 'completed_at', 'completed_by', 'created_by',
    ];

    /** Spec §9 — Editor.js documents in {field}_rich; the plain column is the derived mirror. */
    protected array $richText = ['detail'];

    protected $casts = [
        // A calendar date, serialised as one — see §7.1.
        'due_date' => 'date:Y-m-d',
        'completed_at' => 'datetime',
    ];

    public function speakUpCase(): BelongsTo
    {
        return $this->belongsTo(SpeakUpCase::class, 'case_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    /** Past its date and still open. A completed action is never overdue. */
    public function isOverdue(): bool
    {
        return ! $this->isComplete()
            && $this->due_date !== null
            && $this->due_date->isPast();
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->outstanding()->whereNotNull('due_date')->whereDate('due_date', '<', now()->toDateString());
    }
}
