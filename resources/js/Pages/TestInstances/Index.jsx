import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, Link, router, usePage } from '@inertiajs/react';

export default function Index({ instances, filters = {}, statuses = [] }) {
    const { auth } = usePage().props;
    const view = filters.view ?? 'all';
    const isReviewer = auth.roles.includes('Control Function Head') || auth.roles.includes('System Administrator');

    const tabs = [
        { key: 'all', label: 'All tests' },
        { key: 'mine', label: 'My tests' },
        ...(isReviewer ? [{ key: 'review', label: 'Review queue' }] : []),
    ];

    return (
        <AuthenticatedLayout header="Control Testing">
            <Head title="Control Testing" />

            <PageHeader
                title="Control testing"
                subtitle="Scheduled and ad-hoc test instances"
                actions={
                    auth.permissions.includes('export reports') && (
                        <>
                            <a href={route('reports.test-instances.xlsx')} className="btn-secondary">Excel</a>
                            <a href={route('reports.testing-summary')} className="btn-secondary">Summary PDF</a>
                        </>
                    )
                }
            />

            <div className="mb-4 flex gap-1 rounded-xl bg-gray-100 p-1">
                {tabs.map((tab) => (
                    <button
                        key={tab.key}
                        type="button"
                        onClick={() => router.get(route('test-instances.index'), tab.key === 'all' ? {} : { view: tab.key }, { preserveState: true })}
                        className={`flex-1 rounded-lg px-4 py-2 text-sm font-medium transition-colors duration-200 ${
                            view === tab.key ? 'bg-white text-[var(--color-primary)] shadow-sm' : 'text-gray-500 hover:text-gray-700'
                        }`}
                    >
                        {tab.label}
                    </button>
                ))}
            </div>

            <FilterBar
                route="test-instances.index"
                resource="test-instances"
                currentFilters={filters}
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    { name: 'status', type: 'select', label: 'Status', options: statuses },
                ]}
            />

            <div className="card">
                <DataTable
                    columns={[
                        { field: 'reference', label: 'Ref', render: (row) => <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{row.reference}</span> },
                        {
                            field: 'control',
                            label: 'Control',
                            render: (row) => (
                                <div>
                                    <p className="font-medium text-[var(--color-text-primary)]">{row.control?.title}</p>
                                    <p className="font-mono text-xs text-gray-400">{row.control?.control_ref}</p>
                                </div>
                            ),
                        },
                        { field: 'period_label', label: 'Period' },
                        {
                            field: 'due_date',
                            label: 'Due',
                            render: (row) => (
                                <span className={row.is_overdue ? 'font-semibold text-[var(--color-error)]' : ''}>
                                    {formatDate(row.due_date)}
                                    {row.is_overdue && ' (overdue)'}
                                </span>
                            ),
                        },
                        { field: 'tester', label: 'Tester', render: (row) => row.tester?.name ?? <span className="text-gray-400">Unassigned</span> },
                        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
                    ]}
                    data={instances.data}
                    emptyMessage="No test instances found. The daily scheduler generates them from each active control's frequency."
                    onRowClick={(row) => router.visit(route('test-instances.show', row.id))}
                />
                <Pagination paginator={instances} />
            </div>
        </AuthenticatedLayout>
    );
}
