<?php

namespace App\Services;

use App\Models\Entity;
use App\Models\GhgDataPoint;
use App\Models\MaterialityAssessment;
use App\Models\SustainabilityFiling;
use App\Models\SustainabilityFilingStage;
use App\Models\SustainabilityTopic;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * IFRS S1/S2 sustainability controls (17.3).
 *
 * Three rules shape this service.
 *
 * 1. Nothing about the reporting timetable is coded. The pre-reporting
 *    stage offsets are data — a default on the tenant, copied onto each
 *    filing at creation so a later correction cannot silently restate a
 *    deadline somebody has already been told. The shipped defaults are
 *    'unverified' until an officer confirms them against the FRC's own
 *    published roadmap (R1, R10).
 *
 * 2. A GHG figure is prepared by one person and verified by another. The
 *    service refuses the same person doing both, whatever permissions they
 *    hold (R2).
 *
 * 3. An unverified topic, an unapproved materiality determination and an
 *    unverified emissions figure are all excluded from a generated
 *    submission. What goes to a regulator is the confirmed subset, and the
 *    pack says plainly what was left out (R10).
 */
class SustainabilityService
{
    /**
     * The FRC of Nigeria's three pre-reporting filing stages, expressed as
     * month offsets from the start of the reporting year.
     *
     * UNVERIFIED. These offsets reflect the three-stage pre-reporting
     * schedule described in the FRC's sustainability reporting roadmap
     * (−3 months, +3 months, +6 months from the year start). They have not
     * been confirmed line by line against the FRC's own published
     * instrument, so every filing created from them is stamped
     * `verification_status = 'unverified'` and is excluded from generated
     * submissions until a named officer verifies it (R10). A tenant whose
     * regulator publishes different offsets overrides them in
     * `tenants.settings['sustainability']['filing_stage_offsets']` — no
     * deploy required (R1).
     *
     * @var array<int, array<string, mixed>>
     */
    public const DEFAULT_FILING_STAGES = [
        [
            'key' => 'pre_reporting_1',
            'label' => 'Stage 1 — pre-reporting readiness',
            'offset_months' => -3,
            'description' => 'Filed three months before the start of the reporting year.',
        ],
        [
            'key' => 'pre_reporting_2',
            'label' => 'Stage 2 — interim readiness',
            'offset_months' => 3,
            'description' => 'Filed three months after the start of the reporting year.',
        ],
        [
            'key' => 'pre_reporting_3',
            'label' => 'Stage 3 — final pre-reporting',
            'offset_months' => 6,
            'description' => 'Filed six months after the start of the reporting year.',
        ],
    ];

    /**
     * First reporting periods per class, per the FRC roadmap. Also
     * UNVERIFIED and also overridable — the platform surfaces them as a
     * suggestion in the UI and never applies one silently.
     *
     * @var array<string, int>
     */
    public const DEFAULT_ADOPTION_YEARS = [
        'pie' => 2028,
        'public_sector' => 2028,
        'sme' => 2030,
    ];

    // ── Filing stages ────────────────────────────────────────────────

    /**
     * The stage schedule a tenant uses. Falls back to the shipped default,
     * which is unverified until somebody says otherwise.
     *
     * @return array<int, array<string, mixed>>
     */
    public function stageSchedule(?int $tenantId): array
    {
        $settings = $tenantId
            ? (Tenant::withoutGlobalScopes()->find($tenantId)?->settings['sustainability'] ?? [])
            : [];

        $offsets = $settings['filing_stage_offsets'] ?? null;

        return is_array($offsets) && $offsets !== [] ? $offsets : self::DEFAULT_FILING_STAGES;
    }

    /**
     * Compute the three stage deadlines from a fiscal year start.
     *
     * A stage offset is in whole months from the year start, so a
     * 1 January year start with the shipped schedule gives 1 October of the
     * prior year, 1 April and 1 July. Entities whose year starts on any
     * other date get their own dates — the platform never assumes a
     * December year end (R7).
     *
     * @param  array<int, array<string, mixed>>|null  $schedule
     * @return array<int, array<string, mixed>>
     */
    public function computeStages(string|CarbonImmutable $fiscalYearStart, ?array $schedule = null, ?int $tenantId = null): array
    {
        $start = $fiscalYearStart instanceof CarbonImmutable
            ? $fiscalYearStart
            : CarbonImmutable::parse($fiscalYearStart);

        $schedule ??= $this->stageSchedule($tenantId);

        return collect($schedule)
            ->map(fn (array $stage) => [
                'key' => $stage['key'],
                'label' => $stage['label'],
                'offset_months' => (int) $stage['offset_months'],
                'description' => $stage['description'] ?? null,
                'due_at' => $start->addMonths((int) $stage['offset_months'])->toDateString(),
            ])
            ->sortBy('due_at')
            ->values()
            ->all();
    }

