<?php

namespace App\Services;

use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\RiskAssessmentScale;
use App\Models\Tenant;
use App\Models\User;
use App\Support\LossSimulator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Assessment of a risk across the inherent → residual → target chain
 * (10.1). Everything configurable lives in tenants.settings['risk'] —
 * band cut-offs, impact-dimension weights, the second-line review
 * threshold and the simulation seed (R1). The defaults below are the
 * shipped starting point, not a hard-coded rule.
 */
class RiskAssessmentService
{
    /** Band cut-offs as a fraction of the maximum achievable score. */
    public const DEFAULT_BANDS = [
        'Low' => 0.25,
        'Moderate' => 0.50,
        'High' => 0.75,
        'Critical' => 1.00,
    ];

    /** Weighting used to aggregate the multi-dimensional impact score. */
    public const DEFAULT_DIMENSION_WEIGHTS = [
        'financial' => 3,
        'regulatory' => 3,
        'reputational' => 2,
        'operational' => 2,
        'customer' => 2,
        'people' => 1,
    ];

    /**
     * A residual score at or above this fraction of the maximum requires
     * second-line review before the assessment is published.
     */
    public const DEFAULT_REVIEW_THRESHOLD = 0.50;

    public const DEFAULT_SIMULATION_SEED = 20260809;

    public function __construct(
        private ResidualRiskService $residualRiskService,
        private AppetiteService $appetiteService,
    ) {}

    // ── Configuration ────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function config(?int $tenantId): array
    {
        $settings = $tenantId
            ? (Tenant::withoutGlobalScopes()->find($tenantId)?->settings['risk'] ?? [])
            : [];

        return [
            'bands' => $settings['bands'] ?? self::DEFAULT_BANDS,
            'dimension_weights' => $settings['dimension_weights'] ?? self::DEFAULT_DIMENSION_WEIGHTS,
            'review_threshold' => (float) ($settings['review_threshold'] ?? self::DEFAULT_REVIEW_THRESHOLD),
            'simulation_seed' => (int) ($settings['simulation_seed'] ?? self::DEFAULT_SIMULATION_SEED),
            'simulation_iterations' => (int) ($settings['simulation_iterations'] ?? LossSimulator::ITERATIONS),
            'distribution' => $settings['distribution'] ?? 'pert',
        ];
    }

    /** Highest level configured for a scale — the N in an N×N grid. */
    public function maxLevel(int $tenantId, string $scaleType): int
    {
        return (int) (RiskAssessmentScale::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('scale_type', $scaleType)
            ->whereNull('dimension')
            ->max('level') ?: 5);
    }

    public function maxScore(int $tenantId): int
    {
        return $this->maxLevel($tenantId, 'likelihood') * $this->maxLevel($tenantId, 'impact');
    }

    /**
     * Seeded rating_band rows own the cut-offs and colours (R1). Only when
     * a tenant has no band rows at all does the fraction-of-max fallback
     * apply, so a fresh installation is never left without a band.
     *
     * @return Collection<int, RiskAssessmentScale>
     */
    public function bandScale(int $tenantId)
    {
        return RiskAssessmentScale::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('scale_type', 'rating_band')
            ->orderBy('level')
            ->get();
    }

    /** Score → band, using the tenant's own band rows and grid size. */
    public function bandFor(float $score, int $tenantId): string
    {
        $bands = $this->bandScale($tenantId);

        if ($bands->isNotEmpty()) {
            foreach ($bands as $band) {
                if ($band->score_to === null || $score <= $band->score_to) {
                    return $band->label;
                }
            }

            return $bands->last()->label;
        }

        $max = max(1, $this->maxScore($tenantId));
        $fraction = $score / $max;

        foreach ($this->config($tenantId)['bands'] as $band => $ceiling) {
            if ($fraction <= (float) $ceiling) {
                return $band;
            }
        }

        return 'Critical';
    }

    /** Band → colour, from the seeded rows; never invented in code. */
    public function bandColours(int $tenantId): array
    {
        $colours = $this->bandScale($tenantId)
            ->mapWithKeys(fn (RiskAssessmentScale $band) => [$band->label => $band->colour_hex])
            ->all();

        return $colours ?: [
            'Low' => '#e1e0d9', 'Moderate' => '#e1e0d9',
            'High' => '#e1e0d9', 'Critical' => '#e1e0d9',
        ];
    }

    // ── Multi-dimensional impact ─────────────────────────────────────

