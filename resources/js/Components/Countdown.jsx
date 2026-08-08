import { useEffect, useState } from 'react';

/**
 * A live countdown to a regulatory deadline (Phase 11).
 *
 * The deadline itself is always computed server-side from the obligation
 * record — this component only renders the remaining time and never
 * calculates a window of its own.
 */
function parts(seconds) {
    const abs = Math.abs(seconds);

    return {
        days: Math.floor(abs / 86400),
        hours: Math.floor((abs % 86400) / 3600),
        minutes: Math.floor((abs % 3600) / 60),
        seconds: Math.floor(abs % 60),
    };
}

export default function Countdown({ dueAt, label = 'Time remaining', compact = false }) {
    const target = dueAt ? new Date(dueAt).getTime() : null;
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        if (!target) return undefined;

        // A minute is enough resolution for a 24- or 72-hour window, and it
        // keeps a phone on 4G from repainting every second (R6).
        const timer = setInterval(() => setNow(Date.now()), 30_000);

        return () => clearInterval(timer);
    }, [target]);

    if (!target) {
        return <span className="text-sm text-gray-400">No deadline on file</span>;
    }

    const remaining = Math.floor((target - now) / 1000);
    const { days, hours, minutes } = parts(remaining);
    const overdue = remaining < 0;
    const urgent = !overdue && remaining < 6 * 3600;

    const tone = overdue
        ? 'text-[var(--color-error)]'
        : urgent
          ? 'text-[var(--color-warning)]'
          : 'text-[var(--color-success)]';

    const value = `${days > 0 ? `${days}d ` : ''}${hours}h ${minutes}m`;

    if (compact) {
        return (
            <span className={`font-mono text-xs font-semibold tabular-nums ${tone}`}>
                {overdue ? `${value} over` : value}
            </span>
        );
    }

    return (
        <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                {overdue ? 'Overdue by' : label}
            </p>
            <p className={`mt-1 font-mono text-2xl font-bold tabular-nums ${tone}`}>{value}</p>
        </div>
    );
}
