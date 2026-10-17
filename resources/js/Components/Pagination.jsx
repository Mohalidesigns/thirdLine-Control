import { Link } from '@inertiajs/react';

export default function Pagination({ paginator }) {
    if (!paginator || !paginator.links || paginator.links.length <= 3) return null;

    return (
        <div className="flex flex-col items-center justify-between gap-3 border-t border-gray-100 px-4 py-3 sm:flex-row">
            <p className="text-sm text-gray-500">
                Showing {paginator.from ?? 0}–{paginator.to ?? 0} of {paginator.total}
            </p>
            <nav className="flex flex-wrap items-center gap-1">
                {paginator.links.map((link, i) =>
                    link.url ? (
                        <Link
                            key={i}
                            href={link.url}
                            preserveScroll
                            className={`rounded-md px-3 py-1.5 text-sm transition-colors ${
                                link.active
                                    ? 'bg-[var(--color-primary)] font-semibold text-white'
                                    : 'text-gray-600 hover:bg-gray-100'
                            }`}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ) : (
                        <span
                            key={i}
                            className="cursor-not-allowed rounded-md px-3 py-1.5 text-sm text-gray-300"
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ),
                )}
            </nav>
        </div>
    );
}