    /**
     * Aggregate per-dimension impact levels into a single impact level,
     * keeping the driving dimension visible so the number is explainable.
     *
     * @param  array<string, int|null>  $dimensions
     * @return array{level: int, driving: ?string, weighted: float}
     */
    public function aggregateImpact(array $dimensions, int $tenantId): array
    {
        $weights = $this->config($tenantId)['dimension_weights'];
        $scored = array_filter($dimensions, fn ($level) => is_numeric($level) && $level > 0);

        if ($scored === []) {
            return ['level' => 0, 'driving' => null, 'weighted' => 0.0];
        }

        $totalWeight = 0.0;
        $weightedSum = 0.0;
        $driving = null;
        $highest = 0;

        foreach ($scored as $dimension => $level) {
            $weight = (float) ($weights[$dimension] ?? 1);
            $totalWeight += $weight;
            $weightedSum += $weight * (int) $level;

            if ((int) $level > $highest) {
                $highest = (int) $level;
                $driving = $dimension;
            }
        }

        $weighted = $totalWeight > 0 ? $weightedSum / $totalWeight : 0.0;

        // The aggregate never understates the worst dimension by more than
        // one level — a Critical regulatory impact cannot be averaged away.
        $level = max((int) round($weighted), $highest - 1);

        return [
            'level' => min($level, $this->maxLevel($tenantId, 'impact')),
            'driving' => $driving,
            'weighted' => round($weighted, 2),
        ];
    }

    // ── Recording and approving ──────────────────────────────────────

    /**
     * Record an assessment. It lands as Draft; submitForReview / publish
     * move it on, and above the tenant's review threshold only a
     * second-line reviewer can publish it.
     */
    public function record(Risk $risk, array $data, User $assessor): RiskAssessment
    {
        $tenantId = $risk->tenant_id;
        $dimensions = $data['impact_dimensions'] ?? [];

        $aggregate = $dimensions
            ? $this->aggregateImpact($dimensions, $tenantId)
            : ['level' => (int) $data['impact_level'], 'driving' => null];

        $impactLevel = max(1, (int) ($aggregate['level'] ?: $data['impact_level']));
        $likelihoodLevel = (int) $data['likelihood_level'];
        $score = $likelihoodLevel * $impactLevel;

        $assessment = RiskAssessment::create([
            'tenant_id' => $tenantId,
            'risk_id' => $risk->id,
            'assessment_type' => $data['assessment_type'],
            'assessed_at' => $data['assessed_at'] ?? now(),
            'assessed_by' => $assessor->id,
            'status' => 'Draft',
            'likelihood_level' => $likelihoodLevel,
            'impact_level' => $impactLevel,
            'likelihood_rationale' => $data['likelihood_rationale'] ?? null,
            'impact_rationale' => $data['impact_rationale'] ?? null,
            'score' => $score,
            'rating' => $this->bandFor($score, $tenantId),
            'financial_impact_minor' => $data['financial_impact_minor'] ?? null,
            'currency' => $data['currency'] ?? null,
            'loss_min_minor' => $data['loss_min_minor'] ?? null,
            'loss_likely_minor' => $data['loss_likely_minor'] ?? null,
            'loss_max_minor' => $data['loss_max_minor'] ?? null,
            'likelihood_percent' => $data['likelihood_percent'] ?? null,
            'impact_dimensions' => $dimensions ?: null,
            'driving_dimension' => $aggregate['driving'],
            'confidence' => $data['confidence'] ?? 'Medium',
            'method' => $data['method'] ?? 'workshop',
            'period_label' => $data['period_label'] ?? now()->format('Y').'-Q'.now()->quarter,
            'notes' => $data['notes'] ?? null,
        ]);

        if ($this->hasQuantitativeInputs($assessment)) {
            $this->simulate($assessment);
        }

        return $assessment;
    }

    public function requiresSecondLineReview(RiskAssessment $assessment): bool
    {
        $max = max(1, $this->maxScore($assessment->tenant_id));
        $threshold = $this->config($assessment->tenant_id)['review_threshold'];

        return ($assessment->score / $max) >= $threshold;
    }

    public function submitForReview(RiskAssessment $assessment): RiskAssessment
    {
        $this->transition($assessment, 'Pending Review');

        return $assessment;
    }

