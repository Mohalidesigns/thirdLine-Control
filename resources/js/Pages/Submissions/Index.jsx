import DataTable from '@/Components/DataTable';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import ProgressBar from '@/Components/ProgressBar';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Plus } from 'lucide-react';

export default function Index({
    packs,
    filters = {},
    statuses = [],
    catalogue = [],
    entities = [],
    instances = [],
    threshold = 95,
    can = {},
}) {
    const [creating, setCreating] = useState(false);

    const form = useForm({
        pack_type: catalogue[0]?.pack_type ?? '',
        period_label: catalogue[0]?.default_period ?? '',
        entity_id: '',
        obligation_instance_id: '',
    });

    const selected = catalogue.find((entry) => entry.pack_type === form.data.pack_type);

    const filter = (changes) => router.get(route('submissions.index'), { ...filters, ...changes }, {
        preserveState: true,
        replace: true,
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('submissions.store'), { onSuccess: () => setCreating(false) });
    };

    return (
        <AuthenticatedLayout header="Regulatory submissions">
            <Head title="Regulatory submissions" />

            <PageHeader
                title="Regulatory submissions"
                subtitle={`Generated returns, their completeness and where each sits in the approval chain. The filing floor is ${threshold}%.`}
                actions={can.generate && (
                    <button type="button" onClick={() => setCreating(true)} className="btn-primary">
                        <Plus className="h-4 w-4" strokeWidth={2} /> Generate a return
                    </button>
                )}
            />

            <div className="filter-bar">
                <div className="filter-bar-inner">
                    <div className="filter-bar-group">
                        <label className="filter-bar-label" htmlFor="pack-search">Search</label>
                        <input
                            id="pack-search"
                            className="filter-bar-input"
                            defaultValue={filters.search ?? ''}
                            onBlur={(event) => filter({ search: event.target.value })}
                            placeholder="Reference or period"
                        />
                    </div>
                    <div className="filter-bar-group">
                        <label className="filter-bar-label" htmlFor="pack-status">Status</label>
                        <select
                            id="pack-status"
                            className="filter-bar-select"
                            value={filters.status ?? ''}
                            onChange={(event) => filter({ status: event.target.value })}
                        >
                            <option value="">All statuses</option>
                            {statuses.map((status) => (
                                <option key={status} value={status}>{status}</option>
                            ))}
                        </select>
                    </div>
                    <div className="filter-bar-group">
                        <label className="filter-bar-label" htmlFor="pack-type">Return</label>
                        <select
                            id="pack-type"
                            className="filter-bar-select"
                            value={filters.pack_type ?? ''}
                            onChange={(event) => filter({ pack_type: event.target.value })}
                        >
                            <option value="">All returns</option>
                            {catalogue.map((entry) => (
                                <option key={entry.pack_type} value={entry.pack_type}>{entry.pack_type}</option>
                            ))}
                        </select>
                    </div>
                </div>
            </div>

            <div className="card">
                <DataTable
                    columns={[
                        {
                            field: 'pack_ref',
                            label: 'Reference',
                            render: (pack) => <span className="font-mono text-xs font-semibold">{pack.pack_ref}</span>,
                        },
                        { field: 'pack_type', label: 'Return' },
                        { field: 'period_label', label: 'Period' },
                        { field: 'status', label: 'Status', render: (pack) => <StatusBadge status={pack.status} /> },
                        {
                            field: 'completeness_score',
                            label: 'Completeness',
                            render: (pack) => (
                                <div className="w-32">
                                    <div className="mb-1 flex justify-between text-xs">
                                        <span className="tabular-nums">{pack.completeness_score}%</span>
                                        {pack.completeness_score < threshold && (
                                            <span className="text-[var(--color-warning)]">below floor</span>
                                        )}
                                    </div>
                                    <ProgressBar
                                        value={pack.completeness_score}
                                        color={pack.completeness_score >= threshold ? '#0CA30C' : '#FAB219'}
                                    />
                                </div>
                            ),
                        },
                        {
                            field: 'issues',
                            label: 'Issues',
                            render: (pack) => {
                                const blocking = (pack.validation_errors ?? []).filter((error) => error.blocking !== false).length;
                                const unverified = (pack.unverified_items ?? []).length;

                                return (
                                    <span className="text-xs">
                                        {blocking > 0 && <span className="text-[var(--color-error)]">{blocking} blocking</span>}
                                        {blocking > 0 && unverified > 0 && ' · '}
                                        {unverified > 0 && <span className="text-[var(--color-warning)]">{unverified} unverified</span>}
                                        {blocking === 0 && unverified === 0 && <span className="text-gray-400">None</span>}
                                    </span>
                                );
                            },
                        },
                        { field: 'generator', label: 'Generated by', render: (pack) => pack.generator?.name ?? '—' },
                        { field: 'generated_at', label: 'Generated', render: (pack) => formatDate(pack.generated_at) },
                    ]}
                    data={packs.data}
                    onRowClick={(pack) => router.visit(route('submissions.show', pack.id))}
                    emptyMessage="No submission packs generated yet."
                />
                <Pagination paginator={packs} />
            </div>

            <Modal show={creating} onClose={() => setCreating(false)} title="Generate a regulatory return">
                <form onSubmit={submit} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold text-[var(--color-text-primary)]">Generate a return</h2>

                    <label className="block">
                        <span className="form-label">Return</span>
                        <select
                            className="form-select"
                            value={form.data.pack_type}
                            onChange={(event) => {
                                const entry = catalogue.find((one) => one.pack_type === event.target.value);
                                form.setData((data) => ({
                                    ...data,
                                    pack_type: event.target.value,
                                    period_label: entry?.default_period ?? data.period_label,
                                }));
                            }}
                        >
                            {catalogue.map((entry) => (
                                <option key={entry.pack_type} value={entry.pack_type} disabled={!entry.installed}>
                                    {entry.label}{entry.installed ? '' : ' — content pack not installed'}
                                </option>
                            ))}
                        </select>
                        {selected && (
                            <p className="mt-1 text-xs text-[var(--color-text-secondary)]">{selected.description}</p>
                        )}
                    </label>

                    <label className="block">
                        <span className="form-label">Period</span>
                        <input
                            className="form-input"
                            value={form.data.period_label}
                            onChange={(event) => form.setData('period_label', event.target.value)}
                            placeholder="FY 2025"
                        />
                    </label>

                    <label className="block">
                        <span className="form-label">Filing entity</span>
                        <select
                            className="form-select"
                            value={form.data.entity_id}
                            onChange={(event) => form.setData('entity_id', event.target.value)}
                        >
                            <option value="">First declared legal entity</option>
                            {entities.map((entity) => (
                                <option key={entity.id} value={entity.id}>{entity.name}</option>
                            ))}
                        </select>
                    </label>

                    <label className="block">
                        <span className="form-label">Discharges obligation instance</span>
                        <select
                            className="form-select"
                            value={form.data.obligation_instance_id}
                            onChange={(event) => form.setData('obligation_instance_id', event.target.value)}
                        >
                            <option value="">Not linked</option>
                            {instances.map((instance) => (
                                <option key={instance.id} value={instance.id}>
                                    {instance.obligation?.title} — {instance.period_label}
                                </option>
                            ))}
                        </select>
                        <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                            Filing the pack marks the linked instance as submitted, so the calendar stops chasing it.
                        </p>
                    </label>

                    <div className="flex justify-end gap-2">
                        <button type="button" className="btn-secondary" onClick={() => setCreating(false)}>
                            Cancel
                        </button>
                        <button type="submit" className="btn-primary" disabled={form.processing}>
                            Generate
                        </button>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
