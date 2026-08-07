import { Link } from '@inertiajs/react';

const COLOR_MAP = {
    primary: 'text-[var(--color-primary)]',
    success: 'text-[var(--color-success)]',
    danger: 'text-[var(--color-error)]',
    warning: 'text-[var(--color-warning)]',
    info: 'text-[var(--color-info)]',
    accent: 'text-[var(--color-accent)]',
};

export default function StatCard({ title, value, subtitle, icon, color = 'primary', href, prominent = false }) {
    const content = (
        <div className={`stat-card h-full ${prominent ? 'border-l-4 border-l-[var(--color-error)]' : ''} ${href ? 'cursor-pointer' : ''}`}>
            <div className="flex items-start justify-between">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                        {title}
                    </p>
                    <p className={`mt-2 text-3xl font-bold ${COLOR_MAP[color] ?? COLOR_MAP.primary}`}>{value}</p>
                    {subtitle && <p className="mt-1 text-xs text-[var(--color-text-secondary)]">{subtitle}</p>}
                </div>
                {icon && <div className={`${COLOR_MAP[color] ?? COLOR_MAP.primary} opacity-80`}>{icon}</div>}
            </div>
        </div>
    );

    return href ? <Link href={href}>{content}</Link> : content;
}
