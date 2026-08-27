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

class VendorAssessment extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, HasRichText, SoftDeletes;

    public const TYPES = ['onboarding', 'periodic', 'triggered', 'exit'];

    public const STATUSES = ['Draft', 'In Progress', 'Submitted', 'Approved', 'Rejected', 'Expired'];

    public const BANDS = ['Critical', 'High', 'Medium', 'Low'];

    protected $fillable = [
        'tenant_id', 'vendor_id', 'campaign_id', 'response_id', 'reference',
        'assessment_type', 'period_label', 'risk_score', 'risk_band', 'conclusion',
        'assessed_by', 'assessed_at', 'approved_by', 'approved_at',
        'review_frequency_months', 'expires_at', 'review_raised_at',
        'review_exception_id', 'status',
    ];

    /** Spec §9 — Editor.js documents in {field}_rich; the plain column is the derived mirror. */
    protected array $richText = ['conclusion'];

    protected $casts = [
        'risk_score' => 'float',
        'assessed_at' => 'datetime',
        'approved_at' => 'datetime',
        'expires_at' => 'date',
        'review_raised_at' => 'datetime',
        'review_frequency_months' => 'integer',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CsaCampaign::class, 'campaign_id');
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(CsaResponse::class, 'response_id');
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reviewException(): BelongsTo
    {
        return $this->belongsTo(ControlException::class, 'review_exception_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(VendorFinding::class, 'assessment_id');
    }

    /** Approved assessments whose review date has passed. */
    public function scopeExpired(Builder $query, ?string $asOf = null): Builder
    {
        return $query->where('status', 'Approved')
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', $asOf ?? now()->toDateString());
    }

    public function scopeDueWithin(Builder $query, int $days): Builder
    {
        return $query->where('status', 'Approved')
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', now()->addDays($days)->toDateString())
            ->whereDate('expires_at', '>', now()->toDateString());
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function daysToExpiry(): ?int
    {
        return $this->expires_at
            ? (int) now()->startOfDay()->diffInDays($this->expires_at->startOfDay(), false)
            : null;
    }
}
