<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesReference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Risk extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'external_ref', 'code', 'title', 'description', 'category',
        'inherent_likelihood', 'inherent_impact', 'inherent_rating',
        'residual_rating', 'risk_owner_id', 'source', 'status',
    ];

    protected $casts = [
        'inherent_likelihood' => 'integer',
        'inherent_impact' => 'integer',
        'inherent_rating' => 'integer',
        'residual_rating' => 'float',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'risk_owner_id');
    }

    public function controls(): BelongsToMany
    {
        return $this->belongsToMany(Control::class, 'risk_control_map')
            ->withPivot(['contribution_weight', 'mapped_by', 'mapped_at'])
            ->withTimestamps();
    }

    /** Risks with no active mapped control — control gaps (FR-2.5). */
    public function scopeControlGaps(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->whereDoesntHave('controls', fn (Builder $q) => $q->where('status', 'Active'));
    }
}
