import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { formatDateTime } from '@/utils';
import { Head, Link, router } from '@inertiajs/react';
import { History } from 'lucide-react';
import { useMemo, useState } from 'react';

/**
 * Settings → Activity Log (CR3). Badge colour by event class: green for
 * successful auth, red for failed/denied, amber for escalations and
 * overdue, slate for generic CRUD, blue for workflow transitions.
 */
const BADGE_STYLE = {
    success: 'bg-emerald-50 text-emerald-700 border-emerald-100',
    danger: 'bg-red-50 text-red-700 border-red-100',
    warning: 'bg-amber-50 text-amber-700 border-amber-100',
    info: 'bg-blue-50 text-blue-700 border-blue-100',
    neutral: 'bg-slate-50 text-slate-700 border-slate-100',
};

function EventBadge({ entry }) {
    const style = BADGE_STYLE[entry.badge] ?? BADGE_STYLE.neutral;
    return (
        <span
            title={entry.event}
            className={`inline-flex max-w-[180px] truncate rounded border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${style}`}
        >
            {entry.event_label}
        </span>
    );
}

function Initials({ name }) {
    const initials = (name || '?').split(' ').map((s) => s[0]).slice(0, 2).join('').toUpperCase();
    return (
        <div className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-[var(--color-primary)] text-[11px] font-semibold text-white">
            {initials}
        </div>
    );
}

