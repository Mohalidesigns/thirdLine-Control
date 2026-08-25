import { TONES } from '@/utils/tones';
import { CalendarClock, CalendarDays, CalendarRange, Eye, Zap } from 'lucide-react';

/**
 * CR-03 §E.2: "Frequency of Activity" made visible wherever the client
 * expects to see it.
 *
 * Colour follows the CYCLE, never the label — a tenant that renames
 * "Semi-annual" to "Half yearly" gets the same chip, which is the same
 * rule the backend applies (behaviour keys on cycle, not on wording).
 * The title attribute carries the bank's own words verbatim, so an
 * officer hovering a Quarterly chip still sees the "Quaterly" the
 * workbook said.
 */
const CYCLE_TONES = {
    daily: 'red',
    weekly: 'amber',
    monthly: 'blue',
    quarterly: 'violet',
    semiannual: 'violet',
    annual: 'slate',
    continuous: 'emerald',
    event: 'amber',
};

const CYCLE_ICONS = {
    daily: CalendarDays,
    weekly: CalendarDays,
    monthly: CalendarRange,
    quarterly: CalendarRange,
    semiannual: CalendarRange,
    annual: CalendarClock,
    continuous: Eye,
    event: Zap,
};

export default function FrequencyBadge({ frequency, raw, isOverride = false, className = '' }) {
    if (!frequency) return <span className="text-gray-400">—</span>;

    const palette = TONES[CYCLE_TONES[frequency.cycle] ?? 'slate'];
    const Icon = CYCLE_ICONS[frequency.cycle] ?? CalendarDays;

    // Show the client's own wording where it differs from ours — this is
    // the column they signed the workbook off on.
    const title = raw && raw !== frequency.label
        ? `Written in the workbook as “${raw}”`
        : frequency.label;

    return (
        <span className={`badge gap-1 ${palette.badge} ${className}`} title={title}>
            <Icon className="h-3 w-3 shrink-0" strokeWidth={2} aria-hidden="true" />
            {frequency.label}
            {isOverride && (
                <span
                    className="ms-0.5 font-bold"
                    title="This line runs to its own rhythm, not the function's."
                    aria-label="Line-level frequency override"
                >
                    *
                </span>
            )}
        </span>
    );
}
