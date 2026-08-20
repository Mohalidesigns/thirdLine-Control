<?php

namespace App\Dashboards\Sources;

use App\Models\Control;
use App\Models\Framework;
use App\Models\User;
use App\Services\FrameworkService;
use Illuminate\Support\Facades\DB;

/**
 * Control library datasets. Design and operating effectiveness are a named
 * pair here because they answer different questions — a control can be well
 * designed and still fail in operation, and a board that sees only one
 * number never learns which.
 */
class ControlSources extends SourceProvider
{
    public function __construct(private FrameworkService $frameworks) {}

    public function sources(): array
    {
        return [
            $this->make(
                'control_health', 'Control health', 'Controls', 'view controls',
                ['donut', 'bar', 'stat', 'table'],
                ['w' => 4, 'h' => 4],
                fn (User $user, array $config) => $this->ratingDistribution(
                    'overall_rating', Control::OVERALL_RATINGS, 'overall', $config,
                ),
                ['unit_id', 'key_only'],
            ),

            $this->make(
                'control_design_effectiveness', 'Design effectiveness', 'Controls', 'view controls',
                ['donut', 'bar', 'table'],
                ['w' => 4, 'h' => 4],
                fn (User $user, array $config) => $this->ratingDistribution(
                    'design_effectiveness', Control::DESIGN_RATINGS, 'design', $config,
                ),
                ['unit_id', 'key_only'],
            ),

            $this->make(
                'control_operating_effectiveness', 'Operating effectiveness', 'Controls', 'view controls',
                ['donut', 'bar', 'table'],
                ['w' => 4, 'h' => 4],
                fn (User $user, array $config) => $this->ratingDistribution(
                    'operating_effectiveness', Control::OPERATING_RATINGS, 'operating', $config,
                ),
                ['unit_id', 'key_only'],
            ),

            $this->make(
                'controls_by_unit', 'Controls by business unit', 'Controls', 'view controls',
                ['stacked_bar', 'horizontal_bar', 'table'],
                ['w' => 8, 'h' => 4],
                fn (User $user, array $config) => $this->byUnit($config),
                ['key_only', 'limit'],
            ),

            $this->make(
                'control_status_mix', 'Controls by lifecycle status', 'Controls', 'view controls',
                ['donut', 'bar', 'table'],
                ['w' => 4, 'h' => 4],
                fn (User $user, array $config) => $this->statusMix($config),
                ['unit_id'],
            ),

            $this->make(
                'key_control_count', 'Key controls', 'Controls', 'view controls',
                ['stat'],
                ['w' => 3, 'h' => 2],
                fn (User $user, array $config) => $this->keyControlCount($config),
                ['unit_id'],
            ),

            $this->make(
                'framework_coverage', 'Framework coverage', 'Controls', 'view frameworks',
                ['horizontal_bar', 'table'],
                ['w' => 6, 'h' => 4],
                fn (User $user, array $config) => $this->frameworkCoverage($config),
                ['entity_id'],
            ),

            $this->make(
                'control_implementation', 'Distribution implementation', 'Controls', 'view distributions',
                ['progress', 'stacked_bar', 'table'],
                ['w' => 4, 'h' => 3],
                fn (User $user, array $config) => $this->implementation($config),
            ),
        ];
    }

    /**
     * One grouped count, then a category per rating value — including the
     * ratings with no controls, so a reader sees the zero rather than an
     * absent slice.
     */
    private function ratingDistribution(string $column, array $scale, string $drillKey, array $config): array
    {
        $counts = $this->baseQuery($config)
            ->select($column, DB::raw('count(*) as total'))
            ->groupBy($column)
            ->pluck('total', $column);

        $unitId = $config['unit_id'] ?? null;
        $categories = [];
        $values = [];
        $tones = [];

        foreach ($scale as $rating) {
            $categories[] = [
                'key' => $rating,
                'label' => $rating,
                'drill' => $this->drill('controls.index', array_filter([
                    $drillKey => $rating,
                    'unit_id' => $unitId,
                ])),
            ];
            $values[] = (int) ($counts[$rating] ?? 0);
            $tones[] = self::RATING_TONE[$rating] ?? $this->overallTone($rating);
        }

        $unrated = (int) ($counts[null] ?? 0) + (int) ($counts[''] ?? 0);

        if ($unrated > 0) {
            $categories[] = ['key' => 'Unrated', 'label' => 'Unrated', 'drill' => null];
            $values[] = $unrated;
            $tones[] = 'muted';
        }

        return [
            'kind' => 'series',
            'categories' => $categories,
            'series' => [['key' => $column, 'label' => 'Controls', 'tones' => $tones, 'values' => $values]],
            'total' => array_sum($values),
            'unit' => 'count',
            'drill' => $this->drill('controls.index', array_filter(['unit_id' => $unitId])),
            'empty' => array_sum($values) === 0,
        ];
    }

    private function overallTone(string $rating): string
    {
        return match ($rating) {
            'Strong', 'Adequate' => 'good',
            'Moderate' => 'warning',
            'Weak', 'Inadequate' => 'critical',
            default => 'muted',
        };
    }

