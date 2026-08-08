<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesReference;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Complaint extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, SoftDeletes;

    public const CHANNELS = [
        'letter', 'email', 'phone', 'social_media', 'branch', 'app',
        'ussd', 'web', 'whatsapp', 'regulator_referral',
    ];

    public const STATUSES = [
        'Received', 'Acknowledged', 'Under Investigation', 'Awaiting Customer',
        'Resolved', 'Closed', 'Escalated to Regulator', 'Reopened',
    ];

    public const OPEN_STATUSES = [
        'Received', 'Acknowledged', 'Under Investigation', 'Awaiting Customer',
        'Escalated to Regulator', 'Reopened',
    ];

    public const RESOLUTION_TYPES = ['upheld', 'partially_upheld', 'not_upheld', 'withdrawn'];

    public const TRANSITIONS = [
        'Received' => ['Acknowledged', 'Escalated to Regulator'],
        'Acknowledged' => ['Under Investigation', 'Awaiting Customer', 'Resolved', 'Escalated to Regulator'],
        'Under Investigation' => ['Awaiting Customer', 'Resolved', 'Escalated to Regulator'],
        'Awaiting Customer' => ['Under Investigation', 'Resolved', 'Escalated to Regulator'],
        'Resolved' => ['Closed', 'Reopened'],
        'Escalated to Regulator' => ['Under Investigation', 'Resolved'],
        'Closed' => ['Reopened'],
        'Reopened' => ['Under Investigation', 'Awaiting Customer', 'Resolved'],
    ];

    protected $fillable = [
        'tenant_id', 'complaint_ref', 'tracking_id', 'channel', 'received_at',
        'acknowledged_at', 'acknowledgement_due_at', 'customer_name',
        'customer_reference', 'customer_contact', 'category_id', 'subject',
        'description', 'product', 'amount_disputed_minor', 'currency', 'entity_id',
        'branch', 'assigned_to', 'status', 'resolution_due_at', 'resolved_at',
        'resolution_summary', 'resolution_type', 'redress_amount_minor',
        'redress_currency', 'customer_satisfied', 'sla_breached', 'days_open',
        'penalty_exposure_minor', 'penalty_currency', 'escalated_to_regulator',
        'regulator_reference', 'root_cause_id', 'linked_incident_id', 'linked_control_id',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'acknowledgement_due_at' => 'datetime',
        'resolution_due_at' => 'datetime',
        'resolved_at' => 'datetime',
        'customer_contact' => 'array',
        'amount_disputed_minor' => 'integer',
        'redress_amount_minor' => 'integer',
        'penalty_exposure_minor' => 'integer',
        'days_open' => 'integer',
        'customer_satisfied' => 'boolean',
        'sla_breached' => 'boolean',
        'escalated_to_regulator' => 'boolean',
    ];

    protected $appends = ['penalty_exposure'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ComplaintCategory::class, 'category_id');
    }

    public function rootCause(): BelongsTo
    {
        return $this->belongsTo(ComplaintCategory::class, 'root_cause_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class, 'entity_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'linked_incident_id');
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class, 'linked_control_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ComplaintActivity::class)->orderBy('occurred_at');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeUnacknowledged(Builder $query): Builder
    {
        return $query->whereNull('acknowledged_at')->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeBreached(Builder $query): Builder
    {
        return $query->where('sla_breached', true);
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }

    public function isResolved(): bool
    {
        return in_array($this->status, ['Resolved', 'Closed'], true);
    }

    /** Acknowledgement window missed — the ₦2m exposure trigger. */
    public function acknowledgementBreached(): bool
    {
        if ($this->acknowledgement_due_at === null) {
            return false;
        }

        return $this->acknowledged_at !== null
            ? $this->acknowledged_at->greaterThan($this->acknowledgement_due_at)
            : now()->greaterThan($this->acknowledgement_due_at);
    }

    public function penaltyMoney(): ?Money
    {
        if (! $this->penalty_currency) {
            return null;
        }

        return Money::of((int) $this->penalty_exposure_minor, $this->penalty_currency);
    }

    public function getPenaltyExposureAttribute(): ?array
    {
        return $this->penaltyMoney()?->jsonSerialize();
    }

    public function logActivity(
        string $type,
        ?string $description = null,
        ?string $channel = null,
        bool $customerVisible = false,
    ): ComplaintActivity {
        return $this->activities()->create([
            'tenant_id' => $this->tenant_id,
            'occurred_at' => now(),
            'actor_id' => auth()->id(),
            'activity_type' => $type,
            'description' => $description,
            'channel' => $channel,
            'is_customer_visible' => $customerVisible,
        ]);
    }
}
