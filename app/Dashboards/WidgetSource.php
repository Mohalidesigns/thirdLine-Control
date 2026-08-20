<?php

namespace App\Dashboards;

use App\Models\User;
use Closure;

/**
 * One registered dataset a widget may draw on (13.2).
 *
 * A widget stores a KEY, never a query. The frontend can therefore ask only
 * for datasets that exist here, each of which resolves inside the tenant
 * scope and behind a declared permission — there is no path from a saved
 * dashboard to an arbitrary read of the database.
 *
 * ── The payload contract ────────────────────────────────────────────────
 * Every resolver returns an array shaped like this. The chart components on
 * the other side are written against this shape and nothing else, which is
 * what makes one design system across seventeen widget types possible.
 *
 *   kind        'scalar' | 'series' | 'rows' | 'matrix' | 'tree'
 *   value       scalar only: the number the tile leads with
 *   unit        'count' | 'percent' | 'money' | 'days'
 *   currency    ISO-4217 when unit is money (R7 — minor units in `value`)
 *   caption     supporting line under a scalar
 *   delta       ['value' => float, 'direction' => 'up'|'down', 'period' => string]
 *   categories  [['key','label','drill'?]]   x-axis / slices / rows
 *   series      [['key','label','slot'?,'tone'?,'values'=>[…]]]  aligned to categories
 *   columns     [['key','label','align'?]]   rows only
 *   rows        [['cells'=>[…],'drill'?,'tone'?]]
 *   matrix      ['x'=>[…],'y'=>[…],'cells'=>[['x','y','value','tone','drill'?]]]
 *   tree        [['id','label','value','pct'?,'tone'?,'drill'?,'children'=>[…]]]
 *   total       the aggregate the parts sum to
 *   drill       whole-widget target: ['route' => …, 'params' => […]]
 *   empty       true when there is genuinely nothing to show
 *
 * A `drill` is emitted by the same code that produced the number, carrying
 * the exact filters it aggregated over — that is why a drill-down cannot
 * drift from its aggregate.
 */
final class WidgetSource
{
    /**
     * @param  array<int, string>  $types  widget types this dataset can render as
     * @param  array<string, mixed>  $defaults  title / widget_type / w / h for the palette
     * @param  array<int, string>  $filters  config keys the resolver honours
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $group,
        public readonly ?string $permission,
        public readonly array $types,
        public readonly array $defaults,
        public readonly array $filters,
        private readonly Closure $resolver,
        public readonly bool $selfScoped = false,
    ) {}

    /** @return array<string, mixed> */
    public function resolve(User $user, array $config = []): array
    {
        return ($this->resolver)($user, $config);
    }

    public function visibleTo(User $user): bool
    {
        return $this->permission === null || $user->can($this->permission);
    }

    /** Palette entry for the builder's widget picker. */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'group' => $this->group,
            'permission' => $this->permission,
            'types' => $this->types,
            'defaults' => $this->defaults,
            'filters' => $this->filters,
            'self_scoped' => $this->selfScoped,
        ];
    }
}
