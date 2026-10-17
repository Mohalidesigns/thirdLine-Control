import { INK, STATUS } from './palette';

/**
 * A single bounded measure — completion, coverage, SLA attainment.
 *
 * Drawn as ThirdLine's progress ring: a full circle on a slate track with a
 * round-capped fill, and the value always printed in the middle — a ring
 * alone is a shape, not a number.
 */
export default function GaugeChart({ payload, size = 120 }) {
    const value = Math.max(0, Math.min(100, Number(payload.value ?? 0)));
    const stroke = Math.max(6, Math.round(size / 13));
    const radius = (size - stroke) / 2;
    const circumference = 2 * Math.PI * radius;
    const offset = circumference * (1 - value / 100);

    const tone = payload.tone
        ?? (value >= 90 ? 'good' : value >= 70 ? 'warning' : value >= 40 ? 'serious' : 'critical');

    const colour = STATUS[tone] ?? STATUS.muted;

    return (
        <div className="flex flex-wrap items-center gap-6">
            <div className="relative inline-flex shrink-0 items-center justify-center" style={{ width: size, height: size }}>
                <svg width={size} height={size} className="-rotate-90" role="img" aria-label={`${value}%`}>
                    <circle
                        cx={size / 2}
                        cy={size / 2}
                        r={radius}
                        fill="none"
                        stroke="var(--chart-track)"
                        strokeWidth={stroke}
                    />
                    <circle
                        className="transition-all duration-1000"
                        cx={size / 2}
                        cy={size / 2}
                        r={radius}
                        fill="none"
                        stroke={colour}
                        strokeWidth={stroke}
                        strokeLinecap="round"
                        strokeDasharray={circumference}
                        strokeDashoffset={offset}
                    />
                </svg>
                <span className="absolute text-lg font-bold" style={{ color: INK.primary }}>
                    {value.toLocaleString(undefined, { maximumFractionDigits: 1 })}%
                </span>
            </div>

            {/* The caption belongs to the frame, which prints it once beneath
                the figure — repeating it here is the same sentence twice. */}
        </div>
    );
}
