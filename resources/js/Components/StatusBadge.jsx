import { TONES, toneFor } from '@/utils/tones';

/**
 * Status pill, ThirdLine style: a tinted rounded-full chip with the word in
 * the matching shade. The tone comes from the shared semantic mapping — pass
 * any lifecycle, rating, severity or classification word the app uses.
 */
export default function StatusBadge({ status, className = '' }) {
    if (!status) return <span className="text-gray-400">—</span>;

    const palette = TONES[toneFor(status)];

    return <span className={`badge ${palette.badge} ${className}`}>{status}</span>;
}
