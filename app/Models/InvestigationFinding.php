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
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What an investigation established (CR-04 §C.4).
 *
 * control_id / exception_id / improvement_action_id are the whole point of
 * running this module inside a control product: the finding names which
 * control failed, ties to the exception that failure raised, and its
 * recommendation becomes tracked remediation work.
 */
class InvestigationFinding extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, HasRichText, SoftDeletes;

    public const SEVERITIES = ['Low', 'Moderate', 'High', 'Critical'];

    /** Severities that may not be left without tracked remediation (§F.1). */
    public const REMEDIATION_REQUIRED = ['High', 'Critical'];

    protected $fillable = [
        'tenant_id', 'investigation_id', 'reference', 'title', 'description',
        'severity', 'root_cause', 'control_failure', 'recommendation',
        'financial_impact', 'control_id', 'exception_id', 'improvement_action_id',
        'raised_by', 'established_on',
    ];

    /** Editor.js-backed fields — see HasRichText. */
    protected array $richText = ['description', 'root_cause', 'control_failure', 'recommendation'];

    protected $casts = [
        'established_on' => 'date',
        'financial_impact' => 'decimal:2',
    ];

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    public function exception(): BelongsTo
    {
        return $this->belongsTo(ControlException::class, 'exception_id');
    }

    public function improvementAction(): BelongsTo
    {
        return $this->belongsTo(ImprovementAction::class);
    }

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    /** High and Critical findings with nothing tracking their remediation. */
    public function scopeAwaitingRemediation(Builder $query): Builder
    {
        return $query->whereIn('severity', self::REMEDIATION_REQUIRED)
            ->whereNull('improvement_action_id');
    }
}