/** Field-level before/after comparison of one entry. */
function DiffViewer({ entry }) {
    const keys = [...new Set([...Object.keys(entry.before ?? {}), ...Object.keys(entry.after ?? {})])].sort();

    if (keys.length === 0) {
        return <p className="text-sm text-[var(--color-text-secondary)]">No field changes recorded for this event.</p>;
    }

    const render = (value) =>
        value === null || value === undefined ? (
            <span className="italic text-gray-400">—</span>
        ) : (
            <span className="break-all font-mono text-xs">
                {typeof value === 'object' ? JSON.stringify(value) : String(value)}
            </span>
        );

    return (
        <div className="overflow-x-auto">
            <table className="data-table">
                <thead>
                    <tr>
                        <th>Field</th>
                        <th>Before</th>
                        <th>After</th>
                    </tr>
                </thead>
                <tbody>
                    {keys.map((key) => {
                        const before = entry.before?.[key];
                        const after = entry.after?.[key];
                        const changed = JSON.stringify(before) !== JSON.stringify(after);
                        return (
                            <tr key={key} className={changed ? 'bg-amber-50' : ''}>
                                <td className="font-mono text-xs font-semibold">{key}</td>
                                <td className={changed ? 'text-red-700 line-through decoration-red-300' : ''}>{render(before)}</td>
                                <td className={changed ? 'font-semibold text-green-800' : ''}>{render(after)}</td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

function DetailRow({ label, children, mono = false }) {
    return (
        <div className="flex gap-4">
            <dt className="w-28 flex-shrink-0 text-[var(--color-text-secondary)]">{label}</dt>
            <dd className={`min-w-0 break-all text-[var(--color-text-primary)] ${mono ? 'font-mono text-xs' : ''}`}>{children}</dd>
        </div>
    );
}

/** Right-hand drawer: the full property diff, request context and device
 *  metadata — what makes a row usable in an investigation. */
function DetailDrawer({ entry, onClose }) {
    if (!entry) return null;

    return (
        <div className="fixed inset-0 z-50 flex" onClick={onClose}>
            <div className="absolute inset-0 bg-black/40" />
            <aside
                className="relative ml-auto h-full w-full max-w-lg overflow-y-auto bg-white shadow-xl"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="flex items-start justify-between border-b border-gray-200 px-5 py-4">
                    <div className="min-w-0">
                        <EventBadge entry={entry} />
                        <h3 className="mt-1 truncate text-base font-semibold text-[var(--color-text-primary)]" title={entry.description ?? entry.event_label}>
                            {entry.description || entry.event_label}
                        </h3>
                    </div>
                    <button onClick={onClose} className="text-2xl leading-none text-gray-400 hover:text-gray-700" aria-label="Close">
                        &times;
                    </button>
                </div>

                <dl className="space-y-2 px-5 py-4 text-sm">
                    <DetailRow label="When">{formatDateTime(entry.created_at)}</DetailRow>
                    <DetailRow label="Actor">
                        {entry.user?.name ?? 'System'}
                        {entry.user?.email && <span className="block text-xs text-[var(--color-text-secondary)]">{entry.user.email}</span>}
                    </DetailRow>
                    <DetailRow label="Subject">
                        {entry.subject_type
                            ? `${entry.subject_type}${entry.subject_label ? ' — ' + entry.subject_label : entry.subject_id ? ' #' + entry.subject_id : ''}`
                            : '—'}
                    </DetailRow>
                    <DetailRow label="Request" mono>
                        {entry.method ? `${entry.method} ${entry.route_name || entry.url || ''}` : '—'}
                        {entry.status_code ? ` → ${entry.status_code}` : ''}
                    </DetailRow>
                    {entry.url && <DetailRow label="URL" mono>{entry.url}</DetailRow>}
                    <DetailRow label="IP address" mono>{entry.ip_address ?? '—'}</DetailRow>
                    <DetailRow label="Device">{entry.device_name ?? '—'}</DetailRow>
                    {entry.user_agent && (
                        <DetailRow label="User agent">
                            <span className="text-xs text-[var(--color-text-secondary)]">{entry.user_agent}</span>
                        </DetailRow>
                    )}
                    {entry.batch_id && <DetailRow label="Batch" mono>{entry.batch_id}</DetailRow>}
                </dl>

                <div className="border-t border-gray-100 px-5 py-4">
                    <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Changes</p>
                    <DiffViewer entry={entry} />
                </div>
            </aside>
        </div>
    );
}

function SkeletonRows({ rows = 8 }) {
    return [...Array(rows)].map((_, i) => (
        <tr key={i} className="animate-pulse">
            <td><div className="h-3 w-24 rounded bg-gray-100" /></td>
            <td>
                <div className="flex items-center gap-2">
                    <div className="h-8 w-8 rounded-full bg-gray-100" />
                    <div className="h-3 w-28 rounded bg-gray-100" />
                </div>
            </td>
            <td><div className="h-4 w-20 rounded bg-gray-100" /></td>
            <td><div className="h-3 w-24 rounded bg-gray-100" /></td>
            <td><div className="h-3 w-48 rounded bg-gray-100" /></td>
        </tr>
    ));
}

export default function Index({ entries, filters = {}, options = { events: [], users: [], entity_types: [] }, canExport = false }) {
    const [local, setLocal] = useState({
        search: filters.search ?? '',
        event: filters.event ?? '',
        user_id: filters.user_id ?? '',
        entity_type: filters.entity_type ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
    });
    const [selected, setSelected] = useState(null);
    const [loading, setLoading] = useState(false);

    const apply = (next = local) => {
        const params = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== '' && v !== null));
        router.get(route('settings.activity-log'), params, {
            preserveState: true,
            preserveScroll: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });
    };

    const clearAll = () => {
        setLocal({ search: '', event: '', user_id: '', entity_type: '', from: '', to: '' });
        apply({});
    };

    const hasFilters = useMemo(() => Object.values(local).some(Boolean), [local]);

    const exportParams = new URLSearchParams(
        Object.fromEntries(Object.entries(filters ?? {}).filter(([, v]) => v)),
    ).toString();

    return (
        <AuthenticatedLayout header="Activity Log">
            <Head title="Activity Log" />

            <PageHeader
                title="Activity Log"
                subtitle="Every user action on the application — logins, CRUD, workflow transitions."
                actions={
                    canExport && (
                        <div className="flex gap-2">
                            <a href={`${route('settings.activity-log.export')}?${exportParams}`} className="btn-secondary">
                                Export CSV
                            </a>
                            <a href={`${route('settings.activity-log.export-pdf')}?${exportParams}`} className="btn-secondary">
                                Export PDF
                            </a>
                        </div>
                    )
                }
            />

            {/* Filters — server-side; state lives in the query string so a
                filtered view is shareable and bookmarkable. */}
            <div className="card mb-4 p-4">
                <div className="grid grid-cols-1 gap-3 md:grid-cols-6">
                    <div className="md:col-span-2">
                        <label className="filter-bar-label">Search</label>
                        <input
                            type="text"
                            className="filter-bar-input w-full"
                            value={local.search}
                            placeholder="Description, user, subject, URL…"
                            onChange={(e) => setLocal({ ...local, search: e.target.value })}
                            onKeyDown={(e) => e.key === 'Enter' && apply()}
                        />
                    </div>
                    <div>
                        <label className="filter-bar-label">Event</label>
                        <select
                            className="filter-bar-select w-full"
                            value={local.event}
                            onChange={(e) => { const n = { ...local, event: e.target.value }; setLocal(n); apply(n); }}
                        >
                            <option value="">All events</option>
                            {options.events.map((o) => (
                                <option key={o.value} value={o.value}>{o.label}</option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="filter-bar-label">User</label>
                        <select
                            className="filter-bar-select w-full"
                            value={local.user_id}
                            onChange={(e) => { const n = { ...local, user_id: e.target.value }; setLocal(n); apply(n); }}
                        >
                            <option value="">All users</option>
                            {options.users.map((u) => (
                                <option key={u.id} value={u.id}>{u.name}</option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="filter-bar-label">Subject type</label>
                        <select
                            className="filter-bar-select w-full"
                            value={local.entity_type}
                            onChange={(e) => { const n = { ...local, entity_type: e.target.value }; setLocal(n); apply(n); }}
                        >
                            <option value="">All types</option>
                            {options.entity_types.map((t) => (
                                <option key={t.value} value={t.value}>{t.label}</option>
                            ))}
                        </select>
                    </div>
                    <div className="grid grid-cols-2 gap-2">
                        <div>
                            <label className="filter-bar-label">From</label>
                            <input type="date" className="filter-bar-input w-full" value={local.from} onChange={(e) => setLocal({ ...local, from: e.target.value })} />
                        </div>
                        <div>
                            <label className="filter-bar-label">To</label>
                            <input type="date" className="filter-bar-input w-full" value={local.to} onChange={(e) => setLocal({ ...local, to: e.target.value })} />
                        </div>
                    </div>
                </div>
                <div className="mt-3 flex justify-end gap-2">
                    {hasFilters && <SecondaryButton onClick={clearAll}>Clear</SecondaryButton>}
                    <PrimaryButton onClick={() => apply()}>Apply</PrimaryButton>
                </div>
            </div>

            <div className="card overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="data-table min-w-full">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>User</th>
                                <th>Event</th>
                                <th>Subject</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <SkeletonRows />
                            ) : entries.data.length === 0 ? (
                                <tr>
                                    <td colSpan={5}>
                                        <EmptyState
                                            icon={History}
                                            title="No activity matches"
                                            description={hasFilters ? 'Adjust the filters above.' : 'No activity recorded yet.'}
                                        />
                                    </td>
                                </tr>
                            ) : (
                                entries.data.map((entry) => (
                                    <tr
                                        key={entry.id}
                                        className="cursor-pointer hover:bg-gray-50"
                                        onClick={() => setSelected(entry)}
                                    >
                                        <td className="whitespace-nowrap text-xs text-[var(--color-text-secondary)]">
                                            {formatDateTime(entry.created_at)}
                                        </td>
                                        <td className="whitespace-nowrap">
                                            <div className="flex items-center gap-2">
                                                <Initials name={entry.user?.name} />
                                                <div className="min-w-0">
                                                    <div className="truncate text-sm text-[var(--color-text-primary)]" title={entry.user?.name ?? 'System'}>
                                                        {entry.user?.name ?? 'System'}
                                                    </div>
                                                    {entry.user?.email && (
                                                        <div className="max-w-[180px] truncate text-xs text-[var(--color-text-secondary)]" title={entry.user.email}>
                                                            {entry.user.email}
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        </td>
                                        <td className="whitespace-nowrap"><EventBadge entry={entry} /></td>
                                        <td>
                                            {entry.subject_type ? (
                                                <div className="min-w-0">
                                                    <div className="text-xs text-[var(--color-text-secondary)]">{entry.subject_type}</div>
                                                    <div className="max-w-[200px] truncate text-sm" title={entry.subject_label ?? undefined}>
                                                        {entry.subject_label || (entry.subject_id ? `#${entry.subject_id}` : '—')}
                                                    </div>
                                                </div>
                                            ) : (
                                                <span className="text-gray-400">—</span>
                                            )}
                                        </td>
                                        <td>
                                            <div className="max-w-sm truncate text-sm text-[var(--color-text-primary)]" title={entry.description ?? undefined}>
                                                {entry.description || '—'}
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {(entries.prev_page_url || entries.next_page_url) && (
                    <div className="flex items-center justify-between border-t border-gray-100 px-4 py-3">
                        <span className="text-sm text-[var(--color-text-secondary)]">Showing {entries.data.length} entries</span>
                        <div className="flex gap-2">
                            {entries.prev_page_url && (
                                <Link href={entries.prev_page_url} preserveState preserveScroll className="btn-secondary">Newer</Link>
                            )}
                            {entries.next_page_url && (
                                <Link href={entries.next_page_url} preserveState preserveScroll className="btn-secondary">Older</Link>
                            )}
                        </div>
                    </div>
                )}
            </div>

            <DetailDrawer entry={selected} onClose={() => setSelected(null)} />
        </AuthenticatedLayout>
    );
}
