import { Link } from '@inertiajs/react';
import { INK, STATUS, formatValue } from './palette';

/**
 * When the story is one number, the number is the chart.
 *
 * Contract: label in sentence case, the value in the same sans as everything
 * else at proportional figures (tabular-nums makes 121 look loose at display
 * size), an optional caption, and an optional status tone that always ships
 * with a text label beside it rather than as bare colour.
 */
export default function StatTile({ payload, title, subtitle, hero = false }) {
    const tone = payload.tone;
    const value = formatValue(payload.value, payload.unit, payload);
    const drill = payload.drill;

    const body = (
        <div className="stat-card flex h-full flex-col justify-between">
            <div>
                <p className="text-sm text-[var(--color-text-secondary)]">{title}</p>
                {subtitle && <p className="text-xs text-[var(--color-text-secondary)]">{subtitle}</p>}
            </div>

            <p
                className={`mt-2 font-bold ${hero ? 'text-5xl' : 'text-3xl'}`}
                style={{ color: tone ? STATUS[tone] ?? INK.primary : INK.primary }}
            >
                {value}
            </p>

            {payload.caption && (
                <p className="mt-2 text-xs" style={{ color: INK.muted }}>
                    {tone && (
                        <span
                            className="me-1.5 inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-semibold text-white"
                            style={{ background: STATUS[tone] ?? STATUS.muted }}
                        >
                            {TONE_LABELS[tone] ?? tone}
                        </span>
                    )}
                    {payload.caption}
                </p>
            )}

            {payload.breakdown?.length > 1 && (
                <ul className="mt-2 space-y-0.5 text-xs" style={{ color: INK.muted }}>
                    {payload.breakdown.slice(1).map((entry) => (
                        <li key={entry.currency} className="tabular-nums">
                            {entry.formatted}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );

    if (!drill) {
        return body;
    }

    return (
        <Link href={route(drill.route, drill.params)} className="block h-full">
            {body}
        </Link>
    );
}

/**
 * A status colour never carries meaning alone — these are the words that go
 * with it.
 */
const TONE_LABELS = {
    good: 'On track',
    warning: 'Watch',
    serious: 'Attention',
    critical: 'Action',
    muted: 'No data',
};
