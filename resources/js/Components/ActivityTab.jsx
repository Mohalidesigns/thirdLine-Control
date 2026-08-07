import EmptyState from '@/Components/EmptyState';
import { formatDateTime } from '@/utils';
import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * Inline audit history for one record (Phase 7.5). Lazy-loads on mount
 * from the permission-scoped audit-trail endpoint.
 * alias: 'control' | 'exception' | 'test-instance'
 */
export default function ActivityTab({ alias, entityId }) {
    const { features = [] } = usePage().props;
    const [entries, setEntries] = useState(null);
    const [error, setError] = useState(false);

    const enabled = features.includes('audit-log-ui');

    useEffect(() => {
        if (!enabled) return;
        let cancelled = false;
        fetch(route('audit-trail.entity', [alias, entityId]), { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : Promise.reject()))
            .then((json) => !cancelled && setEntries(json.entries))
            .catch(() => !cancelled && setError(true));
        return () => {
            cancelled = true;
        };
    }, [alias, entityId, enabled]);

    if (!enabled) return null;

    if (error) {
        return <p className="text-sm text-[var(--color-text-secondary)]">Activity history is unavailable.</p>;
    }

    if (entries === null) {
        return <p className="text-sm text-[var(--color-text-secondary)]">Loading activity…</p>;
    }

    if (entries.length === 0) {
        return <EmptyState title="No activity yet" description="Actions on this record will appear here." />;
    }

    return (
        <ol className="relative ms-3 border-s border-gray-200">
            {entries.map((entry) => (
                <li key={entry.id} className="mb-5 ms-5">
                    <span className="absolute -start-[5px] mt-1.5 h-2.5 w-2.5 rounded-full bg-[var(--color-accent)]" />
                    <p className="text-sm font-medium text-[var(--color-text-primary)]">
                        <span className="font-mono text-xs">{entry.action}</span>
                        {' — '}
                        {entry.user?.name ?? 'System'}
                    </p>
                    <p className="text-xs text-[var(--color-text-secondary)]">{formatDateTime(entry.created_at)}</p>
                    {entry.after && Object.keys(entry.after).length > 0 && (
                        <p className="mt-1 break-all font-mono text-[11px] text-[var(--color-text-secondary)]">
                            {Object.entries(entry.after)
                                .slice(0, 4)
                                .map(([key, value]) => `${key}: ${typeof value === 'object' ? JSON.stringify(value) : value}`)
                                .join(' · ')}
                        </p>
                    )}
                </li>
            ))}
        </ol>
    );
}
