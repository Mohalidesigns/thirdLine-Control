import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Activity, AlertCircle, CalendarClock } from 'lucide-react';

export default function Exceptions({
    exceptions,
    filters = {},
    statuses = [],
    policies = [],
    stats = {},
}) {
    const { auth } = usePage().props;
    const canDecide = auth?.permissions?.includes('approve policy-exceptions');

    const columns = [
        {
            field: 'reference',
            label: 'Ref',
            render: (row) => <span className="font-mono text-xs font-semibold">{row.reference}</span>,
        },
        {
            field: 'policy',
            label: 'Policy',
            render: (row) =>
                row.policy ? (
                    <Link
                        href={route('policies.show', row.policy.id)}
                        className="text-[var(--color-primary)] hover:underline"
                    >
                        {row.policy.policy_ref} — {row.policy.title}
                    </Link>
                ) : (
                    '—'
                ),
        },
        { field: 'requester', label: 'Requested by', render: (row) => row.requester?.name ?? '—' },
        { field: 'entity', label: 'Entity', render: (row) => row.entity?.name ?? '—' },
        {
            field: 'requested_to',
            label: 'Window',
            render: (row) => (
                <span className="text-xs">
                    {formatDate(row.requested_from)} – {formatDate(row.requested_to)}
                </span>
            ),
        },
        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
        {
            field: 'decide',
            label: '',
            render: (row) =>
                canDecide && ['Requested', 'Under Review'].includes(row.status) ? (
                    <div className="flex gap-2">
                        <button
                            className="text-xs font-semibold text-[var(--color-success)] hover:underline"
                            onClick={() =>
                                router.post(route('policy-exceptions.decide', row.id), { approved: true }, { preserveScroll: true })
                            }
                        >
                            Approve
                        </button>
                        <button
                            className="text-xs font-semibold text-[var(--color-error)] hover:underline"
                            onClick={() =>
                                router.post(route('policy-exceptions.decide', row.id), { approved: false }, { preserveScroll: true })
                            }
                        >
                            Reject
                        </button>
                    </div>
                ) : null,
        },
    ];

    return (
        <AuthenticatedLayout header="Policy waivers">
            <Head title="Policy waivers" />

            <PageHeader
                title="Policy waivers"
                subtitle="Time-boxed, approved deviations from a published policy — never approved by the requester"
                breadcrumbs={[{ label: 'Policies', href: route('policies.index') }, { label: 'Waivers' }]}
                actions={
                    <Link href={route('policies.index')} className="btn-secondary">
                        Policy library
                    </Link>
                }
            />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard icon={AlertCircle} title="Open requests" value={stats.open ?? 0} color="warning" />
                <StatCard icon={Activity} title="Live waivers" value={stats.live ?? 0} color="info" />
                <StatCard icon={CalendarClock}
                    title="Expiring in 30 days"
                    value={stats.expiring ?? 0}
                    color="danger"
                    prominent={(stats.expiring ?? 0) > 0}
                />
            </div>

            <FilterBar
                route="policy-exceptions.index"
                resource="policy-exceptions"
                currentFilters={filters}
                filters={[
                    { name: 'status', type: 'select', label: 'Status', options: statuses },
                    {
                        name: 'policy_id',
                        type: 'select',
                        label: 'Policy',
                        options: policies.map((p) => ({ value: p.id, label: `${p.policy_ref} — ${p.title}` })),
                    },
                    { name: 'open_only', type: 'checkbox', label: 'Open only' },
                ]}
            />

            <div className="card">
                <DataTable columns={columns} data={exceptions.data} emptyMessage="No waivers match the filters." />
                <Pagination paginator={exceptions} />
            </div>
        </AuthenticatedLayout>
    );
}
