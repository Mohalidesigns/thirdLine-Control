<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesReference;
use App\Support\Money;
use App\Support\SqlDialect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorContract extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, SoftDeletes;

    public const STATUSES = ['Draft', 'Active', 'Expiring', 'Expired', 'Terminated'];

    protected $fillable = [
        'tenant_id', 'vendor_id', 'entity_id', 'reference', 'title', 'contract_type',
        'start_date', 'end_date', 'auto_renew', 'renewal_notice_days', 'value_minor',
        'currency', 'sla_commitments', 'obligation_id', 'document_id', 'owner_id',
        'renewal_alerted_at', 'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'auto_renew' => 'boolean',
        'renewal_notice_days' => 'integer',
        'value_minor' => 'integer',
        'sla_commitments' => 'array',
        'renewal_alerted_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(RegulatoryObligation::class, 'obligation_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function valueMoney(): ?Money
    {
        return $this->value_minor === null ? null : Money::of((int) $this->value_minor, $this->currency);
    }

    /**
     * Contracts inside their own notice window — the point at which a
     * renewal decision has to be taken to be taken at all. The window is a
     * column on each contract, never a constant.
     */
    public function scopeInNoticeWindow(Builder $query): Builder
    {
        $noticeDate = SqlDialect::dateMinusDaysColumn('end_date', 'renewal_notice_days');

        return $query->whereIn('status', ['Active', 'Expiring'])
            ->whereNotNull('end_date')
            ->whereRaw("{$noticeDate} <= ?", [now()->toDateString()])
            ->whereDate('end_date', '>=', now()->toDateString());
    }

    public function daysToExpiry(): ?int
    {
        return $this->end_date
            ? (int) now()->startOfDay()->diffInDays($this->end_date->startOfDay(), false)
            : null;
    }

    public function noticeDeadline(): ?string
    {
        return $this->end_date?->copy()->subDays((int) $this->renewal_notice_days)->toDateString();
    }
}
