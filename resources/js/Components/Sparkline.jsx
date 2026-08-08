/**
 * Inline trend for a table row. Deliberately minimal: one 2px line, one
 * end marker, no axes. The number beside it in the table carries the
 * value — the sparkline only carries the shape.
 */
export default function Sparkline({ series = [], width = 96, height = 24, colour = 'var(--color-primary)', breach = false }) {
    if (!series || series.length < 2) {
        return <span className="text-xs text-gray-300">—</span>;
    }

    const min = Math.min(...series);
    const max = Math.max(...series);
    const span = max - min || 1;
    const step = (width - 4) / (series.length - 1);

    const points = series.map((value, index) => [
        2 + index * step,
        height - 3 - ((value - min) / span) * (height - 6),
    ]);

    const path = points.map(([x, y], index) => `${index === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`).join(' ');
    const [lastX, lastY] = points[points.length - 1];
    const stroke = breach ? 'var(--color-error)' : colour;

    return (
        <svg
            width={width}
            height={height}
            viewBox={`0 0 ${width} ${height}`}
            role="img"
            aria-label={`Trend across ${series.length} periods, latest ${series[series.length - 1]}`}
            className="overflow-visible"
        >
            <path d={path} fill="none" stroke={stroke} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
            <circle cx={lastX} cy={lastY} r="3" fill={stroke} />
        </svg>
    );
}
