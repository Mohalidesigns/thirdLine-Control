import { Link, usePage } from '@inertiajs/react';
import { Gavel } from 'lucide-react';

/**
 * CR-04 §D.2 — the raise-from entry point.
 *
 * Every module that can produce an investigation gets the same button, and
 * it carries the origin with it: the new investigation records a hard
 * `origin_type`/`origin_id` and a graph edge in one transaction, and the
 * create form arrives pre-filled from the record it came from.
 *
 * Renders nothing at all when the module is off or the viewer cannot open
 * an investigation — a button that leads to a 403 is worse than no button.
 */
export default function RaiseInvestigationButton({
    originType,
    originId,
    label = 'Open investigation',
    className = 'btn-secondary',
}) {
    const { auth, features = [] } = usePage().props;

    if (!features.includes('investigations')) return null;
    if (!auth?.permissions?.includes('create investigations')) return null;

    return (
        <Link
            href={route('investigations.create', { origin_type: originType, origin_id: originId })}
            className={className}
        >
            <Gavel className="me-1.5 h-4 w-4" aria-hidden="true" />
            {label}
        </Link>
    );
}
