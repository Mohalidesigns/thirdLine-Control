import { ChartHost, TooltipLayer, useTooltip } from './Tooltip';
import { INK, MARKS, formatValue, slotColour, niceTicks } from './palette';

/**
 * Change over time. One y-axis, always — two scales on one plot invent a
 * correlation the data does not contain, and it is the single most common
 * way a chart misleads. Two measures of different scale get two charts.
 *
 * Lines are 2px with round joins; end markers are 8px with a 2px surface
 * ring so they stay legible where series cross. Only the last point of each
 * series is direct-labelled — a number on every point is chaos and goes
 * unread.
 */
export default function LineChart({ payload, height = 240 }) {
    const { tip, handlers } = useTooltip();

    const categories = payload.categories ?? [];
    const series = payload.series ?? [];
    const unit = payload.unit;

    if (categories.length < 2 || series.length === 0) {
        return null;
    }

    const max = Math.max(
        1,
        ...series.flatMap((one) => one.values.map((value) => Number(value ?? 0))),
    );

    const ticks = niceTicks(max);
    const scaleMax = ticks[ticks.length - 1] || 1;

    const width = 100;
    const plotHeight = height - 28;
    const step = width / (categories.length - 1);

    const pointsFor = (one) =>
        one.values.map((value, index) => ({
            x: index * step,
            y: 100 - (Number(value ?? 0) / scaleMax) * 100,
            value: Number(value ?? 0),
            label: categories[index]?.label,
        }));

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

                <svg
                    className="absolute inset-y-0 left-10 right-0 h-full w-[calc(100%-2.5rem)] overflow-visible"
                    viewBox={`0 0 ${width} 100`}
                    preserveAspectRatio="none"
                    role="img"
                    aria-label={`${series.map((one) => one.label).join(', ')} across ${categories.length} periods`}
                >
                    {series.map((one, seriesIndex) => {
                        const points = pointsFor(one);
                        const colour = slotColour(one, seriesIndex);

                        return (
                            <polyline
                                key={one.key}
                                points={points.map((point) => `${point.x},${point.y}`).join(' ')}
                                fill="none"
                                stroke={colour}
                                strokeWidth={MARKS.lineWidth}
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                vectorEffect="non-scaling-stroke"
                            />
                        );
                    })}
                </svg>

                {/* Markers live outside the stretched SVG so the 8px hit area and
                    the surface ring stay circular whatever the aspect ratio. */}
                <div className="absolute inset-y-0 left-10 right-0">
                    {series.map((one, seriesIndex) => {
                        const colour = slotColour(one, seriesIndex);

                        return pointsFor(one).map((point, index) => (
                            <button
                                key={`${one.key}-${index}`}
                                type="button"
                                aria-label={`${one.label} ${point.label}: ${formatValue(point.value, unit, payload)}`}
                                className="absolute -translate-x-1/2 -translate-y-1/2 rounded-full outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]"
                                style={{
                                    left: `${point.x}%`,
                                    top: `${point.y}%`,
                                    width: MARKS.markerRadius * 2,
                                    height: MARKS.markerRadius * 2,
                                    background: colour,
                                    boxShadow: `0 0 0 ${MARKS.surfaceRing}px ${INK.surface}`,
                                }}
                                {...handlers(`${point.label} · ${one.label}: ${formatValue(point.value, unit, payload)}`)}
                            />
                        ));
                    })}
                </div>

                <div className="absolute bottom-0 left-10 right-0 h-px" style={{ background: INK.axis }} aria-hidden="true" />
            </div>

            <div className="ms-10 flex" style={{ height: 28 }}>
                {categories.map((category) => (
                    <span
                        key={category.key}
                        className="flex-1 truncate px-0.5 pt-1.5 text-center text-[10px]"
                        style={{ color: INK.muted }}
                    >
                        {category.label}
                    </span>
                ))}
            </div>
        </ChartHost>
    );
}
