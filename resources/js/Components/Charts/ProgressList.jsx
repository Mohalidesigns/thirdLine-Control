import { router } from '@inertiajs/react';
import { INK, STATUS, markColour } from './palette';

/**
 * A meter per category. The fill carries the value and the unfilled track is
 * a lighter step of the same ramp, so state reads across the whole bar rather
 * than only where the fill ends.
 */
export default function ProgressList({ payload, onDrill }) {
    const categories = payload.categories ?? [];
    const series = payload.series?.[0];

    if (!series || categories.length === 0) {
        return null;
    }

    const max = payload.max ?? Math.max(1, ...series.values.map((value) => Number(value ?? 0)));

    return (
        <ul className="space-y-3">
            {categories.map((category, index) => {
                const value = Number(series.values[index] ?? 0);
                const pct = Math.min(100, (value / max) * 100);

                return (
                    <li key={category.key}>
                        <div className="mb-1 flex items-baseline justify-between gap-3 text-xs">
                            <button
                                type="button"
                                disabled={!category.drill || onDrill === false}
                                onClick={() => category.drill && router.visit(route(category.drill.route, category.drill.params))}
                                className="truncate text-left underline-offset-2 enabled:hover:underline"
                                style={{ color: INK.muted }}
                            >
                                {category.label}
                                {category.note && <span className="ms-1 text-[10px]">({category.note})</span>}
                            </button>
                            <span className="shrink-0 font-semibold tabular-nums" style={{ color: INK.primary }}>
                                {payload.unit === 'percent' ? `${value.toFixed(0)}%` : value.toLocaleString()}
                            </span>
                        </div>
                        <span className="progress-bar block" style={{ background: 'var(--chart-ord-1)' }}>
                            <span
                                className="progress-bar-fill block"
                                style={{ width: `${pct}%`, background: markColour(series, index, 0) }}
                            />
                        </span>
                    </li>
                );
            })}

            {payload.caption && (
                <li className="pt-1 text-xs" style={{ color: STATUS.muted }}>
                    {payload.caption}
                </li>
            )}
        </ul>
    );
}
