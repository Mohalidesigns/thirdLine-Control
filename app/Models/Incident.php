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
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Incident extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, SoftDeletes;

    public const TYPES = [
        'operational', 'security', 'cyber', 'fraud', 'compliance', 'data_breach',
        'health_safety', 'conduct', 'third_party', 'continuity', 'other',
    ];

    /** Basel II/III operational-risk event taxonomy (level 1). */
    public const BASEL_EVENT_TYPES = [
        'Internal Fraud', 'External Fraud', 'Employment Practices',
        'Clients Products & Business Practices', 'Damage to Physical Assets',
        'Business Disruption & System Failures', 'Execution Delivery & Process Mgmt',
    ];

    public const SEVERITIES = ['Low', 'Medium', 'High', 'Critical'];

    public const STATUSES = [
        'Reported', 'Triaged', 'Under Investigation', 'Contained',
        'Remediated', 'Closed', 'Reopened',
    ];

    public const OPEN_STATUSES = [
        'Reported', 'Triaged', 'Under Investigation', 'Contained', 'Remediated', 'Reopened',
    ];

    /** Guarded lifecycle (11.2). Closed reopens rather than resurrecting. */
    public const TRANSITIONS = [
        'Reported' => ['Triaged', 'Closed'],
        'Triaged' => ['Under Investigation', 'Contained', 'Closed'],
        'Under Investigation' => ['Contained', 'Remediated', 'Closed'],
        'Contained' => ['Under Investigation', 'Remediated', 'Closed'],
        'Remediated' => ['Closed', 'Under Investigation'],
        'Closed' => ['Reopened'],
        'Reopened' => ['Under Investigation', 'Contained', 'Remediated', 'Closed'],
    ];

    protected $fillable = [
        'tenant_id', 'incident_ref', 'title', 'description', 'incident_type',
        'basel_event_type', 'severity', 'occurred_at', 'detected_at', 'detection_method',
        'reported_at', 'reported_by', 'entity_id', 'process_id', 'status',
        'gross_loss_minor', 'recovery_minor', 'net_loss_minor', 'currency', 'near_miss',
        'is_reportable', 'regulator_ids', 'notification_due_at', 'notified_at',
        'notification_reference', 'root_cause', 'root_cause_category',
        'contributing_factors', 'lessons_learned', 'owner_id', 'investigator_id',
        'closed_by', 'closed_at', 'control_failure_ids', 'risk_ids',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'detected_at' => 'datetime',
        'reported_at' => 'datetime',
        'notification_due_at' => 'datetime',
        'notified_at' => 'datetime',
        'closed_at' => 'datetime',
        'gross_loss_minor' => 'integer',
        'recovery_minor' => 'integer',
        'net_loss_minor' => 'integer',
        'near_miss' => 'boolean',
        'is_reportable' => 'boolean',
        'regulator_ids' => 'array',
        'contributing_factors' => 'array',
        'control_failure_ids' => 'array',
        'risk_ids' => 'array',
    ];

    protected $appends = ['net_loss'];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function investigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'investigator_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class, 'entity_id');
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(BusinessProcess::class, 'process_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(IncidentAction::class);
    }

    public function timeline(): HasMany
    {
        return $this->hasMany(IncidentTimelineEntry::class)->orderBy('occurred_at');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(IncidentNotification::class);
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class, 'linked_incident_id');
    }

    public function evidence(): MorphMany
    {
        return $this->morphMany(Evidence::class, 'linked', 'linked_type', 'linked_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeReportable(Builder $query): Builder
    {
        return $query->where('is_reportable', true);
    }

    /** R7: integer minor units plus an ISO-4217 code, never a float. */
    public function netLossMoney(): ?Money
    {
        if (! $this->currency) {
            return null;
        }

        return Money::of((int) $this->net_loss_minor, $this->currency);
    }

    public function getNetLossAttribute(): ?array
    {
        return $this->netLossMoney()?->jsonSerialize();
    }

    /** Mandatory actions the closure gate cares about (11.2). */
    public function openMandatoryActions(): HasMany
    {
        return $this->actions()
            ->where('is_mandatory', true)
            ->whereNotIn('status', ['Completed', 'Verified', 'Cancelled']);
    }

    public function outstandingNotifications(): HasMany
    {
        return $this->notifications()
            ->where('is_required', true)
            ->where('status', 'Pending');
    }

    /** The event key the notification resolver matches obligations on. */
    public function notificationEventAt(): ?Carbon
    {
        return $this->detected_at ?? $this->occurred_at ?? $this->reported_at;
    }
}
