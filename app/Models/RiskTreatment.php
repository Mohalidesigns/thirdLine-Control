<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesReference;
use App\Models\Concerns\HasRichText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RiskTreatment extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, HasRichText, SoftDeletes;

    public const STRATEGIES = ['Avoid', 'Reduce', 'Transfer', 'Accept', 'Exploit'];

    public const STATUSES = [
        'Proposed', 'Approved', 'In Progress', 'Implemented', 'Verified', 'Overdue', 'Cancelled',
    ];

    public const OPEN_STATUSES = ['Proposed', 'Approved', 'In Progress', 'Implemented', 'Overdue'];

    protected $fillable = [
        'tenant_id', 'reference', 'risk_id', 'strategy', 'title', 'description',
        'owner_id', 'approver_id', 'target_rating', 'cost_minor', 'currency',
        'benefit_description', 'start_at', 'due_at', 'status', 'progress',
        'last_update_at', 'verification_notes', 'verified_by', 'verified_at',
        'control_id', 'acceptance_reason', 'accepted_by', 'acceptance_expiry',
        'alerts_enabled', 'alert_lead_days', 'last_alert_at',
    ];

    /** Editor.js-backed fields — see HasRichText. */
    protected array $richText = ['acceptance_reason', 'benefit_description', 'description', 'verification_notes'];

    protected $casts = [
        'start_at' => 'date',
        'due_at' => 'date',
        'acceptance_expiry' => 'date',
        'last_update_at' => 'datetime',
        'verified_at' => 'datetime',
        'last_alert_at' => 'datetime',
        'progress' => 'integer',
        'cost_minor' => 'integer',
        'alerts_enabled' => 'boolean',
        'alert_lead_days' => 'integer',
    ];

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(TreatmentMilestone::class, 'treatment_id')->orderBy('sequence');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereNotNull('due_at')->whereDate('due_at', '<', now());
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null
            && in_array($this->status, self::OPEN_STATUSES, true)
            && $this->due_at->isBefore(now()->startOfDay());
    }
}
