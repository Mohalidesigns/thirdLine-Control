import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import ProgressBar from '@/Components/ProgressBar';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate, formatMoney } from '@/utils';
import { Head, Link, router } from '@inertiajs/react';

export default function Index({ treatments, filters = {}, statuses = [], strategies = [], owners = [], stats = {} }) {
    const columns = [
        {
            field: 'reference',
            label: 'Ref',
            render: (row) => <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{row.reference}</span>,
        },
        {
            field: 'title',
            label: 'Treatment',
            render: (row) => (
                <div className="max-w-sm">
                    <p className="font-medium text-[var(--color-text-primary)]">{row.title}</p>
                    <p className="text-xs text-gray-400">
                        {row.risk ? `${row.risk.code} — ${row.risk.title}` : '—'}
                    </p>
                </div>
            ),
        },
        { field: 'strategy', label: 'Strategy', render: (row) => <span className="badge badge-status-draft">{row.strategy}</span> },
        { field: 'owner', label: 'Owner', render: (row) => row.owner?.name ?? '—' },
        {
            field: 'progress',
            label: 'Progress',
            render: (row) => (
                <div className="w-28">
                    <ProgressBar
                        value={row.progress ?? 0}
                        color={row.status === 'Overdue' ? 'var(--color-error)' : 'var(--color-success)'}
                    />
                    <p className="mt-1 text-xs tabular-nums text-gray-400">
                        {row.progress ?? 0}%
                        {row.milestones_count > 0 ? ` · ${row.completed_milestones_count}/${row.milestones_count}` : ''}
                    </p>
                </div>
            ),
        },
        {
            field: 'due_at',
            label: 'Due',
            render: (row) => (
                <span className={row.status === 'Overdue' ? 'font-semibold text-[var(--color-error)]' : ''}>
                    {formatDate(row.due_at)}
                </span>
            ),
        },
        {
            field: 'cost_minor',
            label: 'Cost',
            render: (row) => (row.cost_minor ? formatMoney(row.cost_minor, row.currency) : '—'),
        },
        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
    ];

    return (
        <AuthenticatedLayout header="Treatment Plans">
            <Head title="Treatment Plans" />

            <PageHeader
                title="Risk Treatment Plans"
                subtitle="Avoid, reduce, transfer, accept or exploit — with milestones, cost and independent verification"
                actions={
                    <Link href={route('risks.index')} className="btn-secondary">
                        Risk register
                    </Link>
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
                <StatCard title="Open plans" value={stats.open ?? 0} color="primary" />
                <StatCard title="Overdue" value={stats.overdue ?? 0} color="danger" prominent={(stats.overdue ?? 0) > 0} />
                <StatCard title="Awaiting verification" value={stats.awaiting_verification ?? 0} color="warning" />
                <StatCard title="Accepted risks" value={stats.accepted ?? 0} color="info" />
            </div>

            <FilterBar
                route="treatments.index"
                resource="treatments"
                currentFilters={filters}
                searchPlaceholder="Search treatment plans…"
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    { name: 'status', type: 'select', label: 'Status', options: statuses },
                    { name: 'strategy', type: 'select', label: 'Strategy', options: strategies },
                    { name: 'owner_id', type: 'select', label: 'Owner', options: owners.map((o) => ({ value: o.id, label: o.name })) },
                    { name: 'overdue_only', type: 'checkbox', label: 'Overdue only' },
                ]}
            />

            <div className="card">
                <DataTable
                    columns={columns}
                    data={treatments.data}
                    emptyMessage="No treatment plans match the filters."
                    onRowClick={(row) => router.visit(route('treatments.show', row.id))}
                />
                <Pagination paginator={treatments} />
            </div>
        </AuthenticatedLayout>
    );
}
