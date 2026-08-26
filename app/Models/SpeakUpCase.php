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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Speak Up intake case (11.4): whistleblowing, disciplinary and
 * regulatory-enquiry reports.
 *
 * The table is `cases`; the class cannot be `Case` because that is a PHP
 * reserved word. It was called InvestigationCase until CR-04, which needed
 * that name for the casework aggregate on `investigations`. The two are
 * deliberately separate: this is INTAKE — allowlist-gated, anonymity-
 * preserving, with no subjects, consequences or financials — and
 * App\Models\Investigation is the investigation that may follow it.
 *
 * Two rules make this model different from every other one in the product:
 *
 *   1. Access is allowlist-only. The `allowlist` global scope restricts
 *      every query to cases the current user is explicitly on. The single
 *      exception is the named oversight permission 'view all cases'
 *      (System Administrator only): it grants read-only sight of every
 *      case so a report can never be invisible to the platform owner, but
 *      it is not membership — investigating, noting and access management
 *      still require being on the allowlist, and every oversight view is
 *      audit-logged like any other.
 *   2. An anonymous case is not de-anonymisable. reporter_id stays NULL,
 *      only a one-way hash of the reporter's follow-up token is stored, and
 *      auditsAnonymously() keeps user, IP and agent out of the audit trail
 *      while still recording that the event happened (R3).
 */
class SpeakUpCase extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, SoftDeletes;

    protected $table = 'cases';

    public const TYPES = [
        'investigation', 'whistleblowing', 'disciplinary', 'fraud',
        'conflict_of_interest', 'regulatory_enquiry', 'litigation',
    ];

    public const CONFIDENTIALITY_LEVELS = ['Standard', 'Restricted', 'Highly Restricted'];

    public const SEVERITIES = ['Low', 'Medium', 'High', 'Critical'];

    public const STATUSES = [
        'Received', 'Assessed', 'Under Investigation', 'Substantiated',
        'Unsubstantiated', 'Referred', 'Closed',
    ];

    public const OPEN_STATUSES = ['Received', 'Assessed', 'Under Investigation'];

    public const TRANSITIONS = [
        'Received' => ['Assessed', 'Closed'],
        'Assessed' => ['Under Investigation', 'Referred', 'Unsubstantiated', 'Closed'],
        'Under Investigation' => ['Substantiated', 'Unsubstantiated', 'Referred'],
        'Substantiated' => ['Referred', 'Closed'],
        'Unsubstantiated' => ['Closed', 'Under Investigation'],
        'Referred' => ['Closed', 'Under Investigation'],
        'Closed' => [],
    ];

    protected $fillable = [
        'tenant_id', 'case_ref', 'case_type', 'title', 'description',
        'confidentiality', 'is_anonymous', 'reporter_id', 'reporter_token_hash',
        'was_anonymised', 'anonymisation_note', 'received_at', 'channel', 'entity_id',
        'subject_persons', 'status', 'severity', 'lead_investigator_id',
        'investigation_plan', 'findings', 'conclusion', 'actions_taken',
        'referred_to', 'closed_by', 'closed_at', 'related_incident_id',
        'related_complaint_id', 'access_user_ids',
        'legal_hold', 'legal_hold_reason', 'legal_hold_by', 'legal_hold_at',
    ];

    protected $hidden = ['reporter_token_hash'];

    protected $casts = [
        'is_anonymous' => 'boolean',
        'was_anonymised' => 'boolean',
        'received_at' => 'datetime',
        'closed_at' => 'datetime',
        'subject_persons' => 'array',
        'access_user_ids' => 'array',
        'legal_hold' => 'boolean',
        'legal_hold_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Layer four of the four-layer enforcement: even a query that skips
        // the controller and the policy cannot return a case the user is not
        // named on. The only escape hatch is the named oversight permission
        // — a role name alone never widens the query.
        static::addGlobalScope('allowlist', function (Builder $builder) {
            $user = auth()->user();

            if (! $user) {
                return;
            }

            if ($user->can('view all cases')) {
                return;
            }

            $table = $builder->getModel()->getTable();

            $builder->where(fn (Builder $q) => $q
                ->where($table.'.lead_investigator_id', $user->id)
                ->orWhere($table.'.reporter_id', $user->id)
                ->orWhereJsonContains($table.'.access_user_ids', $user->id));
        });
    }

    /** Anonymous cases never attribute their audit rows (R3 + 11.4). */
    public function auditsAnonymously(): bool
    {
        return (bool) $this->is_anonymous;
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function leadInvestigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_investigator_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class, 'entity_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'related_incident_id');
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class, 'related_complaint_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CaseNote::class, 'case_id')->orderByDesc('created_at');
    }

    /**
     * Reporter technical metadata (CR). Exists only for confidential
     * submissions — an anonymous case never has a row, and the relation
     * serialises Tier 1 fields only (the model hides Tier 2).
     */
    public function metadata(): HasOne
    {
        return $this->hasOne(SpeakUpReportMetadata::class, 'report_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    /** Membership test used by CasePolicy — the same list, one source. */
    public function grantsAccessTo(User $user): bool
    {
        return $this->lead_investigator_id === $user->id
            || ($this->reporter_id !== null && $this->reporter_id === $user->id)
            || in_array($user->id, array_map('intval', $this->access_user_ids ?? []), true);
    }

    /** One-way: the plaintext token is shown once and never stored. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function generateToken(): string
    {
        return strtoupper(Str::random(6).'-'.Str::random(6));
    }
}
