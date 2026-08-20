import { TONES } from '@/utils/tones';
import { Link } from '@inertiajs/react';

/**
 * Page header. `icon` (a lucide component) renders in a tinted tile next
 * to the title; `tone` picks its semantic colour (default blue).
 */
export default function PageHeader({ title, subtitle, icon: Icon = null, tone = 'blue', actions, breadcrumbs = [] }) {
    const palette = TONES[tone] ?? TONES.blue;

    return (
        <div className="page-header">
            <div className="flex items-start gap-3">
                {Icon && (
                    <div className={`mt-0.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ${palette.tile}`}>
                        <Icon className={`h-5 w-5 ${palette.icon}`} strokeWidth={1.8} aria-hidden="true" />
                    </div>
                )}
                <div>
                    {breadcrumbs.length > 0 && (
                        <nav className="mb-1 flex items-center gap-1.5 text-sm text-[var(--color-text-secondary)]">
                            {breadcrumbs.map((crumb, i) => (
                                <span key={i} className="flex items-center gap-1.5">
                                    {i > 0 && <span>/</span>}
                                    {crumb.href ? (
                                        <Link href={crumb.href} className="hover:text-[var(--color-primary)] hover:underline">
                                            {crumb.label}
                                        </Link>
                                    ) : (
                                        <span>{crumb.label}</span>
                                    )}
                                </span>
                            ))}
                        </nav>
                    )}
                    <h1 className="page-title">{title}</h1>
                    {subtitle && <p className="mt-1 text-sm text-[var(--color-text-secondary)]">{subtitle}</p>}
                </div>
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}
