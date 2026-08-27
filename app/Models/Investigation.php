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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An investigation (CR-04): fraud, staff misconduct, asset
 * misappropriation, conflicts of interest and the rest of the casework the
 * internal control function actually runs.
 *
 * Not to be confused with SpeakUpCase on `cases`, which is INTAKE — the
 * confidential report that arrives — and which this module deliberately
 * does not extend. An investigation raised from a Speak Up report carries
 * it as its origin, inherits its confidentiality and its allowlist, and
 * never learns who reported it (§D.3).
 *
 * Two structural guarantees live on this model rather than in a controller:
 *
 *   1. Visibility, as a GLOBAL scope — the same shape the Speak Up
 *      register uses, and for the same reason: a query that skips the
 *      controller and the policy still cannot return an investigation the
 *      user may not see. Two regimes on one table. An ordinary
 *      investigation opens to its team, its lead, its creator and the
 *      'view all investigations' oversight permission; a confidential one
 *      opens to its lead and team plus the named
 *      'view confidential-investigations' authority, and 'view all
 *      investigations' does NOT reach it.
 *
 *      Making it global is what lets the linkage graph render an
 *      investigation the viewer may not open as "(removed record)" with
 *      no route, rather than leaking its title into somebody else's
 *      Atlas page.
 *   2. Reference and rich text follow the house standard, so an
 *      investigation reads like every other record in the product.
 */
