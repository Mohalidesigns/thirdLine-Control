<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRichText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A strategic objective (17.1). Risks that threaten it and the controls
 * over those risks reach it through the linkage graph, so a board can ask
 * "what threatens this objective and are the controls effective" without a
 * new join table.
 */
class Objective extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasRichText, SoftDeletes;

    public const PERSPECTIVES = [
        'financial', 'customer', 'internal_process', 'learning_growth', 'sustainability',
    ];

    public const PERSPECTIVE_LABELS = [
        'financial' => 'Financial',
        'customer' => 'Customer',
        'internal_process' => 'Internal Process',
        'learning_growth' => 'Learning & Growth',
        'sustainability' => 'Sustainability',
    ];

    public const STATUSES = ['Draft', 'Active', 'At Risk', 'Achieved', 'Missed', 'Cancelled'];

    /** Statuses that still count towards the live scorecard. */
    public const OPEN_STATUSES = ['Draft', 'Active', 'At Risk'];

    public const PROGRESS_MODES = ['auto', 'manual'];

    protected $fillable = [
        'tenant_id', 'entity_id', 'parent_objective_id', 'perspective', 'code',
        'title', 'description', 'owner_id', 'period', 'start_date', 'target_date',
        'status', 'progress', 'progress_mode', 'weight', 'progress_calculated_at',
        'created_by',
    ];

    /** Spec §9 — Editor.js documents in {field}_rich; the plain column is the derived mirror. */
    protected array $richText = ['description'];

    protected $casts = [
        'start_date' => 'date',
        'target_date' => 'date',
        'progress' => 'integer',
        'weight' => 'integer',
        'progress_calculated_at' => 'datetime',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Objective::class, 'parent_objective_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Objective::class, 'parent_objective_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function measures(): HasMany
    {
        return $this->hasMany(ObjectiveMetric::class);
    }

    public function initiatives(): HasMany
    {
        return $this->hasMany(Initiative::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_objective_id');
    }

    public function perspectiveLabel(): string
    {
        return self::PERSPECTIVE_LABELS[$this->perspective] ?? $this->perspective;
    }

    /** Days left to the target date, negative once it has passed. */
    public function daysToTarget(): ?int
    {
        return $this->target_date
            ? (int) now()->startOfDay()->diffInDays($this->target_date->startOfDay(), false)
            : null;
    }
}
