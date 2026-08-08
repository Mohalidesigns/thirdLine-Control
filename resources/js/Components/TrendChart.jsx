import { useMemo, useState } from 'react';
import { formatNumber } from '@/utils';

/**
 * The full metric trend: one series, its target, and the threshold bands
 * behind it. One y-axis only — a second measure would get its own chart.
 *
 * Band colours come from the metric's own threshold records; each band is
 * named in the legend and every point carries its level in the tooltip, so
 * nothing depends on colour alone. A table view renders the same numbers.
 */
export default function TrendChart({ trend = [], thresholds = [], unit = '', height = 260 }) {
    const [hover, setHover] = useState(null);
    const [showTable, setShowTable] = useState(false);

    const width = 720;
    const padding = { top: 16, right: 16, bottom: 30, left: 52 };
    const plotWidth = width - padding.left - padding.right;
    const plotHeight = height - padding.top - padding.bottom;

    const { min, max, points } = useMemo(() => {
        if (trend.length === 0) return { min: 0, max: 1, points: [] };

        const values = trend.flatMap((row) => [row.value, row.target].filter((v) => v !== null && v !== undefined));
        const bandValues = thresholds.flatMap((t) => [t.value_from, t.value_to].filter((v) => v !== null && v !== undefined));
        const all = [...values, ...bandValues];

        let lo = Math.min(...all);
        let hi = Math.max(...all);
        if (hi === lo) hi = lo + 1;
        const pad = (hi - lo) * 0.08;
        lo -= pad;
        hi += pad;

        const step = trend.length > 1 ? plotWidth / (trend.length - 1) : 0;

        return {
            min: lo,
            max: hi,
            points: trend.map((row, index) => ({
                ...row,
                x: padding.left + index * step,
                y: padding.top + plotHeight - ((row.value - lo) / (hi - lo)) * plotHeight,
                targetY:
                    row.target === null || row.target === undefined
                        ? null
                        : padding.top + plotHeight - ((row.target - lo) / (hi - lo)) * plotHeight,
            })),
        };
    }, [trend, thresholds, plotWidth, plotHeight, padding.left, padding.top]);

    if (trend.length === 0) {
        return <p className="p-6 text-sm text-[var(--color-text-secondary)]">No readings captured yet.</p>;
    }

    const scaleY = (value) => padding.top + plotHeight - ((value - min) / (max - min)) * plotHeight;
    const linePath = points.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ');
    const targetPath = points
        .filter((p) => p.targetY !== null)
        .map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x.toFixed(1)},${p.targetY.toFixed(1)}`)
        .join(' ');

    const ticks = [min, min + (max - min) / 2, max];

    return (
        <div>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-3 text-xs text-[var(--color-text-secondary)]">
                    <span className="flex items-center gap-1.5">
                        <span className="inline-block h-0.5 w-4 rounded bg-[var(--color-primary)]" />
                        Actual
                    </span>
                    {points.some((p) => p.targetY !== null) && (
                        <span className="flex items-center gap-1.5">
                            <span className="inline-block h-0.5 w-4 rounded border-t-2 border-dashed border-[var(--color-text-secondary)]" />
                            Target
                        </span>
                    )}
                    {thresholds.map((threshold) => (
                        <span key={threshold.id ?? threshold.level} className="flex items-center gap-1.5">
                            <span
                                className="inline-block h-3 w-3 rounded-sm border"
                                style={{ backgroundColor: tint(threshold.colour_hex, 0.25), borderColor: threshold.colour_hex }}
                            />
                            {threshold.level}
                        </span>
                    ))}
                </div>
                <button
                    type="button"
                    onClick={() => setShowTable((v) => !v)}
                    className="text-xs font-semibold text-[var(--color-primary)] underline-offset-2 hover:underline"
                >
                    {showTable ? 'Show chart' : 'Show as table'}
                </button>
            </div>

            {showTable ? (
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Period</th>
                                <th>Value</th>
                                <th>Target</th>
                                <th>Level</th>
                            </tr>
                        </thead>
                        <tbody>
                            {trend.map((row) => (
                                <tr key={row.period}>
                                    <td className="font-mono text-xs">{row.period}</td>
                                    <td className="tabular-nums">{formatNumber(row.value)}</td>
                                    <td className="tabular-nums">{row.target !== null ? formatNumber(row.target) : '—'}</td>
                                    <td>{row.level ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <div className="relative overflow-x-auto">
                    <svg
                        viewBox={`0 0 ${width} ${height}`}
                        className="w-full min-w-[560px]"
                        role="img"
                        aria-label={`Trend chart across ${trend.length} periods`}
                        onMouseLeave={() => setHover(null)}
                    >
                        {/* Threshold bands sit behind everything else, painted
                            least-severe first so an open-ended Red band never
                            covers the Critical zone above it. */}
                        {[...thresholds].reverse().map((threshold) => {
                            const band = bandExtent(threshold, min, max);
                            if (!band) return null;
                            const top = scaleY(band.upper);
                            const bottom = scaleY(band.lower);
                            return (
                                <rect
                                    key={`band-${threshold.id ?? threshold.level}`}
                                    x={padding.left}
                                    y={Math.min(top, bottom)}
                                    width={plotWidth}
                                    height={Math.abs(bottom - top)}
                                    fill={tint(threshold.colour_hex, 0.14)}
                                />
                            );
                        })}

                        {ticks.map((tick) => (
                            <g key={tick}>
                                <line
                                    x1={padding.left}
                                    x2={width - padding.right}
                                    y1={scaleY(tick)}
                                    y2={scaleY(tick)}
                                    stroke="#e1e0d9"
                                    strokeWidth="1"
                                />
                                <text
                                    x={padding.left - 8}
                                    y={scaleY(tick) + 4}
                                    textAnchor="end"
                                    className="fill-[var(--color-text-secondary)] text-[10px]"
                                    style={{ fontVariantNumeric: 'tabular-nums' }}
                                >
                                    {formatNumber(Math.round(tick * 100) / 100)}
                                </text>
                            </g>
                        ))}

                        {targetPath && (
                            <path d={targetPath} fill="none" stroke="var(--color-text-secondary)" strokeWidth="2" strokeDasharray="5 4" />
                        )}

                        <path d={linePath} fill="none" stroke="var(--color-primary)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />

                        {points.map((point) => (
                            <circle
                                key={point.period}
                                cx={point.x}
                                cy={point.y}
                                r={point.breach ? 5 : 3.5}
                                fill={point.breach ? 'var(--color-error)' : 'var(--color-primary)'}
                                stroke="#ffffff"
                                strokeWidth="2"
                            />
                        ))}

                        {hover && (
                            <line
                                x1={hover.x}
                                x2={hover.x}
                                y1={padding.top}
                                y2={padding.top + plotHeight}
                                stroke="var(--color-text-secondary)"
                                strokeWidth="1"
                                strokeDasharray="3 3"
                            />
                        )}

                        {/* Hit targets are wider than the marks. */}
                        {points.map((point, index) => (
                            <rect
                                key={`hit-${point.period}`}
                                x={point.x - (plotWidth / Math.max(1, points.length - 1)) / 2}
                                y={padding.top}
                                width={plotWidth / Math.max(1, points.length - 1) || plotWidth}
                                height={plotHeight}
                                fill="transparent"
                                onMouseEnter={() => setHover({ ...point, index })}
                            />
                        ))}

                        {points
                            .filter((_, index) => index % Math.ceil(points.length / 8) === 0)
                            .map((point) => (
                                <text
                                    key={`x-${point.period}`}
                                    x={point.x}
                                    y={height - 10}
                                    textAnchor="middle"
                                    className="fill-[var(--color-text-secondary)] text-[10px]"
                                >
                                    {point.period}
                                </text>
                            ))}
                    </svg>

                    {hover && (
                        <div
                            className="pointer-events-none absolute z-10 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs shadow-lg"
                            style={{
                                left: `${(hover.x / width) * 100}%`,
                                top: 8,
                                transform: 'translateX(-50%)',
                            }}
                        >
                            <p className="font-mono font-semibold text-[var(--color-text-primary)]">{hover.period}</p>
                            <p className="tabular-nums">
                                {formatNumber(hover.value)} {unit}
                            </p>
                            {hover.target !== null && hover.target !== undefined && (
                                <p className="tabular-nums text-[var(--color-text-secondary)]">
                                    target {formatNumber(hover.target)}
                                </p>
                            )}
                            {hover.level && (
                                <p className={hover.breach ? 'font-semibold text-[var(--color-error)]' : ''}>
                                    {hover.level}
                                    {hover.breach ? ' · breach' : ''}
                                </p>
                            )}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

/** The y-range a threshold band covers, clipped to the plotted domain. */
function bandExtent(threshold, min, max) {
    const from = threshold.value_from;
    const to = threshold.value_to;

    const extent = {
        gt: { lower: from, upper: max },
        gte: { lower: from, upper: max },
        lt: { lower: min, upper: from },
        lte: { lower: min, upper: from },
        between: { lower: from, upper: to },
        outside: null,
    }[threshold.operator];

    if (!extent || extent.lower === null || extent.upper === null) return null;

    return {
        lower: Math.max(min, Math.min(extent.lower, extent.upper)),
        upper: Math.min(max, Math.max(extent.lower, extent.upper)),
    };
}

function tint(hex, alpha) {
    if (!hex || hex.length !== 7) return `rgba(225, 224, 217, ${alpha})`;
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}