    /**
     * Publishing is the gate (R2): a high-scoring assessment needs a
     * second-line reviewer who is not the assessor. There is no admin
     * bypass — the check is on the record, not the role alone.
     */
    public function publish(RiskAssessment $assessment, User $approver): RiskAssessment
    {
        if ($assessment->status === 'Published') {
            return $assessment;
        }

        if ($this->requiresSecondLineReview($assessment)) {
            if ($assessment->assessed_by === $approver->id) {
                throw ValidationException::withMessages([
                    'approval' => 'A high-scoring risk assessment cannot be published by the person who made it.',
                ]);
            }

            if (! $approver->hasAnyRole(['Control Function Head', 'Control Officer'])) {
                throw ValidationException::withMessages([
                    'approval' => 'Publishing this assessment requires second-line review.',
                ]);
            }
        }

        $assessment->update([
            'status' => 'Published',
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);

        $assessment->auditAction('published', null, ['by' => $approver->id, 'score' => $assessment->score]);

        $this->applyToRisk($assessment);

        return $assessment;
    }

    public function reject(RiskAssessment $assessment, User $approver, string $reason): RiskAssessment
    {
        $this->transition($assessment, 'Rejected', ['approved_by' => $approver->id, 'approved_at' => now()]);
        $assessment->auditAction('rejected', null, ['by' => $approver->id, 'reason' => $reason]);

        return $assessment;
    }

    /** Push a published assessment onto the risk's current position. */
    public function applyToRisk(RiskAssessment $assessment): void
    {
        $risk = $assessment->risk()->withoutGlobalScopes()->first();

        if (! $risk) {
            return;
        }

        match ($assessment->assessment_type) {
            'inherent' => $risk->update([
                'inherent_likelihood' => $assessment->likelihood_level,
                'inherent_impact' => $assessment->impact_level,
                'inherent_rating' => (int) $assessment->score,
                'financial_impact_minor' => $assessment->financial_impact_minor ?? $risk->financial_impact_minor,
                'loss_currency' => $assessment->currency ?? $risk->loss_currency,
                'expected_loss_minor' => $assessment->expected_loss_minor ?? $risk->expected_loss_minor,
                'var_95_minor' => $assessment->var_95_minor ?? $risk->var_95_minor,
                'var_99_minor' => $assessment->var_99_minor ?? $risk->var_99_minor,
            ]),
            'target' => $risk->update([
                'target_likelihood' => $assessment->likelihood_level,
                'target_impact' => $assessment->impact_level,
                'target_rating' => $assessment->score,
            ]),
            default => $risk->update([
                'residual_likelihood' => $assessment->likelihood_level,
                'residual_impact' => $assessment->impact_level,
                'residual_rating' => $assessment->score,
                'current_rating_band' => $assessment->rating,
            ]),
        };

        $this->recomputeResidual($risk->fresh());
    }

    // ── Residual, on top of the phase 0-6 engine ─────────────────────

    /**
     * The control-driven residual stays with ResidualRiskService — its
     * weighted-reduction rule and the 20%-of-inherent floor are unchanged
     * (R8). Phase 10 only derives the residual grid cell from the score it
     * produces, so the heatmap has a cell to plot, and re-evaluates
     * appetite afterwards.
     */
    public function recomputeResidual(Risk $risk): Risk
    {
        $manualResidual = $risk->assessments()
            ->withoutGlobalScopes()
            ->ofType('residual')
            ->published()
            ->orderByDesc('assessed_at')
            ->first();

        if (! $manualResidual) {
            $this->residualRiskService->recompute($risk);
            $risk->refresh();

            $impact = $risk->residual_impact ?: $risk->inherent_impact;
            $maxLikelihood = $this->maxLevel($risk->tenant_id, 'likelihood');

            $risk->updateQuietly([
                'residual_impact' => $impact,
                'residual_likelihood' => $impact > 0
                    ? max(1, min($maxLikelihood, (int) round(($risk->residual_rating ?? 0) / $impact)))
                    : $risk->inherent_likelihood,
                'current_rating_band' => $this->bandFor($risk->currentScore(), $risk->tenant_id),
            ]);
        } else {
            $risk->updateQuietly([
                'current_rating_band' => $this->bandFor($risk->currentScore(), $risk->tenant_id),
            ]);
        }

        $this->appetiteService->evaluate($risk->refresh());

        return $risk;
    }

    // ── Quantitative path ────────────────────────────────────────────

    public function hasQuantitativeInputs(RiskAssessment $assessment): bool
    {
        return $assessment->loss_min_minor !== null
            && $assessment->loss_likely_minor !== null
            && $assessment->loss_max_minor !== null
            && $assessment->likelihood_percent !== null;
    }

    /**
     * Expected loss, VaR 95/99 and the loss-exceedance curve. Cached on the
     * input fingerprint so re-opening the page never re-runs 10,000
     * iterations, and deterministic under the tenant's configured seed.
     *
     * @return array<string, mixed>
     */
    public function simulate(RiskAssessment $assessment, ?int $seed = null): array
    {
        $config = $this->config($assessment->tenant_id);
        $seed ??= $config['simulation_seed'];

        $fingerprint = md5(implode(':', [
            $assessment->loss_min_minor, $assessment->loss_likely_minor, $assessment->loss_max_minor,
            $assessment->likelihood_percent, $seed, $config['distribution'], $config['simulation_iterations'],
        ]));

        $result = Cache::remember(
            "risk-simulation:{$assessment->id}:{$fingerprint}",
            now()->addDay(),
            fn () => (new LossSimulator($seed))->run(
                (int) $assessment->loss_min_minor,
                (int) $assessment->loss_likely_minor,
                (int) $assessment->loss_max_minor,
                min(1.0, (int) $assessment->likelihood_percent / 100),
                $config['distribution'],
                $config['simulation_iterations'],
            ),
        );

        $assessment->updateQuietly([
            'expected_loss_minor' => $result['expected_loss_minor'],
            'var_95_minor' => $result['var_95_minor'],
            'var_99_minor' => $result['var_99_minor'],
            'loss_curve' => $result['curve'],
            'simulated_at' => now(),
            'method' => 'monte_carlo',
        ]);

        return $result;
    }

    private function transition(RiskAssessment $assessment, string $to, array $extra = []): void
    {
        $allowed = [
            'Draft' => ['Pending Review', 'Published', 'Rejected'],
            'Pending Review' => ['Published', 'Rejected', 'Draft'],
            'Published' => [],
            'Rejected' => ['Draft'],
        ][$assessment->status] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "A risk assessment cannot move from {$assessment->status} to {$to}.",
            ]);
        }

        $assessment->update(['status' => $to, ...$extra]);
    }
}
