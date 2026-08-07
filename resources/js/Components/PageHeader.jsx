import { Link } from '@inertiajs/react';

export default function PageHeader({ title, subtitle, actions, breadcrumbs = [] }) {
    return (
        <div className="page-header">
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
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}
