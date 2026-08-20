import { TONES, iconFor, toneFor } from '@/utils/tones';

/**
 * Status pill: tinted background, matching text and a small leading icon,
 * all derived from the shared semantic tone mapping — pass any lifecycle,
 * rating, severity or classification word the app uses.
 */
export default function StatusBadge({ status, className = '' }) {
    if (!status) return <span className="text-gray-400">—</span>;

    const palette = TONES[toneFor(status)];
    const Icon = iconFor(status);

    return (
        <span className={`badge gap-1 ${palette.badge} ${className}`}>
            <Icon className="h-3 w-3 shrink-0" strokeWidth={2} aria-hidden="true" />
            {status}
        </span>
    );
}
