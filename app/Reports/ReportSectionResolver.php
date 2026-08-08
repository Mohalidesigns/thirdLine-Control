<?php

namespace App\Reports;

use App\Dashboards\WidgetRegistry;
use App\Models\Framework;
use App\Models\OrganisationUnit;
use App\Models\ReportDefinition;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Turns a stored section list into a rendered document (13.3).
 *
 * The output is engine-agnostic on purpose: one resolved document feeds the
 * PDF, Word, Excel and PowerPoint writers, so the four never drift into
 * saying different things about the same period.
 *
 * Every source-backed section resolves through WidgetRegistry, which means a
 * report can never read data its requester could not read on a dashboard.
 */
class ReportSectionResolver
{
    public function __construct(private WidgetRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function resolve(ReportDefinition $definition, User $user, array $parameters = []): array
    {
        $context = $this->context($definition, $user, $parameters);
        $sections = [];

        foreach ($definition->sections ?? [] as $descriptor) {
            $section = $this->section($descriptor, $user, $context);

            if ($section !== null) {
                $sections[] = $section;
            }
        }

        return [
            'code' => $definition->code,
            'title' => $this->interpolate($definition->name, $context),
            'subtitle' => $this->interpolate($definition->description ?? '', $context),
            'report_type' => $definition->report_type,
            'confidentiality' => $definition->confidentiality,
            'generated_at' => $context['generated_at'],
            'generated_by' => $user->name,
            'context' => $context,
            'styling' => $definition->styling ?? [],
            'header' => $definition->header_config ?? [],
            'footer' => $definition->footer_config ?? [],
            'cover' => $definition->cover_page_config ?? [],
            'sections' => $sections,
        ];
    }

    /**
     * Parameter resolution. Dates render in the tenant's timezone, never the
     * server's — a report headed "Q3 2026" must mean the same quarter to the
     * Lagos office that produced it and the London parent reading it (R7).
     *
     * @return array<string, mixed>
     */
    public function context(ReportDefinition $definition, User $user, array $parameters): array
    {
        $timezone = $user->tenant?->timezone ?? config('app.timezone', 'Africa/Lagos');
        $now = Carbon::now($timezone);

        [$from, $to, $label] = $this->period($parameters['period'] ?? 'current_quarter', $now);

        $entity = ! empty($parameters['entity_id'])
            ? OrganisationUnit::find($parameters['entity_id'])
            : null;

        $framework = ! empty($parameters['framework_id'])
            ? Framework::find($parameters['framework_id'])
            : null;

        return [
            'tenant' => $user->tenant?->name ?? config('app.name'),
            'timezone' => $timezone,
            'generated_at' => $now->format('d M Y H:i'),
            'today' => $now->toDateString(),
            'period' => $label,
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'entity' => $entity?->name ?? 'Group-wide',
            'entity_id' => $entity?->id,
            'framework' => $framework?->name ?? 'All frameworks',
            'framework_id' => $framework?->id,
            'owner' => $user->name,
            'parameters' => $parameters,
        ];
    }

    /** @return array{0: Carbon, 1: Carbon, 2: string} */
    private function period(string $period, Carbon $now): array
    {
        return match ($period) {
            'current_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth(), $now->format('F Y')],
            'last_month' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
                $now->copy()->subMonthNoOverflow()->format('F Y'),
            ],
            'current_year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear(), 'FY '.$now->year],
            'last_year' => [
                $now->copy()->subYear()->startOfYear(),
                $now->copy()->subYear()->endOfYear(),
                'FY '.($now->year - 1),
            ],
            'last_quarter' => [
                $now->copy()->subQuarterNoOverflow()->startOfQuarter(),
                $now->copy()->subQuarterNoOverflow()->endOfQuarter(),
                'Q'.$now->copy()->subQuarterNoOverflow()->quarter.' '.$now->copy()->subQuarterNoOverflow()->year,
            ],
            default => [
                $now->copy()->startOfQuarter(),
                $now->copy()->endOfQuarter(),
                'Q'.$now->quarter.' '.$now->year,
            ],
        };
    }

