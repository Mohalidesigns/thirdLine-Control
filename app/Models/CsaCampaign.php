<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesReference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CsaCampaign extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, SoftDeletes;

    public const TYPES = ['csa', 'attestation', 'survey'];

    public const STATUSES = ['Draft', 'Scheduled', 'Open', 'Closed', 'Archived'];

    protected $fillable = [
        'tenant_id', 'reference', 'name', 'description', 'campaign_type',
        'scope_definition', 'period_label', 'opens_at', 'closes_at',
        'reminder_schedule', 'status', 'is_anonymous', 'variance_threshold',
        'created_by', 'approved_by', 'approved_at', 'response_rate_target',
    ];

    protected $casts = [
        'scope_definition' => 'array',
        'reminder_schedule' => 'array',
        'opens_at' => 'datetime',
        'closes_at' => 'datetime',
        'approved_at' => 'datetime',
        'is_anonymous' => 'boolean',
        'variance_threshold' => 'integer',
        'response_rate_target' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function questionnaires(): HasMany
    {
        return $this->hasMany(CsaQuestionnaire::class, 'campaign_id');
    }

    public function activeQuestionnaire(): ?CsaQuestionnaire
    {
        return $this->questionnaires()->where('is_active', true)->orderByDesc('version_no')->first();
    }

    public function responses(): HasMany
    {
        return $this->hasMany(CsaResponse::class, 'campaign_id');
    }

    public function attestations(): HasMany
    {
        return $this->hasMany(Attestation::class, 'campaign_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'Open';
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('campaign_type', $type);
    }

    public function responseRate(): int
    {
        $total = $this->responses()->count();

        if ($this->is_anonymous) {
            $invited = (int) ($this->scope_definition['invited_count'] ?? 0);

            return $invited > 0 ? (int) round(min($total, $invited) / $invited * 100) : 0;
        }

        if ($total === 0) {
            return 0;
        }

        $submitted = $this->responses()
            ->whereIn('status', ['Submitted', 'Under Review', 'Accepted'])
            ->count();

        return (int) round($submitted / $total * 100);
    }
}
