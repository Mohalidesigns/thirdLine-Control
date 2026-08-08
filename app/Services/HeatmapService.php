<?php

namespace App\Services;

use App\Models\Risk;
use App\Models\RiskAssessmentScale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Risk heatmaps (10.2). The grid is whatever the tenant's likelihood and
 * impact scales say it is — 4×4, 5×5, 6×6 — and every cell colour comes
 * from a seeded rating_band row. Nothing about the matrix is compiled in
 * (R1). Meaning is never carried by colour alone: every cell and every
 * plotted risk ships its band label alongside its colour.
 */
class HeatmapService
{
    public const VIEWS = ['inherent', 'residual', 'target', 'movement'];

    public function __construct(private RiskAssessmentService $assessments) {}

    /**
     * @param  array<string, mixed>  $filters  category_id, entity_id, owner_id,
     *                                         process_id, framework_id, breach_only
     * @return array<string, mixed>
     */
    public function build(int $tenantId, string $view = 'residual', array $filters = []): array
    {
        $view = in_array($view, self::VIEWS, true) ? $view : 'residual';

        $likelihood = $this->axis($tenantId, 'likelihood');
        $impact = $this->axis($tenantId, 'impact');
        $colours = $this->assessments->bandColours($tenantId);

        // Band resolution is memoised by SCORE for the length of this build.
        // A 5×5 grid has at most 25 distinct scores, so the number of band
        // lookups is bounded by the grid rather than by the size of the
        // register — which is what stops a dashboard heatmap issuing a query
        // per risk on a full register (R6).
        $bandMemo = [];
        $bandFor = function (float $score) use (&$bandMemo, $tenantId): string {
            return $bandMemo[(string) $score] ??= $this->assessments->bandFor($score, $tenantId);
        };

        $risks = $this->risks($tenantId, $filters);
        $plotted = $risks->map(fn (Risk $risk) => $this->plot($risk, $view, $tenantId, $bandFor))->filter();

        $cells = [];

        foreach ($impact as $impactLevel) {
            foreach ($likelihood as $likelihoodLevel) {
                $inCell = $plotted->filter(fn (array $point) => $point['likelihood'] === $likelihoodLevel['level']
                    && $point['impact'] === $impactLevel['level']);

                $score = $likelihoodLevel['level'] * $impactLevel['level'];
                $band = $bandFor((float) $score);

                $cells[] = [
                    'likelihood' => $likelihoodLevel['level'],
                    'impact' => $impactLevel['level'],
                    'score' => $score,
                    'band' => $band,
                    'colour' => $colours[$band] ?? '#e1e0d9',
                    'count' => $inCell->count(),
                    'breach_count' => $inCell->where('appetite_breached', true)->count(),
                    'financial_total_minor' => (int) $inCell->sum('financial_impact_minor'),
                    'risk_ids' => $inCell->pluck('id')->values()->all(),
                ];
            }
        }

        return [
            'view' => $view,
            'likelihood' => $likelihood,
            'impact' => $impact,
            'cells' => $cells,
            'bands' => $this->bandLegend($tenantId),
            'points' => $plotted->values()->all(),
            'movement' => $view === 'movement' ? $this->movement($risks, $tenantId) : [],
            'currency' => $risks->first()?->loss_currency,
            'total' => $risks->count(),
            'plotted' => $plotted->count(),
        ];
    }

    /**
     * The risks behind a single cell — the click-through target. Paginated
     * because a cell in a large register can hold hundreds (R6).
     */
    public function cellRisks(int $tenantId, string $view, int $likelihood, int $impact, array $filters = [])
    {
        $ids = collect($this->build($tenantId, $view, $filters)['cells'])
            ->firstWhere(fn (array $cell) => $cell['likelihood'] === $likelihood && $cell['impact'] === $impact)['risk_ids'] ?? [];

        return Risk::query()
            ->whereIn('id', $ids)
            ->with(['owner:id,name', 'riskCategory:id,name', 'entity:id,name'])
            ->orderByDesc('residual_rating')
            ->paginate(15)
            ->withQueryString();
    }