class Investigation extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, HasRichText, SoftDeletes;

    public const CATEGORIES = [
        'fraud', 'staff_misconduct', 'customer_complaint', 'whistleblowing',
        'regulatory_directive', 'asset_misappropriation', 'cyber_it_incident',
        'conflict_of_interest', 'process_breach', 'other',
    ];

    public const SOURCES = [
        'whistleblowing', 'management_directive', 'control_exception',
        'control_test_failure', 'regulator', 'customer_complaint',
        'system_alert', 'anonymous_tip', 'internal_audit_finding', 'other',
    ];

    public const STATUSES = [
        'draft', 'reported', 'under_investigation', 'pending_review',
        'completed', 'closed', 'suspended',
    ];

    /** Everything that is not finished and not shelved. */
    public const OPEN_STATUSES = ['draft', 'reported', 'under_investigation', 'pending_review'];

    public const PRIORITIES = ['Low', 'Medium', 'High', 'Critical'];

    public const RISK_RATINGS = ['Low', 'Moderate', 'High', 'Critical'];

    /**
     * The whole workflow, and deliberately narrow.
     *
     *   draft → reported → under_investigation → pending_review → completed → closed
     *                            ↕                     ↕
     *                        suspended ←───────────────┘
     *
     * 'completed' is absent from every list on purpose: it is reachable
     * only through InvestigationService::complete(), which requires a risk
     * rating, a completion date and a resolved outcome for every named
     * subject. That is what makes it structurally impossible to close an
     * investigation without rating it or without saying what happened to
     * the people it named.
     */
    public const TRANSITIONS = [
        'draft' => ['reported'],
        'reported' => ['under_investigation', 'suspended'],
        'under_investigation' => ['pending_review', 'suspended'],
        'pending_review' => ['under_investigation', 'suspended'],
        'completed' => ['closed'],
        'closed' => [],
        'suspended' => ['under_investigation', 'pending_review'],
    ];

    /** The two roles a tenant may not need, but which every install starts with. */
    public const CONFIDENTIAL_PERMISSION = 'view confidential-investigations';

    protected $fillable = [
        'tenant_id', 'reference', 'title', 'description', 'category', 'source',
        'control_entity_id', 'organisation_unit_id', 'origin_type', 'origin_id',
        'status', 'priority', 'risk_rating', 'is_confidential', 'confidentiality_locked',
        'has_sod_conflict', 'sod_conflict_note',
        'reported_date', 'commenced_date', 'target_completion_date',
        'completed_date', 'closed_date', 'lead_investigator_id',
        'estimated_financial_impact', 'confirmed_financial_loss', 'amount_recovered', 'currency',
        'background', 'scope', 'objectives', 'methodology', 'chronology', 'conclusion',
        'is_archived', 'archived_at', 'archived_by', 'archive_reason',
        'created_by', 'updated_by',
    ];

    /** Editor.js-backed fields — see HasRichText. */
    protected array $richText = ['archive_reason', 'background', 'chronology', 'conclusion', 'description', 'methodology', 'objectives', 'scope'];

    /** Derived, never stored — see daysOpen() and financialImpact(). */
    protected $appends = ['days_open', 'financial_impact'];

    protected $casts = [
        'reported_date' => 'date:Y-m-d',
        'commenced_date' => 'date:Y-m-d',
        'target_completion_date' => 'date:Y-m-d',
        'completed_date' => 'date:Y-m-d',
        'closed_date' => 'date:Y-m-d',
        'is_confidential' => 'boolean',
        'confidentiality_locked' => 'boolean',
        'has_sod_conflict' => 'boolean',
        'is_archived' => 'boolean',
        'archived_at' => 'datetime',
        'estimated_financial_impact' => 'decimal:2',
        'confirmed_financial_loss' => 'decimal:2',
        'amount_recovered' => 'decimal:2',
    ];

    // ── Relations ────────────────────────────────────────────────────────

    public function leadInvestigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_investigator_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function controlEntity(): BelongsTo
    {
        return $this->belongsTo(ControlEntity::class);
    }

    public function organisationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class);
    }

    /** The record this investigation exists because of — §D.2. */
    public function origin(): MorphTo
    {
        return $this->morphTo('origin', 'origin_type', 'origin_id');
    }

    public function teamMembers(): HasMany
    {
        return $this->hasMany(InvestigationTeamMember::class);
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(InvestigationSubject::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(InvestigationFinding::class);
    }

    public function consequenceActions(): HasMany
    {
        return $this->hasMany(ConsequenceAction::class);
    }

    /** Spec §5.3 — the report(s), each with its own review trail. */
    public function reports(): HasMany
    {
        return $this->hasMany(InvestigationReport::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(InvestigationActivity::class)->orderByDesc('activity_date');
    }

    /** Exhibits live in the shared repository, under its legal hold (§B.2). */
    public function evidence(): MorphMany
    {
        return $this->morphMany(Evidence::class, 'linked');
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    /**
     * The last line, not the only one. Layered under the policy and the
     * controller exactly as the Speak Up register's allowlist scope is:
     * an ad-hoc query, a relation traversal or a linkage-graph node
     * resolution all pass through it.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('visibility', function (Builder $builder) {
            if ($user = auth()->user()) {
                $builder->visibleTo($user);
            }
        });
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    /**
     * Spec §4 — OUTSTANDING is work in progress, and a draft is not.
     *
     * Deliberately narrower than open(): a draft is a case somebody has
     * started typing, not a case the function is carrying. Counting drafts
     * as outstanding inflates the one number a control function is asked
     * for most often, and inflates it with records that may never be
     * reported at all.
     */
    public const OUTSTANDING_STATUSES = ['reported', 'under_investigation', 'pending_review'];

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', self::OUTSTANDING_STATUSES);
    }

    /**
     * Spec §4 — anything that has not finished, for the OVERDUE count.
     *
     * Wider than open() at both ends: drafts count, and so does a
     * suspended case. A deadline does not stop existing because the case
     * is waiting on a police report — the whole point of the number is to
     * show what has slipped, and a suspended case past its date has.
     * (Ageing takes the opposite view and buckets suspended separately,
     * because there the question is how long work has been sitting.)
     */
    public function scopeUnfinished(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['completed', 'closed']);
    }

    /** Archived cases drop out of every list, count and KPI. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    /**
     * The two confidentiality regimes, expressed as one query so a list,
     * a count and a dashboard aggregate can never disagree about what a
     * user may see.
     *
     * An empty nested closure adds nothing to the SQL (Laravel drops a
     * where-group with no conditions), which is how the two override
     * branches below read as "no further restriction".
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $table = $query->getModel()->getTable();

        $onTeam = fn (Builder $q) => $q->whereExists(fn ($sub) => $sub
            ->selectRaw('1')
            ->from('investigation_team_members')
            ->whereColumn('investigation_team_members.investigation_id', $table.'.id')
            ->where('investigation_team_members.user_id', $user->id));

        return $query->where(function (Builder $outer) use ($table, $user, $onTeam) {
            $outer
                ->where(function (Builder $ordinary) use ($table, $user, $onTeam) {
                    $ordinary->where($table.'.is_confidential', false)
                        ->where(function (Builder $reach) use ($table, $user, $onTeam) {
                            if ($user->can('view all investigations')) {
                                return;
                            }

                            $reach->where($table.'.lead_investigator_id', $user->id)
                                ->orWhere($table.'.created_by', $user->id)
                                ->orWhere($onTeam);
                        });
                })
                ->orWhere(function (Builder $confidential) use ($table, $user, $onTeam) {
                    $confidential->where($table.'.is_confidential', true)
                        ->where(function (Builder $reach) use ($table, $user, $onTeam) {
                            // The named authority, never the role name: a
                            // tenant that separates these duties can move
                            // the permission without a deploy (R1).
                            if ($user->can(self::CONFIDENTIAL_PERMISSION)) {
                                return;
                            }

                            $reach->where($table.'.lead_investigator_id', $user->id)
                                ->orWhere($onTeam);
                        });
                });
        });
    }

    // ── Membership and state ─────────────────────────────────────────────

    /**
     * The same test the visibility scope applies, in memory — the policy
     * calls this so the policy and the query cannot drift apart.
     */
    public function grantsAccessTo(User $user): bool
    {
        $onTeam = $this->relationLoaded('teamMembers')
            ? $this->teamMembers->contains('user_id', $user->id)
            : $this->teamMembers()->where('user_id', $user->id)->exists();

        if ($this->is_confidential) {
            return $this->lead_investigator_id === $user->id
                || $onTeam
                || $user->can(self::CONFIDENTIAL_PERMISSION);
        }

        return $this->lead_investigator_id === $user->id
            || $this->created_by === $user->id
            || $onTeam
            || $user->can('view all investigations');
    }

    /** Membership proper — reach conferred by an oversight permission is not it. */
    public function hasTeamMember(User $user): bool
    {
        return $this->lead_investigator_id === $user->id
            || $this->teamMembers()->where('user_id', $user->id)->exists();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isEditable(): bool
    {
        return ! $this->is_archived && ! in_array($this->status, ['completed', 'closed'], true);
    }

    /** Loss net of anything recovered — the figure a consequence report leads on. */
    public function netLoss(): float
    {
        return round((float) $this->confirmed_financial_loss - (float) $this->amount_recovered, 2);
    }

    /**
     * Spec §7.6 — days open stops accruing when the case does.
     *
     * A closed case that keeps counting is not reporting how long the
     * investigation took; it is reporting how long ago it happened. The
     * clock stops at the completion date, or the closing date where the
     * case went straight to closed.
     */
    public function daysOpen(): int
    {
        $end = $this->completed_date ?? $this->closed_date ?? now();

        return (int) max(0, $this->reported_date?->diffInDays($end) ?? 0);
    }

    /**
     * Spec §7.3 — the single figure the list and the status strip lead on.
     *
     * The reference implementation labels it "Financial Exposure" and
     * shows the CONFIRMED loss under it, which reads as an estimate of
     * what is at risk when it is in fact a finding of what was lost.
     * Confirmed loss is the better number once it exists, so it wins —
     * but it is labelled for what it is, which is why this returns the
     * basis alongside the amount.
     *
     * @return array{amount: float|null, basis: string|null}
     */
    public function financialImpact(): array
    {
        if ($this->confirmed_financial_loss !== null) {
            return ['amount' => (float) $this->confirmed_financial_loss, 'basis' => 'Confirmed'];
        }

        if ($this->estimated_financial_impact !== null) {
            return ['amount' => (float) $this->estimated_financial_impact, 'basis' => 'Estimated'];
        }

        return ['amount' => null, 'basis' => null];
    }

    /**
     * Spec §7.3 — an investigation that uncovers more than was first
     * estimated is normal and must not be blocked. It should, however, be
     * visible: a confirmed loss well past the opening estimate is the
     * single most useful prompt to revisit the case's priority and rating.
     */
    public const IMPACT_VARIANCE_THRESHOLD = 0.20;

    /**
     * Both figures travel with the record so the list, the status strip
     * and the dashboard read one implementation rather than three.
     * Neither accessor issues a query.
     */
    public function getDaysOpenAttribute(): int
    {
        return $this->daysOpen();
    }

    /** @return array{amount: float|null, basis: string|null} */
    public function getFinancialImpactAttribute(): array
    {
        return $this->financialImpact();
    }

    public function confirmedLossExceedsEstimate(): bool
    {
        $estimate = (float) $this->estimated_financial_impact;
        $confirmed = (float) $this->confirmed_financial_loss;

        if ($estimate <= 0 || $confirmed <= 0) {
            return false;
        }

        return $confirmed > $estimate * (1 + self::IMPACT_VARIANCE_THRESHOLD);
    }

    public function raisedFromSpeakUp(): bool
    {
        return $this->origin_type === SpeakUpCase::class;
    }
}
