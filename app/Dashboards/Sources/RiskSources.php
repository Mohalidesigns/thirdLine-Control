<?php

namespace App\Dashboards\Sources;

use App\Models\Metric;
use App\Models\MetricBreach;
use App\Models\Risk;
use App\Models\RiskTreatment;
use App\Models\User;
use App\Services\AppetiteService;
use App\Services\HeatmapService;
use Illuminate\Support\Facades\DB;

/**
 * Risk datasets. The heatmap keeps the configured band colours from Phase
 * 10 rather than a chart palette — a board that has agreed what "red" means
 * should not see it repainted by a visualisation layer.
 */
class RiskSources extends SourceProvider
{
    public function __construct(
        private HeatmapService $heatmaps,
        private AppetiteService $appetites,
    ) {}

    public function sources(): array
    {
        return [
            $this->make(
                'risk_heatmap', 'Risk heatmap', 'Risk', 'view risks',
                ['heatmap'],
                ['w' => 6, 'h' => 5],
                fn (User $user, array $config) => $this->heatmap($user, $config),
                ['view', 'category_id', 'entity_id'],
            ),

            $this->make(
                'risk_band_distribution', 'Risks by band', 'Risk', 'view risks',
                ['donut', 'bar', 'table'],
                ['w' => 4, 'h' => 4],
                fn (User $user, array $config) => $this->bands(),
            ),

            $this->make(
                'top_risks', 'Top risks', 'Risk', 'view risks',
                ['table'],
                ['w' => 6, 'h' => 4],
                fn (User $user, array $config) => $this->topRisks((int) ($config['limit'] ?? 8)),
                ['limit'],
            ),

            $this->make(
                'appetite_position', 'Appetite position', 'Risk', 'view appetite',
                ['table', 'horizontal_bar'],
                ['w' => 6, 'h' => 4],
                fn (User $user, array $config) => $this->appetitePosition($user),
            ),

            $this->make(
                'kri_status', 'KRI status', 'Risk', 'view metrics',
                ['scorecard', 'table'],
                ['w' => 6, 'h' => 4],
                fn (User $user, array $config) => $this->kriStatus((int) ($config['limit'] ?? 8)),
                ['limit'],
            ),

            $this->make(
                'treatment_progress', 'Treatment progress', 'Risk', 'view treatments',
                ['stacked_bar', 'progress', 'table'],
                ['w' => 5, 'h' => 4],
                fn (User $user, array $config) => $this->treatments(),
            ),
        ];
    }

    private function heatmap(User $user, array $config): array
    {
        $built = $this->heatmaps->build(
            $user->tenant_id,
            $config['view'] ?? 'residual',
            array_filter([
                'category_id' => $config['category_id'] ?? null,
                'entity_id' => $config['entity_id'] ?? null,
            ]),
        );

        return [
            'kind' => 'matrix',
            'matrix' => [
                'x' => array_map(fn (array $level) => [
                    'key' => (string) $level['level'], 'label' => $level['label'],
                ], $built['likelihood']),
                'y' => array_map(fn (array $level) => [
                    'key' => (string) $level['level'], 'label' => $level['label'],
                ], $built['impact']),
                'cells' => array_map(fn (array $cell) => [
                    'x' => (string) $cell['likelihood'],
                    'y' => (string) $cell['impact'],
                    'value' => $cell['count'],
                    'label' => $cell['band'],
                    'colour' => $cell['colour'],
                    'drill' => $cell['count'] > 0
                        ? $this->drill('risks.heatmap.cell', [
                            'view' => $built['view'],
                            'likelihood' => $cell['likelihood'],
                            'impact' => $cell['impact'],
                        ])
                        : null,
                ], $built['cells']),
                'legend' => $built['bands'],
            ],
            'total' => $built['total'],
            'unit' => 'count',
            'caption' => "{$built['plotted']} of {$built['total']} risks plotted",
            'empty' => $built['total'] === 0,
        ];
    }

    private function bands(): array
    {
        $counts = Risk::query()
            ->where('status', 'active')
            ->select('current_rating_band', DB::raw('count(*) as total'))
            ->groupBy('current_rating_band')
            ->pluck('total', 'current_rating_band');

        $categories = [];
        $values = [];
        $tones = [];

        foreach (Risk::RATING_BANDS as $band) {
            $categories[] = [
                'key' => $band,
                'label' => $band,
                'drill' => $this->drill('risks.index', ['band' => $band]),
            ];
            $values[] = (int) ($counts[$band] ?? 0);
            $tones[] = match ($band) {
                'Critical' => 'critical',
                'High' => 'serious',
                'Moderate' => 'warning',
                default => 'good',
            };
        }

        $unbanded = (int) ($counts[null] ?? 0) + (int) ($counts[''] ?? 0);

        if ($unbanded > 0) {
            $categories[] = ['key' => 'Unassessed', 'label' => 'Unassessed', 'drill' => null];
            $values[] = $unbanded;
            $tones[] = 'muted';
        }

        return [
            'kind' => 'series',
            'categories' => $categories,
            'series' => [['key' => 'band', 'label' => 'Risks', 'tones' => $tones, 'values' => $values]],
            'total' => array_sum($values),
            'unit' => 'count',
            'drill' => $this->drill('risks.index'),
            'empty' => array_sum($values) === 0,
        ];
    }