    /** @return array<string, mixed>|null */
    private function section(array $descriptor, User $user, array $context): ?array
    {
        $type = $descriptor['type'] ?? 'narrative';
        $title = $this->interpolate($descriptor['title'] ?? '', $context);

        return match ($type) {
            'cover' => [
                'type' => 'cover',
                'title' => $title ?: $context['tenant'],
                'subtitle' => $this->interpolate($descriptor['subtitle'] ?? '', $context),
                'fields' => [
                    ['label' => 'Period', 'value' => $context['period']],
                    ['label' => 'Entity', 'value' => $context['entity']],
                    ['label' => 'Prepared by', 'value' => $context['owner']],
                    ['label' => 'Generated', 'value' => $context['generated_at']],
                ],
            ],

            'toc' => ['type' => 'toc', 'title' => $title ?: 'Contents', 'entries' => []],

            'narrative', 'appendix' => [
                'type' => $type,
                'title' => $title,
                'body' => $this->interpolate($descriptor['body'] ?? '', $context, $user),
            ],

            'page_break' => ['type' => 'page_break'],

            'signature_block' => [
                'type' => 'signature_block',
                'title' => $title ?: 'Sign-off',
                'signatories' => array_map(fn ($signatory) => [
                    'role' => is_array($signatory) ? ($signatory['role'] ?? '') : (string) $signatory,
                    'name' => is_array($signatory) ? ($signatory['name'] ?? null) : null,
                ], $descriptor['signatories'] ?? []),
            ],

            'kpi_row' => $this->kpiRow($descriptor, $user, $context, $title),

            'chart', 'table' => $this->dataSection($type, $descriptor, $user, $context, $title),

            default => null,
        };
    }

    private function kpiRow(array $descriptor, User $user, array $context, string $title): array
    {
        $items = [];

        foreach ($descriptor['items'] ?? [] as $item) {
            $payload = $this->payload($item['source'] ?? null, $user, ($item['config'] ?? []) + $this->scopeFrom($context));

            if ($payload === null || ($payload['kind'] ?? null) === 'denied') {
                continue;
            }

            $items[] = [
                'label' => $item['label'] ?? $payload['label'] ?? ($item['source'] ?? ''),
                'value' => $payload['formatted'] ?? $this->formatValue($payload),
                'caption' => $payload['caption'] ?? null,
                'tone' => $payload['tone'] ?? null,
            ];
        }

        return ['type' => 'kpi_row', 'title' => $title, 'items' => $items];
    }

    private function dataSection(string $type, array $descriptor, User $user, array $context, string $title): ?array
    {
        $payload = $this->payload(
            $descriptor['source'] ?? null,
            $user,
            ($descriptor['config'] ?? []) + $this->scopeFrom($context),
        );

        if ($payload === null) {
            return null;
        }

        // A refused tile is stated, not silently dropped: a reader must know
        // the section exists and that the requester could not see it.
        if (($payload['kind'] ?? null) === 'denied') {
            return ['type' => 'narrative', 'title' => $title, 'body' => 'Withheld — the requester is not entitled to this data.'];
        }

        return [
            'type' => $type,
            'title' => $title,
            'chart_type' => $descriptor['chart_type'] ?? 'bar',
            'source' => $descriptor['source'] ?? null,
            'data' => $payload,
            'table' => $this->tabulate($payload),
        ];
    }