    /** @return array<int, array<string, mixed>> */
    public function axis(int $tenantId, string $type): array
    {
        $levels = RiskAssessmentScale::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('scale_type', $type)
            ->whereNull('dimension')
            ->orderBy('level')
            ->get();

        if ($levels->isEmpty()) {
            // A tenant that has not configured scales still gets a usable
            // 5×5 grid rather than an empty page.
            return collect(range(1, 5))->map(fn (int $level) => [
                'level' => $level,
                'label' => "Level {$level}",
                'description' => null,
                'lower_bound_minor' => null,
                'upper_bound_minor' => null,
                'currency' => null,
            ])->all();
        }

        return $levels->map(fn (RiskAssessmentScale $scale) => [
            'level' => $scale->level,
            'label' => $scale->label,
            'description' => $scale->description,
            'lower_bound_minor' => $scale->lower_bound_minor,
            'upper_bound_minor' => $scale->upper_bound_minor,
            'currency' => $scale->currency,
        ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function bandLegend(int $tenantId): array
    {
        $bands = $this->assessments->bandScale($tenantId);

        if ($bands->isEmpty()) {
            return [];
        }

        return $bands->map(fn (RiskAssessmentScale $band) => [
            'level' => $band->level,
            'label' => $band->label,
            'colour' => $band->colour_hex,
            'score_from' => $band->score_from,
            'score_to' => $band->score_to,
            'description' => $band->description,
        ])->all();
    }

    /** @return Collection<int, Risk> */
    private function risks(int $tenantId, array $filters): Collection
    {
        return Risk::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => $q->where('risk_category_id', $id))
            ->when($filters['entity_id'] ?? null, fn (Builder $q, $id) => $q->where('entity_id', $id))
            ->when($filters['owner_id'] ?? null, fn (Builder $q, $id) => $q->where('risk_owner_id', $id))
            ->when($filters['process_id'] ?? null, fn (Builder $q, $id) => $q->where('process_id', $id))
            ->when($filters['framework_id'] ?? null, fn (Builder $q, $id) => $q->whereHas(
                'controls.requirements',
                fn (Builder $w) => $w->where('framework_id', $id)
            ))
            ->when($filters['breach_only'] ?? null, fn (Builder $q) => $q->where('appetite_breached', true))
            ->get([
                'id', 'tenant_id', 'code', 'title', 'risk_category_id', 'entity_id', 'process_id',
                'risk_owner_id', 'inherent_likelihood', 'inherent_impact', 'inherent_rating',
                'residual_likelihood', 'residual_impact', 'residual_rating',
                'target_likelihood', 'target_impact', 'target_rating',
                'current_rating_band', 'appetite_breached', 'financial_impact_minor', 'loss_currency',
            ]);
    }

    /** @return array<string, mixed>|null */
    private function plot(Risk $risk, string $view, int $tenantId, ?callable $bandFor = null): ?array
    {
        $bandFor ??= fn (float $score): string => $this->assessments->bandFor($score, $tenantId);

        [$likelihood, $impact, $score] = match ($view) {
            'inherent' => [$risk->inherent_likelihood, $risk->inherent_impact, (float) $risk->inherent_rating],
            'target' => [$risk->target_likelihood, $risk->target_impact, (float) $risk->target_rating],
            default => [
                $risk->residual_likelihood ?? $risk->inherent_likelihood,
                $risk->residual_impact ?? $risk->inherent_impact,
                $risk->currentScore(),
            ],
        };

        if (! $likelihood || ! $impact) {
            return null;
        }

        return [
            'id' => $risk->id,
            'code' => $risk->code,
            'title' => $risk->title,
            'likelihood' => (int) $likelihood,
            'impact' => (int) $impact,
            'score' => round((float) $score, 2),
            'band' => $risk->current_rating_band ?? $bandFor((float) $score),
            'appetite_breached' => (bool) $risk->appetite_breached,
            'financial_impact_minor' => $risk->financial_impact_minor,
            'currency' => $risk->loss_currency,
        ];
    }

    /**
     * Residual → target movement: one arrow per risk that has both a
     * current and an agreed target position.
     *
     * @return array<int, array<string, mixed>>
     */
    private function movement(Collection $risks, int $tenantId): array
    {
        return $risks
            ->filter(fn (Risk $risk) => $risk->target_likelihood && $risk->target_impact)
            ->map(function (Risk $risk) use ($tenantId) {
                $from = $this->plot($risk, 'residual', $tenantId);
                $to = $this->plot($risk, 'target', $tenantId);

                if (! $from || ! $to) {
                    return null;
                }

                return [
                    'id' => $risk->id,
                    'code' => $risk->code,
                    'title' => $risk->title,
                    'from' => ['likelihood' => $from['likelihood'], 'impact' => $from['impact'], 'band' => $from['band']],
                    'to' => ['likelihood' => $to['likelihood'], 'impact' => $to['impact'], 'band' => $to['band']],
                    'gap' => $risk->targetGap(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
