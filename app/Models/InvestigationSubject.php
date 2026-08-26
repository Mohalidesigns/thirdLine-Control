<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person, account or process an investigation names (CR-04 §C.3).
 *
 * The identifying columns here are the most sensitive CR-04 introduces.
 * They are reachable only through an investigation the viewer can already
 * open, and they must never appear in a dashboard aggregate or a board
 * extract — see InvestigationDashboardService, which returns references
 * and titles only.
 */
class InvestigationSubject extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    public const TYPES = ['staff', 'customer', 'vendor', 'third_party', 'system_process', 'unknown'];

    public const ROLES_IN_CASE = ['primary_subject', 'witness', 'person_of_interest'];

    public const OUTCOMES = ['pending', 'exonerated', 'culpable', 'partially_culpable', 'inconclusive'];

    /** Outcomes that resolve a person's position — completion requires one. */
    public const RESOLVED_OUTCOMES = ['exonerated', 'culpable', 'partially_culpable', 'inconclusive'];

    /** Identifying columns §H.4 proposes purging once a subject is exonerated. */
    public const IDENTIFYING_COLUMNS = ['name', 'staff_id', 'account_number', 'position'];

    protected $fillable = [
        'tenant_id', 'investigation_id', 'subject_type', 'name', 'user_id',
        'staff_id', 'account_number', 'department', 'organisation_unit_id', 'position',
        'role_in_case', 'outcome', 'outcome_rationale', 'outcome_recorded_on',
        'outcome_recorded_by', 'notes',
    ];

    protected $casts = ['outcome_recorded_on' => 'date'];

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organisationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class);
    }

    public function outcomeRecorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'outcome_recorded_by');
    }

    public function consequenceActions(): HasMany
    {
        return $this->hasMany(ConsequenceAction::class, 'investigation_subject_id');
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->where('outcome', 'pending');
    }

    public function isResolved(): bool
    {
        return in_array($this->outcome, self::RESOLVED_OUTCOMES, true);
    }
}
