import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, Link, router } from '@inertiajs/react';

export default function Index({ spotChecks, filters = {}, statuses = [] }) {
    return (
        <AuthenticatedLayout header="Spot Checks">
            <Head title="Spot Checks" />

            <PageHeader
                title="Spot checks"
                subtitle="Ad-hoc control reviews outside the testing calendar"
                actions={
                    <Link href={route('spot-checks.create')} className="btn-primary">
                        + New spot check
                    </Link>
                }
            />

            <FilterBar
                route="spot-checks.index"
                resource="spot-checks"
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
                            field: 'title',
                            label: 'Spot check',
                            render: (row) => (
                                <div>
                                    <p className="font-medium text-[var(--color-text-primary)]">{row.title}</p>
                                    {row.is_surprise && <span className="badge badge-status-pending">Unannounced</span>}
                                </div>
                            ),
                        },
                        { field: 'unit', label: 'Unit', render: (row) => row.unit?.name ?? '—' },
                        { field: 'date_conducted', label: 'Date', render: (row) => formatDate(row.date_conducted) },
                        { field: 'conductor', label: 'Conducted by', render: (row) => row.conductor?.name ?? '—' },
                        { field: 'findings_count', label: 'Findings' },
                        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
                    ]}
                    data={spotChecks.data}
                    emptyMessage="No spot checks recorded."
                    onRowClick={(row) => router.visit(route('spot-checks.show', row.id))}
                />
                <Pagination paginator={spotChecks} />
            </div>
        </AuthenticatedLayout>
    );
}