    /** Create a filing and materialise its stages. */
    public function createFiling(array $data, ?User $creator = null): SustainabilityFiling
    {
        $tenantId = $data['tenant_id'] ?? auth()->user()?->tenant_id;
        $schedule = $this->stageSchedule($tenantId);
        $stages = $this->computeStages($data['fiscal_year_start'], $schedule, $tenantId);

        $filing = SustainabilityFiling::create([
            'tenant_id' => $tenantId,
            'entity_id' => $data['entity_id'] ?? null,
            'regulator_id' => $data['regulator_id'] ?? null,
            'reference' => SustainabilityFiling::nextReference('SUS'),
            'reporting_class' => $data['reporting_class'] ?? 'pie',
            'adoption_year' => $data['adoption_year'] ?? null,
            'fiscal_year_start' => $data['fiscal_year_start'],
            'period_label' => $data['period_label'],
            'filing_stage_offsets' => $schedule,
            // The schedule the filing was built from was unverified unless
            // the tenant has confirmed it. An officer verifies the filing
            // explicitly; nothing here does it for them (R10).
            'verification_status' => $data['verification_status'] ?? 'unverified',
            'owner_id' => $data['owner_id'] ?? $creator?->id,
            'status' => 'Planned',
        ]);

        foreach ($stages as $stage) {
            SustainabilityFilingStage::create([
                'tenant_id' => $filing->tenant_id,
                'filing_id' => $filing->id,
                'stage_key' => $stage['key'],
                'label' => $stage['label'],
                'offset_months' => $stage['offset_months'],
                'due_at' => $stage['due_at'],
                'status' => 'Pending',
            ]);
        }

        return $filing->fresh(['stages']);
    }

    public function submitStage(SustainabilityFilingStage $stage, User $user, array $data): SustainabilityFilingStage
    {
        $stage->update([
            'submitted_at' => now(),
            'submitted_by' => $user->id,
            'submission_reference' => $data['submission_reference'] ?? null,
            'evidence_id' => $data['evidence_id'] ?? null,
            'note' => $data['note'] ?? null,
            'status' => 'Submitted',
        ]);

        $stage->auditAction('filed', null, [
            'stage' => $stage->stage_key,
            'reference' => $stage->submission_reference,
            'by' => $user->id,
        ]);

        $filing = $stage->filing;

        if ($filing && $filing->stages()->open()->doesntExist()) {
            $filing->update(['status' => 'Complete']);
        } elseif ($filing && $filing->status === 'Planned') {
            $filing->update(['status' => 'In Progress']);
        }

        return $stage->fresh();
    }

    /**
     * Nightly: mark open stages past their due date Late. A stage that
     * quietly stayed 'Pending' after its deadline would read as on time.
     */
    public function refreshFilingStages(?int $tenantId = null): int
    {
        $late = 0;

        SustainabilityFilingStage::withoutGlobalScopes()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereIn('status', ['Pending', 'In Progress'])
            ->whereDate('due_at', '<', now()->toDateString())
            ->each(function (SustainabilityFilingStage $stage) use (&$late) {
                $stage->update(['status' => 'Late']);
                $stage->auditAction('missed', null, ['due_at' => $stage->due_at->toDateString()]);
                $late++;
            });

        return $late;
    }

    // ── Materiality ──────────────────────────────────────────────────

    public function submitMateriality(MaterialityAssessment $assessment, User $assessor, array $data): MaterialityAssessment
    {
        $assessment->update([
            ...$data,
            'assessed_by' => $assessor->id,
            'assessed_at' => now(),
            'status' => 'Submitted',
        ]);

        return $assessment->fresh();
    }

