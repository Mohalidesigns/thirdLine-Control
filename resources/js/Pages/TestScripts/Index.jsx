import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';

export default function Index({ scripts, filters = {} }) {
    return (
        <AuthenticatedLayout header="Test Scripts">
            <Head title="Test Scripts" />

            <PageHeader
                title="Test scripts"
                subtitle="Reusable, checklist-driven test procedures per control"
                actions={
                    <Link href={route('test-scripts.create')} className="btn-primary">
                        <Plus className="h-4 w-4" strokeWidth={2} /> New script
                    </Link>
                }
            />

            <FilterBar
                route="test-scripts.index"
                resource="test-scripts"
                currentFilters={filters}
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    { name: 'status', type: 'select', label: 'Status', options: ['Draft', 'Pending Approval', 'Active', 'Retired'] },
                ]}
            />

            <div className="card">
                <DataTable
                    columns={[
                        {
                            field: 'title',
                            label: 'Script',
                            render: (row) => (
                                <div>
                                    <p className="font-medium text-[var(--color-text-primary)]">{row.title}</p>
                                    <p className="text-xs text-gray-400">v{row.version_no}</p>
                                </div>
                            ),
                        },
                        {
                            field: 'control',
                            label: 'Control',
                            render: (row) => (
                                <span className="font-mono text-xs">{row.control?.control_ref}</span>
                            ),
                        },
                        { field: 'creator', label: 'Author', render: (row) => row.creator?.name ?? '—' },
                        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
                    ]}
                    data={scripts.data}
                    emptyMessage="No test scripts yet."
                    onRowClick={(row) => router.visit(route('test-scripts.show', row.id))}
                />
                <Pagination paginator={scripts} />
            </div>
        </AuthenticatedLayout>
    );
}
