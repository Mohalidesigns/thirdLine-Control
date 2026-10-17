import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';

/**
 * Page header, drawn as ThirdLine draws it: a white band under the top bar
 * carrying breadcrumbs, the title, a one-line subtitle and the page actions.
 *
 * `band` bleeds the header edge to edge across the main column; pass
 * `band={false}` when the header sits inside a narrower centred container.
 * `icon` and `tone` are accepted for older call sites but no longer drawn —
 * ThirdLine headers carry no icon tile.
 */
export default function PageHeader({ title, subtitle, actions, breadcrumbs = [], band = true }) {
    return (
        <div className={band ? 'page-header-band' : 'mb-6'}>
            <div className="page-header">
                <div className="min-w-0">
                    {breadcrumbs.length > 0 && (
                        <nav aria-label="Breadcrumb" className="mb-1 flex flex-wrap items-center gap-1 text-xs text-gray-500">
                            {breadcrumbs.map((crumb, i) => (
                                <span key={i} className="flex items-center gap-1">
                                    {i > 0 && <ChevronRight className="h-3.5 w-3.5 text-gray-400" aria-hidden="true" />}
                                    {crumb.href ? (
                                        <Link href={crumb.href} className="hover:text-[var(--color-primary)]">
                                            {crumb.label}
                                        </Link>
                                    ) : (
                                        <span className="text-gray-700">{crumb.label}</span>
                                    )}
                                </span>
                            ))}
                        </nav>
                    )}
                    <h2 className="page-title">{title}</h2>
                    {subtitle && <p className="mt-1 text-sm text-gray-500">{subtitle}</p>}
                </div>
                {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
            </div>
        </div>
    );
}
