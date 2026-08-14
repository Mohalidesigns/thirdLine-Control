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

/** A third party the institution depends on (17.2). */
class Vendor extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, SoftDeletes;

    public const CRITICALITIES = ['Critical', 'High', 'Medium', 'Low'];

    public const STATUSES = ['Onboarding', 'Active', 'Under Review', 'Suspended', 'Exited'];

    public const DATA_CLASSIFICATIONS = [
        'none', 'internal', 'confidential', 'personal', 'sensitive_personal',
    ];

    /** Classifications that put NDPA obligations on the relationship. */
    public const PERSONAL_DATA_CLASSIFICATIONS = ['personal', 'sensitive_personal'];

    protected $fillable = [
        'tenant_id', 'entity_id', 'reference', 'legal_name', 'trading_name',
        'registration_number', 'category', 'criticality', 'services_provided',
        'jurisdiction', 'processing_country', 'relationship_start', 'relationship_end',
        'annual_spend_minor', 'spend_currency', 'owner_id',
        'regulator_notification_required', 'regulator_notified_at',
        'regulator_notification_ref', 'notification_obligation_id',
        'data_access_classification', 'sub_processors', 'review_frequency_months',
        'status', 'created_by',
    ];

    protected $casts = [
        'relationship_start' => 'date',
        'relationship_end' => 'date',
        'regulator_notified_at' => 'date',
        'annual_spend_minor' => 'integer',
        'regulator_notification_required' => 'boolean',
        'sub_processors' => 'array',
        'review_frequency_months' => 'integer',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function notificationObligation(): BelongsTo
    {
        return $this->belongsTo(RegulatoryObligation::class, 'notification_obligation_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(VendorAssessment::class)->orderByDesc('assessed_at');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(VendorFinding::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(VendorContract::class);
    }

    public function screenings(): HasMany
    {
        return $this->hasMany(VendorScreening::class)->orderByDesc('screened_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['Onboarding', 'Active', 'Under Review']);
    }

    public function scopeCritical(Builder $query): Builder
    {
        return $query->whereIn('criticality', ['Critical', 'High']);
    }

    public function annualSpendMoney(): ?Money
    {
        return $this->annual_spend_minor === null
            ? null
            : Money::of((int) $this->annual_spend_minor, $this->spend_currency);
    }

    public function touchesPersonalData(): bool
    {
        return in_array($this->data_access_classification, self::PERSONAL_DATA_CLASSIFICATIONS, true);
    }

    /**
     * A material outsourcing that was decided to need a regulator
     * notification and has not had one recorded. The decision is the
     * institution's; this only reports whether it was followed through.
     */
    public function notificationOutstanding(): bool
    {
        return $this->regulator_notification_required && $this->regulator_notified_at === null;
    }

    public function currentAssessment(): ?VendorAssessment
    {
        return $this->assessments()
            ->where('status', 'Approved')
            ->orderByDesc('approved_at')
            ->first();
    }
}
