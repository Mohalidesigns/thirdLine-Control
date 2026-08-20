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
    // Calm tones keep the number dark; alarm tones stay loud.
    const valueClass = ['red', 'amber'].includes(resolvedTone) && color !== undefined
        ? palette.value
        : 'text-[var(--color-text-primary)]';

    // Every card carries a tile — a call site that names no icon falls
    // back to the tone's default rather than rendering bare. It floats in
    // the top-right corner so the title and value keep the full card width
    // even in dense six-up grids; only the title's first lines dodge it.
    const resolvedIcon = icon ?? toneIcon(resolvedTone);
    const tile = (
        <div className={`absolute end-4 top-4 flex h-11 w-11 items-center justify-center rounded-xl ${palette.tile}`}>
            {isValidElement(resolvedIcon)
                ? <span className={palette.icon}>{resolvedIcon}</span>
                : (() => { const Icon = resolvedIcon; return <Icon className={`h-5 w-5 ${palette.icon}`} strokeWidth={1.8} aria-hidden="true" />; })()}
        </div>
    );

    const content = (
        <div className={`stat-card relative h-full ${prominent ? 'border-l-4 border-l-[var(--color-error)]' : ''} ${href ? 'cursor-pointer' : ''}`}>
            {tile}
            <p className="min-h-11 pe-12 text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                {title}
            </p>
            <p className={`mt-2 text-3xl font-bold ${valueClass}`}>{value}</p>
            {subtitle && <p className="mt-1 text-xs text-[var(--color-text-secondary)]">{subtitle}</p>}
        </div>
    );

    return href ? <Link href={href}>{content}</Link> : content;
}
