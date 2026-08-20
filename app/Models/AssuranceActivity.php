<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One cell of the combined assurance map (17.4). */
class AssuranceActivity extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    /** Assurable subjects, by the same aliases the linkage graph uses. */
    public const SUBJECT_TYPES = [
        'risk' => Risk::class,
        'control' => Control::class,
        'obligation' => RegulatoryObligation::class,
        'process' => BusinessProcess::class,
    ];

    public const SUBJECT_LABELS = [
        'risk' => 'Risk',
        'control' => 'Control',
        'obligation' => 'Obligation',
        'process' => 'Process',
    ];

    public const COVERAGE_LEVELS = ['none', 'partial', 'full'];

    public const ASSURANCE_TYPES = [
        'reasonable', 'limited', 'agreed_upon_procedures', 'review', 'inspection', 'self_assessment',
    ];

    public const CONCLUSIONS = ['satisfactory', 'needs_improvement', 'unsatisfactory', 'not_concluded'];

    public const RELIANCE_LEVELS = ['none', 'limited', 'moderate', 'full'];

    protected $fillable = [
        'tenant_id', 'provider_id', 'entity_id', 'subject_type', 'subject_id',
        'activity_name', 'coverage_level', 'assurance_type', 'last_assurance_date',
        'next_assurance_date', 'period_label', 'conclusion', 'reliance_level',
        'reliance_rationale', 'reliance_recorded_by', 'reliance_recorded_at',
        'scope_note', 'source', 'external_ref', 'synced_at',
    ];

    protected $casts = [
        'last_assurance_date' => 'date',
        'next_assurance_date' => 'date',
        'reliance_recorded_at' => 'datetime',
        'synced_at' => 'datetime',
        'subject_id' => 'integer',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AssuranceProvider::class, 'provider_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function relianceRecorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reliance_recorded_by');
    }

    /** Coverage that actually covers something. */
    public function scopeCovering(Builder $query): Builder
    {
        return $query->whereIn('coverage_level', ['partial', 'full']);
    }

    public function scopeForSubject(Builder $query, string $type, int $id): Builder
    {
        return $query->where('subject_type', $type)->where('subject_id', $id);
    }

    public static function modelClassFor(string $alias): ?string
    {
        return self::SUBJECT_TYPES[$alias] ?? null;
    }

    /**
     * Assurance goes stale. A "covered" risk whose last assurance was three
     * years ago is not covered in any sense a board should rely on, so the
     * map ages coverage against the next-assurance date the provider itself
     * set.
     */
    public function isStale(): bool
    {
        if ($this->next_assurance_date) {
            return $this->next_assurance_date->isPast();
        }

        return $this->last_assurance_date === null
            || $this->last_assurance_date->lt(now()->subMonths(18));
    }
}