    /**
     * Approve a materiality determination. Not the assessor: deciding that
     * a topic is immaterial is deciding not to disclose it, which is
     * exactly the judgement that needs a second person (R2).
     */
    public function approveMateriality(MaterialityAssessment $assessment, User $approver): MaterialityAssessment
    {
        if ($assessment->status !== 'Submitted') {
            throw ValidationException::withMessages([
                'status' => 'Only a submitted materiality assessment can be approved.',
            ]);
        }

        if ($assessment->assessed_by === $approver->id) {
            throw ValidationException::withMessages([
                'approved_by' => 'The person who assessed materiality cannot approve their own determination.',
            ]);
        }

        $assessment->update([
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'status' => 'Approved',
        ]);

        $assessment->auditAction('approved', ['status' => 'Submitted'], [
            'status' => 'Approved',
            'is_material' => $assessment->is_material,
            'by' => $approver->id,
        ]);

        return $assessment->fresh();
    }

    // ── GHG data ─────────────────────────────────────────────────────

    /**
     * Compute tCO2e where activity data and a factor are both present, and
     * leave it null where they are not. A derived figure is never invented
     * from a missing input.
     */
    public function prepareDataPoint(GhgDataPoint $point, User $preparer, array $data): GhgDataPoint
    {
        $activity = $data['activity_data'] ?? $point->activity_data;
        $factor = $data['emission_factor'] ?? $point->emission_factor;

        $point->update([
            ...$data,
            'tco2e' => $data['tco2e'] ?? (
                $activity !== null && $factor !== null ? round((float) $activity * (float) $factor, 6) : null
            ),
            'prepared_by' => $preparer->id,
            'prepared_at' => now(),
            'status' => 'Prepared',
        ]);

        return $point->fresh();
    }

    /**
     * Verify a prepared figure. The preparer cannot verify their own — a
     * control over sustainability reporting that one person both operates
     * and signs off is not a control (COSO ICSR, R2).
     */
    public function verifyDataPoint(GhgDataPoint $point, User $verifier, ?string $note = null): GhgDataPoint
    {
        if ($point->status !== 'Prepared') {
            throw ValidationException::withMessages([
                'status' => 'Only a prepared figure can be verified.',
            ]);
        }

        if ($point->prepared_by === $verifier->id) {
            throw ValidationException::withMessages([
                'verified_by' => 'The person who prepared this figure cannot verify it.',
            ]);
        }

        $point->update([
            'verified_by' => $verifier->id,
            'verified_at' => now(),
            'verification_note' => $note,
            'status' => 'Verified',
        ]);

        $point->auditAction('verified', ['status' => 'Prepared'], [
            'status' => 'Verified',
            'tco2e' => $point->tco2e,
            'by' => $verifier->id,
        ]);

        return $point->fresh();
    }

    /**
     * Defer a Scope 3 figure under the IFRS S2 transition reliefs. Scope 1
     * and 2 carry no such relief, so the service refuses to defer them —
     * the relief is the standard's, not the institution's convenience.
     */
    public function deferDataPoint(GhgDataPoint $point, string $reason): GhgDataPoint
    {
        if ($point->scope !== '3') {
            throw ValidationException::withMessages([
                'scope' => 'Only Scope 3 emissions may be deferred under the transition reliefs.',
            ]);
        }

        $point->update(['is_deferred' => true, 'deferral_reason' => $reason, 'status' => 'Deferred']);
        $point->auditAction('deferred', null, ['reason' => $reason]);

        return $point->fresh();
    }

    /**
     * The emissions inventory for a period: verified totals per scope, plus
     * what is not yet verified and what is deferred. The three are reported
     * separately on purpose — a total that folded unverified figures into
     * verified ones would be the single most misleading number in the
     * product.
     *
     * @return array<string, mixed>
     */
    public function inventory(string $periodLabel, ?int $entityId = null): array
    {
        $points = GhgDataPoint::query()
            ->where('period_label', $periodLabel)
            ->when($entityId, fn ($q, $e) => $q->where('entity_id', $e))
            ->get();

        $scopes = collect(GhgDataPoint::SCOPES)->mapWithKeys(function (string $scope) use ($points) {
            $inScope = $points->where('scope', $scope);

            return [$scope => [
                'verified_tco2e' => round((float) $inScope->where('status', 'Verified')->sum('tco2e'), 3),
                'unverified_tco2e' => round((float) $inScope->whereNotIn('status', ['Verified', 'Deferred'])->sum('tco2e'), 3),
                'points' => $inScope->count(),
                'verified_points' => $inScope->where('status', 'Verified')->count(),
                'deferred_points' => $inScope->where('is_deferred', true)->count(),
                'lineage_gaps' => $inScope->filter(fn (GhgDataPoint $point) => ! $point->isAssurable())->count(),
            ]];
        })->all();

        return [
            'period_label' => $periodLabel,
            'scopes' => $scopes,
            'verified_total' => round(array_sum(array_column($scopes, 'verified_tco2e')), 3),
            'unverified_total' => round(array_sum(array_column($scopes, 'unverified_tco2e')), 3),
            'assurable' => $points->filter(fn (GhgDataPoint $point) => $point->isAssurable())->count(),
            'total_points' => $points->count(),
        ];
    }

