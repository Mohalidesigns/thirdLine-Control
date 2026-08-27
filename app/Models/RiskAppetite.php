<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRichText;
use App\Models\Concerns\HasVersions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RiskAppetite extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasRichText, HasVersions, SoftDeletes;

    public const LEVELS = ['Averse', 'Minimal', 'Cautious', 'Open', 'Hungry'];

    public const STATUSES = ['Draft', 'Pending Approval', 'Active', 'Superseded'];

    protected $fillable = [
        'tenant_id', 'entity_id', 'risk_category_id', 'statement', 'appetite_level',
        'tolerance_upper', 'tolerance_lower', 'capacity', 'metric_definition',
        'version_no', 'supersedes_id', 'approved_by', 'approved_at',
        'effective_from', 'effective_to', 'review_due_at', 'status', 'created_by',
    ];

    /** Spec §9 — Editor.js documents in {field}_rich; the plain column is the derived mirror. */
    protected array $richText = ['metric_definition', 'statement'];

    protected $casts = [
        'tolerance_upper' => 'float',
        'tolerance_lower' => 'float',
        'capacity' => 'float',
        'version_no' => 'integer',
        'approved_at' => 'datetime',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'review_due_at' => 'date',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class, 'entity_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(RiskCategory::class, 'risk_category_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function risks(): HasMany
    {
        return $this->hasMany(Risk::class, 'appetite_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'Active')
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()));
    }

    /** Where a score sits against this statement's bands. */
    public function positionFor(?float $score): string
    {
        if ($score === null) {
            return 'Unknown';
        }

        if ($this->capacity !== null && $score > $this->capacity) {
            return 'Beyond capacity';
        }

        if ($this->tolerance_upper !== null && $score > $this->tolerance_upper) {
            return 'Outside tolerance';
        }

        if ($this->tolerance_lower !== null && $score < $this->tolerance_lower) {
            return 'Below appetite';
        }

        return 'Within appetite';
    }

    public function isBreachedBy(?float $score): bool
    {
        return in_array($this->positionFor($score), ['Outside tolerance', 'Beyond capacity'], true);
    }
}
