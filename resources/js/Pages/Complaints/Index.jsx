import Countdown from '@/Components/Countdown';
import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDateTime } from '@/utils';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({
    complaints,
    filters = {},
    statuses = [],
    channels = [],
    categories = [],
    handlers = [],
    stats = {},
    exposure = {},
}) {
    const { auth } = usePage().props;
    const canCreate = auth?.permissions?.includes('create complaints');
    const [logging, setLogging] = useState(false);

    const form = useForm({
        channel: 'email',
        subject: '',
        description: '',
        customer_name: '',
        customer_reference: '',
        customer_contact: { email: '', phone: '' },
        category_id: '',
        product: '',
        assigned_to: '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.post(route('complaints.store'), { onSuccess: () => setLogging(false) });
    };

    const columns = [
        {
            field: 'tracking_id',
            label: 'Tracking',
            render: (row) => (
                <div>
                    <p className="font-mono text-xs font-semibold text-[var(--color-primary)]">{row.tracking_id}</p>
                    <p className="font-mono text-[10px] text-gray-400">{row.complaint_ref}</p>
                </div>
            ),
        },
        {
            field: 'subject',
            label: 'Complaint',
            render: (row) => (
                <div className="max-w-xs">
                    <p className="font-medium text-[var(--color-text-primary)]">{row.subject}</p>
                    <p className="text-xs capitalize text-gray-400">
                        {row.channel?.replace('_', ' ')}
                        {row.category ? ` · ${row.category.name}` : ''}
                    </p>
                </div>
            ),
        },
        { field: 'customer_name', label: 'Customer', render: (row) => row.customer_name ?? '—' },
        {
            field: 'acknowledgement_due_at',
            label: 'Acknowledgement',
            render: (row) =>
                row.acknowledged_at ? (
                    <span className="badge badge-status-completed">Acknowledged</span>
                ) : (
                    <Countdown dueAt={row.acknowledgement_due_at} compact />
                ),
        },
        {
            field: 'days_open',
            label: 'Days open',
            render: (row) => <span className="tabular-nums">{row.days_open}</span>,
        },
        {
            field: 'penalty_exposure',
            label: 'Exposure',
            render: (row) =>
                row.penalty_exposure_minor > 0 ? (
                    <span className="font-semibold tabular-nums text-[var(--color-error)]">
                        {row.penalty_exposure?.formatted}
                    </span>
                ) : (
                    <span className="text-gray-400">—</span>
                ),
        },
        { field: 'assignee', label: 'Handler', render: (row) => row.assignee?.name ?? '—' },
        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
    ];

    return (
        <AuthenticatedLayout header="Complaints">
            <Head title="Complaints" />

            <PageHeader
                title="Consumer Complaints"
                subtitle="Omni-channel intake with the CBN acknowledgement clock and live penalty exposure"
                actions={
                    <>
                        <Link href={route('complaints.analysis')} className="btn-secondary">
                            Root-cause analysis
                        </Link>
                        {canCreate && (
                            <button className="btn-primary" onClick={() => setLogging(true)}>
                                Log a complaint
                            </button>
                        )}
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
                <StatCard title="Open" value={stats.open ?? 0} color="primary" />
                <StatCard
                    title="Unacknowledged"
                    value={stats.unacknowledged ?? 0}
                    color="warning"
                    prominent={(stats.unacknowledged ?? 0) > 0}
                />
                <StatCard title="SLA breached" value={stats.breached ?? 0} color="danger" />
                <StatCard title="With the regulator" value={stats.escalated ?? 0} color="info" />
            </div>

            {Object.keys(exposure).length > 0 && (
                <div className="card mb-6 border-l-4 border-l-[var(--color-error)]">
                    <div className="card-body">
                        <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                            Live penalty exposure
                        </p>
                        <div className="mt-2 flex flex-wrap gap-6">
                            {Object.entries(exposure).map(([currency, money]) => (
                                <p key={currency} className="text-2xl font-bold text-[var(--color-error)]">
                                    {money.formatted}
                                </p>
                            ))}
                        </div>
                        <p className="mt-2 text-xs text-gray-400">
                            Computed from the penalty fields on the applicable CBN consumer-protection obligation
                            records — never from a figure written into the application.
                        </p>
                    </div>
                </div>
            )}

            <FilterBar
                route="complaints.index"
                resource="complaints"
                currentFilters={filters}
                searchPlaceholder="Search by subject, reference or tracking ID…"
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    { name: 'status', type: 'select', label: 'Status', options: statuses },
                    { name: 'channel', type: 'select', label: 'Channel', options: channels },
                    {
                        name: 'category_id',
                        type: 'select',
                        label: 'Category',
                        options: categories.map((c) => ({ value: c.id, label: c.name })),
                    },
                    {
                        name: 'assigned_to',
                        type: 'select',
                        label: 'Handler',
                        options: handlers.map((h) => ({ value: h.id, label: h.name })),
                    },
                    { name: 'unacknowledged_only', type: 'checkbox', label: 'Unacknowledged' },
                    { name: 'breached_only', type: 'checkbox', label: 'Breached' },
                ]}
            />

            <div className="card">
                <DataTable
                    columns={columns}
                    data={complaints.data}
                    emptyMessage="No complaints match the filters."
                    onRowClick={(row) => router.visit(route('complaints.show', row.id))}
                />
                <Pagination paginator={complaints} />
            </div>

            <Modal show={logging} onClose={() => setLogging(false)} maxWidth="2xl">
                <form onSubmit={submit} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold">Log a complaint</h2>
                    <p className="text-sm text-gray-400">
                        The acknowledgement clock starts the moment this is saved, and a unique tracking ID is issued
                        automatically.
                    </p>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label className="form-label">Channel</label>
                            <select
                                className="form-select"
                                value={form.data.channel}
                                onChange={(e) => form.setData('channel', e.target.value)}
                            >
                                {channels.map((channel) => (
                                    <option key={channel} value={channel}>
                                        {channel.replace('_', ' ')}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="form-label">Category</label>
                            <select
                                className="form-select"
                                value={form.data.category_id}
                                onChange={(e) => form.setData('category_id', e.target.value)}
                            >
                                <option value="">—</option>
                                {categories.map((category) => (
                                    <option key={category.id} value={category.id}>
                                        {category.name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="sm:col-span-2">
                            <label className="form-label">Subject</label>
                            <input
                                className="form-input"
                                value={form.data.subject}
                                onChange={(e) => form.setData('subject', e.target.value)}
                            />
                        </div>

                        <div className="sm:col-span-2">
                            <label className="form-label">Description</label>
                            <textarea
                                className="form-input"
                                rows={3}
                                value={form.data.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                            />
                        </div>

                        <div>
                            <label className="form-label">Customer name</label>
                            <input
                                className="form-input"
                                value={form.data.customer_name}
                                onChange={(e) => form.setData('customer_name', e.target.value)}
                            />
                        </div>

                        <div>
                            <label className="form-label">Customer reference</label>
                            <input
                                className="form-input"
                                value={form.data.customer_reference}
                                onChange={(e) => form.setData('customer_reference', e.target.value)}
                            />
                        </div>

                        <div>
                            <label className="form-label">Handler</label>
                            <select
                                className="form-select"
                                value={form.data.assigned_to}
                                onChange={(e) => form.setData('assigned_to', e.target.value)}
                            >
                                <option value="">—</option>
                                {handlers.map((handler) => (
                                    <option key={handler.id} value={handler.id}>
                                        {handler.name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="form-label">Product</label>
                            <input
                                className="form-input"
                                value={form.data.product}
                                onChange={(e) => form.setData('product', e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="flex justify-end gap-2">
                        <button type="button" className="btn-secondary" onClick={() => setLogging(false)}>
                            Cancel
                        </button>
                        <button type="submit" className="btn-primary" disabled={form.processing}>
                            Log complaint
                        </button>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