    // ── Readiness ────────────────────────────────────────────────────

    /**
     * What would and would not go into a submission for this period, and
     * why. This is the screen an officer looks at before generating a pack
     * — everything excluded is named rather than dropped (R10).
     *
     * @return array<string, mixed>
     */
    public function readiness(string $periodLabel, ?int $entityId = null): array
    {
        $assessments = MaterialityAssessment::query()
            ->where('period_label', $periodLabel)
            ->when($entityId, fn ($q, $e) => $q->where('entity_id', $e))
            ->with('topic')
            ->get();

        $unverifiedTopics = $assessments
            ->filter(fn (MaterialityAssessment $a) => $a->topic && ! $a->topic->isVerified())
            ->map(fn (MaterialityAssessment $a) => [
                'code' => $a->topic->code,
                'title' => $a->topic->title,
                'reason' => 'The topic has not been verified against the standard.',
            ])
            ->values()
            ->all();

        $unapproved = $assessments
            ->where('status', '!=', 'Approved')
            ->map(fn (MaterialityAssessment $a) => [
                'code' => $a->topic?->code,
                'title' => $a->topic?->title,
                'reason' => "Materiality is {$a->status}, not Approved.",
            ])
            ->values()
            ->all();

        $inventory = $this->inventory($periodLabel, $entityId);

        $unverifiedFigures = GhgDataPoint::query()
            ->where('period_label', $periodLabel)
            ->when($entityId, fn ($q, $e) => $q->where('entity_id', $e))
            ->whereNotIn('status', ['Verified', 'Deferred'])
            ->get()
            ->map(fn (GhgDataPoint $point) => [
                'scope' => $point->scope,
                'category' => $point->category,
                'reason' => "Status is {$point->status}"
                    .($point->lineageGaps() ? ' and lineage is missing '.implode(', ', $point->lineageGaps()) : '')
                    .'.',
            ])
            ->values()
            ->all();

        $excluded = [...$unverifiedTopics, ...$unapproved, ...$unverifiedFigures];

        return [
            'period_label' => $periodLabel,
            'material_topics' => $assessments->filter(fn (MaterialityAssessment $a) => $a->status === 'Approved' && $a->is_material)->count(),
            'topics_assessed' => $assessments->count(),
            'inventory' => $inventory,
            'excluded' => $excluded,
            // A pack can only be generated when nothing material is stuck in
            // an unverified or unapproved state.
            'ready' => $excluded === [] && $assessments->isNotEmpty(),
        ];
    }

    /** Headline numbers for the sustainability index page. */
    public function stats(?string $periodLabel = null): array
    {
        return [
            'topics' => SustainabilityTopic::active()->count(),
            'unverified_topics' => SustainabilityTopic::active()->where('verification_status', 'unverified')->count(),
            'material_topics' => MaterialityAssessment::material()
                ->when($periodLabel, fn ($q, $p) => $q->where('period_label', $p))
                ->count(),
            'awaiting_approval' => MaterialityAssessment::where('status', 'Submitted')->count(),
            'unverified_figures' => GhgDataPoint::whereNotIn('status', ['Verified', 'Deferred'])->count(),
            'lineage_gaps' => GhgDataPoint::query()->get()
                ->filter(fn (GhgDataPoint $point) => ! $point->isAssurable())
                ->count(),
            'stages_late' => SustainabilityFilingStage::overdue()->count(),
            'stages_due_90' => SustainabilityFilingStage::open()
                ->whereDate('due_at', '<=', now()->addDays(90)->toDateString())
                ->whereDate('due_at', '>=', now()->toDateString())
                ->count(),
        ];
    }

    /** Suggested first reporting year for a class — a suggestion, never applied. */
    public function suggestedAdoptionYear(string $reportingClass): ?int
    {
        return self::DEFAULT_ADOPTION_YEARS[$reportingClass] ?? null;
    }

    /** Entities available to attach a filing to. */
    public function entities()
    {
        return Entity::query()->orderBy('legal_name')->get(['id', 'legal_name', 'fiscal_year_end']);
    }
}
