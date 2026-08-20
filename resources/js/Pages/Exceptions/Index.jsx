import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import SeverityBadge from '@/Components/SeverityBadge';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';

export default function Index({ exceptions, filters = {}, statuses = [], severities = [], units = [] }) {
    const { auth } = usePage().props;
    const canCreate = auth.permissions.includes('create exceptions');

    return (
        <AuthenticatedLayout header="Exception Tracker">
            <Head title="Exceptions" />

            <PageHeader
                title="Exception tracker"
                subtitle="Deviations from expected control performance, chased to verified closure"
                actions={
                    <>
                        {auth.permissions.includes('export reports') && (
                            <>
                                <a href={route('reports.exceptions.xlsx', filters)} className="btn-secondary">Excel</a>
                                <a href={route('reports.exceptions.pdf', filters)} className="btn-secondary">PDF</a>
                            </>
                        )}
                        {canCreate && (
                            <Link href={route('exceptions.create')} className="btn-primary">
                                <Plus className="h-4 w-4" strokeWidth={2} /> Raise exception
                            </Link>
                        )}
                    </>
                }
            />

            <FilterBar
                route="exceptions.index"
                resource="exceptions"
                currentFilters={filters}
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    { name: 'status', type: 'select', label: 'Status', options: [{ value: 'open', label: 'All open' }, ...statuses] },
                    { name: 'severity', type: 'select', label: 'Severity', options: severities },
                    { name: 'unit_id', type: 'select', label: 'Unit', options: units.map((u) => ({ value: u.id, label: u.name })) },
                    { name: 'overdue', type: 'checkbox', label: 'Overdue only' },
                ]}
            />

            <div className="card">
                <DataTable
                    columns={[
                        { field: 'reference', label: 'Ref', render: (row) => <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{row.reference}</span> },
                        {
                            field: 'title',
                            label: 'Exception',
                            render: (row) => (
                                <div>
                                    <p className="max-w-md truncate font-medium text-[var(--color-text-primary)]">{row.title}</p>
                                    <p className="text-xs text-gray-400">
                                        {row.control ? `${row.control.control_ref} · ` : ''}{row.source_type}
                                        {row.is_recurring && <span className="ms-1 badge badge-high">Recurring</span>}
                                    </p>
                                </div>
                            ),
                        },
                        { field: 'severity', label: 'Severity', render: (row) => <SeverityBadge severity={row.severity} /> },
                        { field: 'owner', label: 'Owner', render: (row) => row.owner?.name ?? <span className="text-gray-400">Unassigned</span> },
                        {
                            field: 'age_days',
                            label: 'Age',
                            render: (row) => <span className={row.age_days > 90 ? 'font-semibold text-[var(--color-error)]' : ''}>{row.age_days}d</span>,
                        },
                        {
                            field: 'target_closure_date',
                            label: 'Target',
                            render: (row) => (
                                <span className={row.is_overdue ? 'font-semibold text-[var(--color-error)]' : ''}>
                                    {formatDate(row.target_closure_date)}
                                    {row.is_overdue && ' ⚠'}
                                </span>
                            ),
                        },
                        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
                    ]}
                    data={exceptions.data}
                    emptyMessage="No exceptions match the current filters."
                    onRowClick={(row) => router.visit(route('exceptions.show', row.id))}
                />
                <Pagination paginator={exceptions} />
            </div>
        </AuthenticatedLayout>
    );
}
