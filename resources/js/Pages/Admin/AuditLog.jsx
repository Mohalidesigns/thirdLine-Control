import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import SecondaryButton from '@/Components/SecondaryButton';
import { formatDateTime } from '@/utils';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

function shortType(entityType) {
    return entityType?.split('\\').pop() ?? entityType;
}

/** Field-level before/after comparison of one audit entry. */
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

export default function AuditLog({ entries, filters, entityTypes, users, canExport }) {
    const [inspecting, setInspecting] = useState(null);
    const [local, setLocal] = useState(filters);

    const apply = () => {
        const params = Object.fromEntries(Object.entries(local).filter(([, v]) => v !== '' && v !== null && v !== undefined));
        router.get(route('admin.audit-log'), params, { preserveState: true, replace: true });
    };

    const exportUrl = `${route('admin.audit-log.export')}?${new URLSearchParams(
        Object.fromEntries(Object.entries(filters ?? {}).filter(([, v]) => v)),
    )}`;

    return (
        <AuthenticatedLayout header="Audit Log">
            <Head title="Audit Log" />

            <PageHeader
                title="Audit log"
                subtitle="Immutable, append-only record of every action in the system. Read-only by design."
                actions={
                    canExport && (
                        <a href={exportUrl} className="btn-secondary">
                            Export CSV
                        </a>
                    )
                }
            />

            <div className="filter-bar">
                <div className="filter-bar-inner">
                    <div className="filter-bar-group">
                        <label className="filter-bar-label">From</label>
                        <input type="date" className="filter-bar-input" value={local.from ?? ''} onChange={(e) => setLocal({ ...local, from: e.target.value })} />
                    </div>
                    <div className="filter-bar-group">
                        <label className="filter-bar-label">To</label>
                        <input type="date" className="filter-bar-input" value={local.to ?? ''} onChange={(e) => setLocal({ ...local, to: e.target.value })} />
                    </div>
                    <div className="filter-bar-group">
                        <label className="filter-bar-label">User</label>
                        <select className="filter-bar-select" value={local.user_id ?? ''} onChange={(e) => setLocal({ ...local, user_id: e.target.value })}>
                            <option value="">All</option>
                            {users.map((user) => (
                                <option key={user.id} value={user.id}>{user.name}</option>
                            ))}
                        </select>
                    </div>
                    <div className="filter-bar-group">
                        <label className="filter-bar-label">Record type</label>
                        <select className="filter-bar-select" value={local.entity_type ?? ''} onChange={(e) => setLocal({ ...local, entity_type: e.target.value })}>
                            <option value="">All</option>
                            {entityTypes.map((type) => (
                                <option key={type} value={type}>{shortType(type)}</option>
                            ))}
                        </select>
                    </div>
                    <div className="filter-bar-group">
                        <label className="filter-bar-label">Record ID</label>
                        <input type="number" className="filter-bar-input w-24" value={local.entity_id ?? ''} onChange={(e) => setLocal({ ...local, entity_id: e.target.value })} />
                    </div>
                    <div className="filter-bar-group">
                        <label className="filter-bar-label">Action</label>
                        <input type="text" className="filter-bar-input w-32" placeholder="approved…" value={local.action ?? ''} onChange={(e) => setLocal({ ...local, action: e.target.value })} onKeyDown={(e) => e.key === 'Enter' && apply()} />
                    </div>
                    <div className="filter-bar-group">
                        <label className="filter-bar-label">IP</label>
                        <input type="text" className="filter-bar-input w-32" value={local.ip ?? ''} onChange={(e) => setLocal({ ...local, ip: e.target.value })} onKeyDown={(e) => e.key === 'Enter' && apply()} />
                    </div>
                    <SecondaryButton className="mb-0.5" onClick={apply}>Filter</SecondaryButton>
                </div>
            </div>

            {entries.data.length === 0 ? (
                <EmptyState title="No audit entries match" description="Adjust the filters above." />
            ) : (
                <div className="card overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Record</th>
                                <th>IP</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {entries.data.map((entry) => (
                                <tr key={entry.id}>
                                    <td className="whitespace-nowrap">{formatDateTime(entry.created_at)}</td>
                                    <td>{entry.user?.name ?? 'System'}</td>
                                    <td><span className="badge badge-status-draft font-mono">{entry.action}</span></td>
                                    <td className="whitespace-nowrap font-mono text-xs">
                                        {shortType(entry.entity_type)} #{entry.entity_id}
                                    </td>
                                    <td className="font-mono text-xs">{entry.ip_address}</td>
                                    <td>
                                        <button type="button" className="text-sm font-medium text-[var(--color-primary)] hover:underline" onClick={() => setInspecting(entry)}>
                                            Diff
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <div className="mt-4 flex items-center justify-between">
                <span className="text-sm text-[var(--color-text-secondary)]">Showing {entries.data.length} entries</span>
                <div className="flex gap-2">
                    {entries.prev_page_url && (
                        <Link href={entries.prev_page_url} preserveState className="btn-secondary">Newer</Link>
                    )}
                    {entries.next_page_url && (
                        <Link href={entries.next_page_url} preserveState className="btn-secondary">Older</Link>
                    )}
                </div>
            </div>

            <Modal show={inspecting !== null} onClose={() => setInspecting(null)} maxWidth="2xl">
                <div className="p-6">
                    <h2 className="mb-1 text-lg font-semibold text-[var(--color-text-primary)]">
                        {inspecting && `${shortType(inspecting.entity_type)} #${inspecting.entity_id} — ${inspecting.action}`}
                    </h2>
                    <p className="mb-4 text-xs text-[var(--color-text-secondary)]">
                        {inspecting && `${inspecting.user?.name ?? 'System'} · ${formatDateTime(inspecting.created_at)} · ${inspecting.ip_address ?? ''}`}
                    </p>
                    {inspecting && <DiffViewer entry={inspecting} />}
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
