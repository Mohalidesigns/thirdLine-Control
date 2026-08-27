<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRichText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One edge of the linkage graph (10.6). Nodes are addressed by a stable
 * alias rather than a class name so the alias survives refactoring and is
 * safe to put in a URL. Phase 11 node types (incident, policy, complaint,
 * case) are registered here as they land.
 */
class EntityLink extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasRichText;

    public const RELATIONSHIPS = [
        'mitigates', 'causes', 'indicates', 'governs', 'evidences',
        'relates_to', 'escalates_to', 'depends_on',
    ];

    /** @var array<string, class-string<Model>> */
    public const NODE_TYPES = [
        'risk' => Risk::class,
        'control' => Control::class,
        'metric' => Metric::class,
        'exception' => ControlException::class,
        'treatment' => RiskTreatment::class,
        'document' => Document::class,
        'obligation' => RegulatoryObligation::class,
        'requirement' => FrameworkRequirement::class,
        'improvement' => ImprovementAction::class,
        'process' => BusinessProcess::class,
        // Phase 11 — policy, incident, complaint and case nodes.
        'policy' => Policy::class,
        'incident' => Incident::class,
        'complaint' => Complaint::class,
        'case' => SpeakUpCase::class,
        // Phase 17 — strategy and third-party nodes.
        'objective' => Objective::class,
        'vendor' => Vendor::class,
        // CR-04 — the investigation. Note this is the casework aggregate
        // on `investigations`, not the Speak Up intake register above.
        'investigation' => Investigation::class,
    ];

    /** Human labels and the route each node type links to in the UI. */
    public const NODE_LABELS = [
        'risk' => 'Risk',
        'control' => 'Control',
        'metric' => 'Metric',
        'exception' => 'Exception',
        'treatment' => 'Treatment',
        'document' => 'Document',
        'obligation' => 'Obligation',
        'requirement' => 'Requirement',
        'improvement' => 'Improvement',
        'process' => 'Process',
        'policy' => 'Policy',
        'incident' => 'Incident',
        'complaint' => 'Complaint',
        'case' => 'Case',
        'objective' => 'Objective',
        'vendor' => 'Third Party',
        'investigation' => 'Investigation',
    ];

    protected $fillable = [
        'tenant_id', 'source_type', 'source_id', 'target_type', 'target_id',
        'relationship', 'strength', 'notes', 'created_by',
    ];

    /** Spec §9 — Editor.js documents in {field}_rich; the plain column is the derived mirror. */
    protected array $richText = ['notes'];

    protected $casts = [
        'source_id' => 'integer',
        'target_id' => 'integer',
        'strength' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function modelClassFor(string $alias): ?string
    {
        return self::NODE_TYPES[$alias] ?? null;
    }

    /** Every edge touching a node, in either direction. */
    public function scopeTouching(Builder $query, string $type, int $id): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $s) => $s->where('source_type', $type)->where('source_id', $id))
            ->orWhere(fn (Builder $t) => $t->where('target_type', $type)->where('target_id', $id)));
    }
}