    /**
     * Controls per unit, split by operating effectiveness. One join, one
     * group-by — the unit name comes back with the count rather than through
     * a relation load per row.
     */
    private function byUnit(array $config): array
    {
        $limit = min(20, max(3, (int) ($config['limit'] ?? 10)));

        $rows = $this->baseQuery($config)
            ->leftJoin('organisation_units', 'organisation_units.id', '=', 'controls.unit_id')
            ->select([
                'controls.unit_id',
                DB::raw("coalesce(organisation_units.name, 'Unassigned') as unit_name"),
                'controls.operating_effectiveness',
                DB::raw('count(*) as total'),
            ])
            ->groupBy('controls.unit_id', 'organisation_units.name', 'controls.operating_effectiveness')
            ->get();

        if ($rows->isEmpty()) {
            return $this->emptyPayload();
        }

        $totals = $rows->groupBy('unit_name')->map(fn ($group) => $group->sum('total'))->sortDesc()->take($limit);

        $categories = $totals->keys()->map(fn (string $name) => [
            'key' => $name,
            'label' => $name,
            'drill' => $this->drill('controls.index', array_filter([
                'unit_id' => $rows->firstWhere('unit_name', $name)?->unit_id,
            ])),
        ])->values()->all();

        $series = [];

        foreach (Control::OPERATING_RATINGS as $rating) {
            $series[] = [
                'key' => $rating,
                'label' => $rating,
                'tone' => self::RATING_TONE[$rating] ?? 'muted',
                'values' => $totals->keys()->map(fn (string $name) => (int) $rows
                    ->where('unit_name', $name)
                    ->where('operating_effectiveness', $rating)
                    ->sum('total'))->values()->all(),
            ];
        }

        return [
            'kind' => 'series',
            'categories' => $categories,
            'series' => $series,
            'total' => (int) $totals->sum(),
            'unit' => 'count',
            'stacked' => true,
        ];
    }

    private function statusMix(array $config): array
    {
        $counts = Control::query()
            ->where('is_template', false)
            ->when($config['unit_id'] ?? null, fn ($q, $u) => $q->where('unit_id', $u))
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $values = [];
        $categories = [];

        foreach (Control::STATUSES as $status) {
            $categories[] = [
                'key' => $status,
                'label' => $status,
                'drill' => $this->drill('controls.index', array_filter([
                    'status' => $status,
                    'unit_id' => $config['unit_id'] ?? null,
                ])),
            ];
            $values[] = (int) ($counts[$status] ?? 0);
        }

        return [
            'kind' => 'series',
            'categories' => $categories,
            'series' => [['key' => 'status', 'label' => 'Controls', 'slot' => 1, 'values' => $values]],
            'total' => array_sum($values),
            'unit' => 'count',
            'empty' => array_sum($values) === 0,
        ];
    }

    private function keyControlCount(array $config): array
    {
        $total = $this->baseQuery($config)->count();
        $key = $this->baseQuery($config)->where('is_key_control', true)->count();

        return [
            'kind' => 'scalar',
            'value' => $key,
            'unit' => 'count',
            'caption' => $total > 0 ? "{$key} of {$total} active controls are key controls" : 'No active controls',
            'drill' => $this->drill('controls.index', array_filter(['unit_id' => $config['unit_id'] ?? null])),
            'empty' => $total === 0,
        ];
    }

    /**
     * Coverage per framework. A framework whose content is unverified still
     * appears, flagged — hiding it would read as full coverage (R10).
     */
    private function frameworkCoverage(array $config): array
    {
        $frameworks = Framework::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->limit(12)
            ->get();

        if ($frameworks->isEmpty()) {
            return $this->emptyPayload();
        }

        $categories = [];
        $mapped = [];
        $effective = [];

        foreach ($frameworks as $framework) {
            $coverage = $this->frameworks->coverage($framework, $config['entity_id'] ?? null);

            $categories[] = [
                'key' => $framework->code,
                'label' => $framework->code,
                'note' => $coverage['verification_status'] !== 'verified' ? 'Unverified content' : null,
                'drill' => $this->drill('frameworks.show', ['framework' => $framework->id]),
            ];
            $mapped[] = $coverage['mapped_percentage'];
            $effective[] = $coverage['effective_percentage'];
        }

        return [
            'kind' => 'series',
            'categories' => $categories,
            'series' => [
                ['key' => 'mapped', 'label' => 'Mapped', 'slot' => 1, 'values' => $mapped],
                ['key' => 'effective', 'label' => 'Mapped and effective', 'slot' => 3, 'values' => $effective],
            ],
            'unit' => 'percent',
            'max' => 100,
        ];
    }

    private function implementation(array $config): array
    {
        $counts = Control::query()
            ->where('is_template', false)
            ->whereNotNull('implementation_status')
            ->select('implementation_status', DB::raw('count(*) as total'))
            ->groupBy('implementation_status')
            ->pluck('total', 'implementation_status');

        $categories = [];
        $values = [];

        foreach (Control::IMPLEMENTATION_STATUSES as $status) {
            $categories[] = [
                'key' => $status,
                'label' => $status,
                'drill' => $this->drill('controls.index', ['implementation_status' => $status]),
            ];
            $values[] = (int) ($counts[$status] ?? 0);
        }

        $total = array_sum($values);
        $implemented = (int) ($counts['Implemented'] ?? 0) + (int) ($counts['Verified'] ?? 0);

        return [
            'kind' => 'series',
            'categories' => $categories,
            'series' => [['key' => 'implementation', 'label' => 'Controls', 'slot' => 1, 'values' => $values]],
            'total' => $total,
            'value' => $this->percent($implemented, $total),
            'unit' => 'percent',
            'caption' => "{$implemented} of {$total} distributed controls implemented",
            'empty' => $total === 0,
        ];
    }

    private function baseQuery(array $config)
    {
        return Control::query()
            ->where('controls.is_template', false)
            ->where('controls.status', 'Active')
            ->when($config['unit_id'] ?? null, fn ($q, $u) => $q->where('controls.unit_id', $u))
            ->when($config['key_only'] ?? false, fn ($q) => $q->where('controls.is_key_control', true));
    }
}
