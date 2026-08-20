import { router } from '@inertiajs/react';
import { ChartHost, TooltipLayer, useTooltip } from './Tooltip';
import { INK, formatValue, markColour } from './palette';

/**
 * Part-to-whole, at a glance, six segments or fewer. Deliberately not offered
 * for comparing close values — a bar does that better and this does it badly.
 *
 * Segments are separated by a surface-coloured gap rather than a stroke, and
 * every segment is direct-labelled in the key beside the ring, so identity
 * never rests on colour alone.
 */
export default function DonutChart({ payload, size = 168, stroke = 26, onDrill }) {
    const { tip, handlers } = useTooltip();

    const categories = payload.categories ?? [];
    const series = payload.series?.[0];

    if (!series || categories.length === 0) {
        return null;
    }

    const values = categories.map((_, index) => Number(series.values[index] ?? 0));
    const total = values.reduce((sum, value) => sum + value, 0);

    if (total <= 0) {
        return null;
    }

    const radius = (size - stroke) / 2;
    const circumference = 2 * Math.PI * radius;
    // A 2px gap in the surface colour separates touching segments.
    const gap = 2;

    let cumulative = 0;

    const arcs = categories.map((category, index) => {
        const value = values[index];
        const length = Math.max(0, (value / total) * circumference - gap);
        const arc = {
            category,
            value,
            index,
            length,
            offset: cumulative,
            colour: markColour(series, index, 0),
        };

        cumulative += (value / total) * circumference;

        return arc;
    }).filter((arc) => arc.value > 0);

    const drill = (category) => {
        if (category?.drill && onDrill !== false) {
            router.visit(route(category.drill.route, category.drill.params));
        }
    };

    return (
        <ChartHost className="flex flex-wrap items-center gap-6">
            <TooltipLayer tip={tip} />
            <div className="relative shrink-0" style={{ width: size, height: size }}>
                <svg width={size} height={size} className="-rotate-90" role="img" aria-label={payload.caption ?? 'Distribution'}>
                    <circle cx={size / 2} cy={size / 2} r={radius} fill="none" stroke={INK.grid} strokeWidth={stroke} />
                    {arcs.map((arc) => (
                        <circle
                            key={arc.category.key}
                            cx={size / 2}
                            cy={size / 2}
                            r={radius}
                            fill="none"
                            stroke={arc.colour}
                            strokeWidth={stroke}
                            strokeDasharray={`${arc.length} ${circumference - arc.length}`}
                            strokeDashoffset={-arc.offset}
                        />
                    ))}
                </svg>
                <div className="absolute inset-0 flex flex-col items-center justify-center">
                    <span className="text-2xl font-bold" style={{ color: INK.primary }}>
                        {formatValue(payload.total ?? total, payload.unit, payload)}
                    </span>
                    <span className="text-[10px] uppercase tracking-wide" style={{ color: INK.muted }}>
                        Total
                    </span>
                </div>
            </div>

            <ul className="min-w-[9rem] flex-1 space-y-1.5">
                {categories.map((category, index) => (
                    <li key={category.key}>
                        <button
                            type="button"
                            onClick={() => drill(category)}
                            className="flex w-full items-center gap-2 rounded px-1 py-0.5 text-left text-sm outline-none hover:bg-gray-50 focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]"
                            {...handlers(`${category.label}: ${formatValue(values[index], payload.unit, payload)}`)}
                        >
                            <span
                                className="h-2.5 w-2.5 shrink-0 rounded-full"
                                style={{ background: markColour(series, index, 0) }}
                                aria-hidden="true"
                            />
                            <span className="flex-1 truncate" style={{ color: INK.muted }}>
                                {category.label}
                            </span>
                            <span className="font-semibold tabular-nums" style={{ color: INK.primary }}>
                                {formatValue(values[index], payload.unit, payload)}
                            </span>
                        </button>
                    </li>
                ))}
            </ul>
        </ChartHost>
    );
}
