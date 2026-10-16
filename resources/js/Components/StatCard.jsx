import { TONES, toneIcon } from '@/utils/tones';
import { Link } from '@inertiajs/react';
import { isValidElement } from 'react';

// Legacy colour names still used across the app, mapped onto the shared
// semantic tones so every card draws from one palette.
const LEGACY_TONES = {
    primary: 'blue',
    success: 'emerald',
    danger: 'red',
    warning: 'amber',
    info: 'blue',
    accent: 'amber',
};

/**
 * KPI / stat card with the coloured icon tile in the top-right corner.
 *
 * `icon` accepts a lucide component (preferred) or a pre-rendered element;
 * `tone` is one of the semantic tones (emerald | blue | amber | red |
 * violet | slate) and should follow the value's state, not decoration.
 * The legacy `color` prop keeps older call sites working.
 */
export default function StatCard({ title, value, subtitle, icon, tone, color, href, prominent = false }) {
    const resolvedTone = TONES[tone] ? tone : (LEGACY_TONES[color] ?? 'blue');
    const palette = TONES[resolvedTone];
    // The number stays dark, as on ThirdLine — the tile carries the tone.
    const valueClass = 'text-[var(--color-text-primary)]';

    // Every card carries a tile — a call site that names no icon falls
    // back to the tone's default rather than rendering bare.
    const resolvedIcon = icon ?? toneIcon(resolvedTone);
    const tile = (
        <div className={`flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg ${palette.tile} ${palette.icon}`}>
            {isValidElement(resolvedIcon)
                ? resolvedIcon
                : (() => { const Icon = resolvedIcon; return <Icon className="h-5 w-5" aria-hidden="true" />; })()}
        </div>
    );

    // ThirdLine's stat card: label, value and note on the left, the tinted
    // icon tile top-right.
    const content = (
        <div className={`stat-card h-full ${prominent ? 'border-l-4 border-l-[var(--color-error)]' : ''} ${href ? 'cursor-pointer' : ''}`}>
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-sm font-medium text-gray-500">{title}</p>
                    <p className={`mt-1 text-2xl font-bold ${valueClass}`}>{value}</p>
                    {subtitle && <p className="mt-1 text-xs text-gray-500">{subtitle}</p>}
                </div>
                {tile}
            </div>
        </div>
    );

    return href ? <Link href={href}>{content}</Link> : content;
}
