import { INK, STATUS } from './palette';

/**
 * A single bounded measure — completion, coverage, SLA attainment.
 *
 * The unfilled track is a lighter step of the fill's own ramp rather than a
 * neutral grey, so the state reads across the whole arc, and the value is
 * always printed in the middle: an arc alone is a shape, not a number.
 */
export default function GaugeChart({ payload, size = 168 }) {
    const value = Math.max(0, Math.min(100, Number(payload.value ?? 0)));
    const stroke = 14;
    const radius = (size - stroke) / 2;
    const circumference = 2 * Math.PI * radius;
    // Three-quarter arc: the open quarter is what stops it reading as a pie.
    const sweep = circumference * 0.75;
    const filled = sweep * (value / 100);

    const tone = payload.tone
        ?? (value >= 90 ? 'good' : value >= 70 ? 'warning' : value >= 40 ? 'serious' : 'critical');

    const colour = STATUS[tone] ?? STATUS.muted;

    return (
        <div className="flex flex-wrap items-center gap-5">
            <div className="relative shrink-0" style={{ width: size, height: size }}>
                <svg width={size} height={size} className="rotate-[135deg]" role="img" aria-label={`${value}%`}>
                    <circle
                        cx={size / 2}
                        cy={size / 2}
                        r={radius}
                        fill="none"
                        stroke="var(--chart-ord-1)"
                        strokeWidth={stroke}
                        strokeLinecap="round"
                        strokeDasharray={`${sweep} ${circumference - sweep}`}
                    />
                    <circle
                        cx={size / 2}
                        cy={size / 2}
                        r={radius}
                        fill="none"
                        stroke={colour}
                        strokeWidth={stroke}
                        strokeLinecap="round"
                        strokeDasharray={`${filled} ${circumference - filled}`}
                    />
                </svg>
                <div className="absolute inset-0 flex flex-col items-center justify-center">
                    <span className="text-3xl font-bold" style={{ color: INK.primary }}>
                        {value.toLocaleString(undefined, { maximumFractionDigits: 1 })}%
                    </span>
                </div>
            </div>

            {/* The caption belongs to the frame, which prints it once beneath
                the figure — repeating it here is the same sentence twice. */}
        </div>
    );
}
