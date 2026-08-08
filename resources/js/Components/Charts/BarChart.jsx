import { router } from '@inertiajs/react';
import { ChartHost, TooltipLayer, useTooltip } from './Tooltip';
import { INK, MARKS, formatValue, markColour, niceTicks } from './palette';

/**
 * Columns, stacked columns and horizontal bars — one component, because they
 * are the same mark in three orientations and splitting them is how a design
 * system drifts.
 *
 * Mark spec, held to in all three: bars capped at 24px so the band keeps its
 * air, a 4px rounded data-end with the baseline end left square, a 2px
 * surface gap between touching fills (stack segments and neighbours alike),
 * hairline solid gridlines, and no stroke around any fill.
 */
export default function BarChart({
    payload,
    orientation = 'vertical',
    stacked = false,
    height = 240,
    onDrill,
}) {
    const { tip, handlers } = useTooltip();

    const categories = payload.categories ?? [];
    const series = payload.series ?? [];
    const unit = payload.unit;

    if (categories.length === 0 || series.length === 0) {
        return null;
    }

    const totals = categories.map((_, index) =>
        stacked
            ? series.reduce((sum, one) => sum + Number(one.values[index] ?? 0), 0)
            : Math.max(...series.map((one) => Number(one.values[index] ?? 0))),
    );

    const max = payload.max ?? Math.max(...totals, 1);
    const ticks = niceTicks(max);
    const scaleMax = ticks[ticks.length - 1] || 1;

    const drill = (category) => {
        if (category?.drill && onDrill !== false) {
            router.visit(route(category.drill.route, category.drill.params));
        }
    };

    if (orientation === 'horizontal') {
        return (
            <ChartHost>
                <TooltipLayer tip={tip} />
                <ul className="space-y-2">
                    {categories.map((category, index) => (
                        <li key={category.key}>
                            <div className="mb-1 flex items-baseline justify-between gap-3 text-xs">
                                <span className="truncate" style={{ color: INK.muted }}>
                                    {category.label}
                                    {category.note && <span className="ms-1 text-[10px]">({category.note})</span>}
                                </span>
                                <span className="shrink-0 font-semibold tabular-nums" style={{ color: INK.primary }}>
                                    {formatValue(totals[index], unit, payload)}
                                </span>
                            </div>
                            <div className="flex h-3 w-full items-stretch gap-[2px] rounded-sm bg-gray-50">
                                {series.map((one, seriesIndex) => {
                                    const value = Number(one.values[index] ?? 0);
                                    const width = `${Math.max(value > 0 ? 1 : 0, (value / scaleMax) * 100)}%`;

                                    return (
                                        <button
                                            key={one.key}
                                            type="button"
                                            aria-label={`${category.label} ${one.label}: ${formatValue(value, unit, payload)}`}
                                            className="h-full rounded-e-[4px] outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]"
                                            style={{ width, background: markColour(one, index, seriesIndex) }}
                                            onClick={() => drill(category)}
                                            {...handlers(`${category.label} · ${one.label}: ${formatValue(value, unit, payload)}`)}
                                        />
                                    );
                                })}
                            </div>
                        </li>
                    ))}
                </ul>
            </ChartHost>
        );
    }

    const plotHeight = height - 28;
    const bandWidth = 100 / categories.length;
    // Cap the bar, never fill the band — the leftover is deliberate air.
    const barWidth = Math.min(MARKS.barMaxThickness, (bandWidth / (stacked ? 1 : series.length)) * 0.62 * 6);

    return (
        <ChartHost>
            <TooltipLayer tip={tip} />
            <div className="relative" style={{ height: plotHeight }}>
                {ticks.map((tick) => (
                    <div
                        key={tick}
                        className="absolute inset-x-0 flex items-center"
                        style={{ bottom: `${(tick / scaleMax) * 100}%` }}
                    >
                        <span className="w-10 shrink-0 pe-1 text-right text-[10px] tabular-nums" style={{ color: INK.muted }}>
                            {formatValue(tick, unit === 'percent' ? 'percent' : null, payload)}
                        </span>
                        <span className="h-px flex-1" style={{ background: INK.grid }} aria-hidden="true" />
                    </div>
                ))}

                <div className="absolute inset-y-0 left-10 right-0 flex items-end">
                    {categories.map((category, index) => (
                        <div key={category.key} className="flex h-full flex-1 items-end justify-center gap-[2px]">
                            {stacked ? (
                                <div
                                    className="flex flex-col-reverse justify-start gap-[2px]"
                                    style={{ width: barWidth, height: '100%' }}
                                >
                                    {series.map((one, seriesIndex) => {
                                        const value = Number(one.values[index] ?? 0);

                                        if (value <= 0) {
                                            return null;
                                        }

                                        return (
                                            <button
                                                key={one.key}
                                                type="button"
                                                aria-label={`${category.label} ${one.label}: ${formatValue(value, unit, payload)}`}
                                                className="w-full outline-none first:rounded-t-[4px] focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]"
                                                style={{
                                                    height: `${(value / scaleMax) * 100}%`,
                                                    background: markColour(one, index, seriesIndex),
                                                }}
                                                onClick={() => drill(category)}
                                                {...handlers(`${category.label} · ${one.label}: ${formatValue(value, unit, payload)}`)}
                                            />
                                        );
                                    })}
                                </div>
                            ) : (
                                series.map((one, seriesIndex) => {
                                    const value = Number(one.values[index] ?? 0);

                                    return (
                                        <button
                                            key={one.key}
                                            type="button"
                                            aria-label={`${category.label} ${one.label}: ${formatValue(value, unit, payload)}`}
                                            className="rounded-t-[4px] outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]"
                                            style={{
                                                width: barWidth,
                                                height: `${Math.max(value > 0 ? 1 : 0, (value / scaleMax) * 100)}%`,
                                                background: markColour(one, index, seriesIndex),
                                            }}
                                            onClick={() => drill(category)}
                                            {...handlers(`${category.label} · ${one.label}: ${formatValue(value, unit, payload)}`)}
                                        />
                                    );
                                })
                            )}
                        </div>
                    ))}
                </div>

                <div className="absolute bottom-0 left-10 right-0 h-px" style={{ background: INK.axis }} aria-hidden="true" />
            </div>

            <div className="ms-10 flex" style={{ height: 28 }}>
                {categories.map((category) => (
                    <span
                        key={category.key}
                        className="flex-1 truncate px-0.5 pt-1.5 text-center text-[10px]"
                        style={{ color: INK.muted }}
                        title={category.label}
                    >
                        {category.label}
                    </span>
                ))}
            </div>
        </ChartHost>
    );
}
