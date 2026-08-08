<?php

namespace App\Dashboards\Sources;

use App\Dashboards\WidgetSource;
use Closure;

/**
 * Shared scaffolding for the registered dataset providers (13.2). Each
 * concrete provider returns the WidgetSource definitions for one domain.
 */
abstract class SourceProvider
{
    /** @return array<int, WidgetSource> */
    abstract public function sources(): array;

    /**
     * Severity and rating are STATUS scales, not series identity: they get
     * the reserved status tones and always ship with their label beside
     * them, never colour alone.
     */
    protected const SEVERITY_TONE = [
        'Critical' => 'critical',
        'High' => 'serious',
        'Medium' => 'warning',
        'Low' => 'good',
    ];

    protected const RATING_TONE = [
        'Effective' => 'good',
        'Partially Effective' => 'warning',
        'Ineffective' => 'critical',
        'Not Tested' => 'muted',
    ];

    protected function make(
        string $key,
        string $label,
        string $group,
        ?string $permission,
        array $types,
        array $defaults,
        Closure $resolver,
        array $filters = [],
        bool $selfScoped = false,
    ): WidgetSource {
        return new WidgetSource(
            key: $key,
            label: $label,
            group: $group,
            permission: $permission,
            types: $types,
            defaults: $defaults + ['widget_type' => $types[0], 'title' => $label, 'w' => 4, 'h' => 3],
            filters: $filters,
            resolver: $resolver,
            selfScoped: $selfScoped,
        );
    }

    /** @param array<int, array{0:string,1:int|float,2?:array}> $rows */
    protected function categoriesFrom(array $rows): array
    {
        return array_map(fn (array $row) => [
            'key' => $row[0],
            'label' => $row[0],
            'drill' => $row[2] ?? null,
        ], $rows);
    }

    protected function drill(string $route, array $params = []): array
    {
        return ['route' => $route, 'params' => $params];
    }

    protected function percent(int|float $part, int|float $whole): float
    {
        return $whole > 0 ? round(($part / $whole) * 100, 1) : 0.0;
    }

    /** Last N month buckets as ['2026-03' => 'Mar 26', …], oldest first. */
    protected function monthBuckets(int $months): array
    {
        $buckets = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $moment = now()->startOfMonth()->subMonths($i);
            $buckets[$moment->format('Y-m')] = $moment->format('M y');
        }

        return $buckets;
    }

    protected function emptyPayload(string $kind = 'series'): array
    {
        return ['kind' => $kind, 'empty' => true, 'categories' => [], 'series' => [], 'total' => 0];
    }
}