    /**
     * Every chart section also carries its table form. That is the report's
     * accessibility twin and the reason a sub-3:1 fill is legal at all.
     *
     * @return array{columns: array<int, string>, rows: array<int, array<int, mixed>>}
     */
    public function tabulate(array $payload): array
    {
        return match ($payload['kind'] ?? null) {
            'rows' => [
                'columns' => array_column($payload['columns'] ?? [], 'label'),
                'rows' => array_map(fn (array $row) => $row['cells'] ?? [], $payload['rows'] ?? []),
            ],

            'series' => [
                'columns' => array_merge(
                    [''],
                    array_map(fn (array $series) => $series['label'], $payload['series'] ?? []),
                ),
                'rows' => array_map(
                    fn (array $category, int $index) => array_merge(
                        [$category['label']],
                        array_map(fn (array $series) => $series['values'][$index] ?? 0, $payload['series'] ?? []),
                    ),
                    $payload['categories'] ?? [],
                    array_keys($payload['categories'] ?? []),
                ),
            ],

            'matrix' => [
                'columns' => array_merge([''], array_column($payload['matrix']['x'] ?? [], 'label')),
                'rows' => array_map(fn (array $row) => array_merge(
                    [$row['label']],
                    array_map(
                        fn (array $column) => collect($payload['matrix']['cells'] ?? [])
                            ->firstWhere(fn (array $cell) => $cell['x'] === $column['key'] && $cell['y'] === $row['key'])['value'] ?? 0,
                        $payload['matrix']['x'] ?? [],
                    ),
                ), $payload['matrix']['y'] ?? []),
            ],

            'tree' => [
                'columns' => ['Unit', 'Own', 'Rolled up'],
                'rows' => $this->flattenTree($payload['tree'] ?? []),
            ],

            default => [
                'columns' => ['Measure', 'Value'],
                'rows' => [[$payload['caption'] ?? 'Value', $payload['formatted'] ?? $this->formatValue($payload)]],
            ],
        };
    }

    private function flattenTree(array $nodes, int $depth = 0): array
    {
        $rows = [];

        foreach ($nodes as $node) {
            $rows[] = [str_repeat('— ', $depth).$node['label'], $node['own'] ?? 0, $node['value'] ?? 0];
            $rows = array_merge($rows, $this->flattenTree($node['children'] ?? [], $depth + 1));
        }

        return $rows;
    }

    private function payload(?string $sourceKey, User $user, array $config): ?array
    {
        if (! $sourceKey) {
            return null;
        }

        $source = $this->registry->find($sourceKey);

        if (! $source) {
            return null;
        }

        if (! $source->visibleTo($user)) {
            return ['kind' => 'denied'];
        }

        return $this->registry->resolve($user, $source, $config);
    }

    /** Report parameters flow into every source that accepts them. */
    private function scopeFrom(array $context): array
    {
        return array_filter([
            'unit_id' => $context['entity_id'] ?? null,
            'entity_id' => $context['entity_id'] ?? null,
        ]);
    }

    private function formatValue(array $payload): string
    {
        $value = $payload['value'] ?? null;

        if ($value === null) {
            return '—';
        }

        return match ($payload['unit'] ?? null) {
            'percent' => rtrim(rtrim(number_format((float) $value, 1), '0'), '.').'%',
            'money' => $payload['formatted'] ?? number_format((int) $value / 100, 2),
            default => is_numeric($value) ? number_format((float) $value) : (string) $value,
        };
    }

    /**
     * Variable interpolation for narrative text. `{{ period }}` and friends
     * come from the context; `{{ metric:key }}` pulls a live number from a
     * registered source, so a narrative paragraph cannot quote a figure the
     * rest of the document contradicts.
     */
    public function interpolate(string $text, array $context, ?User $user = null): string
    {
        if ($text === '' || ! str_contains($text, '{{')) {
            return $text;
        }

        return preg_replace_callback('/\{\{\s*([a-z0-9_:.]+)\s*\}\}/i', function (array $match) use ($context, $user) {
            $token = $match[1];

            if (str_starts_with($token, 'metric:') && $user) {
                $payload = $this->payload(substr($token, 7), $user, []);

                return $payload ? ($payload['formatted'] ?? $this->formatValue($payload)) : $match[0];
            }

            $value = $context[$token] ?? data_get($context['parameters'] ?? [], $token);

            return is_scalar($value) ? (string) $value : $match[0];
        }, $text) ?? $text;
    }
}