    private function topRisks(int $limit): array
    {
        $limit = min(20, max(3, $limit));

        $risks = Risk::query()
            ->with(['owner:id,name'])
            ->where('status', 'active')
            ->orderByDesc(DB::raw('coalesce(residual_rating, inherent_rating)'))
            ->limit($limit)
            ->get(['id', 'code', 'title', 'inherent_rating', 'residual_rating', 'current_rating_band', 'risk_owner_id', 'appetite_breached']);

        return [
            'kind' => 'rows',
            'columns' => [
                ['key' => 'code', 'label' => 'Risk'],
                ['key' => 'title', 'label' => 'Title'],
                ['key' => 'residual', 'label' => 'Residual', 'align' => 'right'],
                ['key' => 'owner', 'label' => 'Owner'],
            ],
            'rows' => $risks->map(fn (Risk $risk) => [
                'cells' => [
                    $risk->code,
                    $risk->title,
                    $risk->residual_rating ?? $risk->inherent_rating,
                    $risk->owner?->name ?? 'Unassigned',
                ],
                'tone' => $risk->appetite_breached ? 'critical' : null,
                'note' => $risk->appetite_breached ? 'Outside appetite' : null,
                'drill' => $this->drill('risks.show', ['risk' => $risk->id]),
            ])->all(),
            'total' => $risks->count(),
            'drill' => $this->drill('risks.index'),
            'empty' => $risks->isEmpty(),
        ];
    }

    private function appetitePosition(User $user): array
    {
        $positions = $this->appetites->positions($user->tenant_id);

        return [
            'kind' => 'rows',
            'columns' => [
                ['key' => 'category', 'label' => 'Category'],
                ['key' => 'entity', 'label' => 'Entity'],
                ['key' => 'position', 'label' => 'Position', 'align' => 'right'],
                ['key' => 'status', 'label' => 'Against appetite'],
            ],
            'rows' => array_map(fn (array $position) => [
                'cells' => [
                    $position['category'],
                    $position['entity'],
                    $position['current_position'] ?? '—',
                    $position['status'],
                ],
                'tone' => match ($position['status']) {
                    'Outside capacity', 'Breach' => 'critical',
                    'Outside tolerance' => 'serious',
                    'Within tolerance' => 'warning',
                    default => 'good',
                },
                'drill' => $this->drill('appetite.index'),
            ], $positions),
            'total' => count($positions),
            'drill' => $this->drill('appetite.index'),
            'empty' => $positions === [],
        ];
    }

    private function kriStatus(int $limit): array
    {
        $limit = min(20, max(3, $limit));

        $metrics = Metric::query()
            ->active()
            ->whereIn('metric_type', ['KRI', 'KCI'])
            ->withCount(['breaches as open_breaches' => fn ($q) => $q->whereNull('resolved_at')])
            ->orderByDesc('open_breaches')
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'code', 'name', 'unit', 'metric_type', 'direction']);

        $latest = $metrics->mapWithKeys(fn (Metric $metric) => [$metric->id => $metric->latestValue()]);

        return [
            'kind' => 'rows',
            'columns' => [
                ['key' => 'code', 'label' => 'Metric'],
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'value', 'label' => 'Latest', 'align' => 'right'],
                ['key' => 'breaches', 'label' => 'Open breaches', 'align' => 'right'],
            ],
            'rows' => $metrics->map(fn (Metric $metric) => [
                'cells' => [
                    $metric->code,
                    $metric->name,
                    $latest[$metric->id]?->value !== null
                        ? rtrim(rtrim(number_format((float) $latest[$metric->id]->value, 2), '0'), '.').' '.($metric->unit ?? '')
                        : '—',
                    $metric->open_breaches,
                ],
                'tone' => $metric->open_breaches > 0 ? 'critical' : 'good',
                'drill' => $this->drill('metrics.show', ['metric' => $metric->id]),
            ])->all(),
            'total' => $metrics->count(),
            'caption' => MetricBreach::query()->whereNull('resolved_at')->count().' unresolved breaches across all metrics',
            'drill' => $this->drill('metrics.index', ['breached_only' => 1]),
            'empty' => $metrics->isEmpty(),
        ];
    }

    private function treatments(): array
    {
        $counts = RiskTreatment::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $categories = [];
        $values = [];

        foreach (RiskTreatment::STATUSES as $status) {
            $categories[] = [
                'key' => $status,
                'label' => $status,
                'drill' => $this->drill('treatments.index', ['status' => $status]),
            ];
            $values[] = (int) ($counts[$status] ?? 0);
        }

        $open = RiskTreatment::query()->open()->count();
        $overdue = RiskTreatment::query()->overdue()->count();
        $averageProgress = (int) round((float) RiskTreatment::query()->open()->avg('progress'));

        return [
            'kind' => 'series',
            'categories' => $categories,
            'series' => [['key' => 'treatments', 'label' => 'Treatments', 'slot' => 1, 'values' => $values]],
            'total' => array_sum($values),
            'value' => $averageProgress,
            'unit' => 'percent',
            'caption' => "{$open} open, {$overdue} overdue — average progress {$averageProgress}%",
            'drill' => $this->drill('treatments.index'),
            'empty' => array_sum($values) === 0,
        ];
    }
}
