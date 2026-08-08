import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';

const TYPE_BADGES = {
    Preventive: 'bg-blue-50 text-blue-700',
    Detective: 'bg-amber-50 text-amber-700',
    Corrective: 'bg-purple-50 text-purple-700',
};

export default function Index({
    controls,
    filters = {},
    categories = [],
    units = [],
    statuses = [],
    types = [],
    natures = [],
    designRatings = [],
    operatingRatings = [],
    functionGroupings = [],
    controlLevels = [],
    implementationStatuses = [],
    stats = {},
}) {
    const { auth } = usePage().props;
    const canCreate = auth.permissions.includes('create controls');

    const columns = [
        {
            field: 'control_ref',
            label: 'Control Ref',
            render: (row) => <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{row.control_ref}</span>,
        },
        {
            field: 'title',
            label: 'Title',
            render: (row) => (
                <div className="max-w-xs">
                    <p className="font-medium text-[var(--color-text-primary)]">{row.title}</p>
                    {row.description && <p className="truncate text-xs text-gray-400">{row.description}</p>}
                </div>
            ),
        },
        {
            field: 'type',
            label: 'Type',
            render: (row) => (
                <span className={`badge ${TYPE_BADGES[row.type] ?? 'badge-status-draft'}`}>{row.type}</span>
            ),
        },
        { field: 'category', label: 'Category', render: (row) => row.category?.name ?? '—' },
        {
            field: 'control_level',
            label: 'Level',
            render: (row) => (
                <span>
                    {row.control_level ?? '—'}
                    {row.library_level === 'group' && (
                        <span className="ml-1 rounded bg-[var(--color-primary)]/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-[var(--color-primary)]">
                            Group
                        </span>
                    )}
                </span>
            ),
        },
        { field: 'function_grouping', label: 'Function', render: (row) => row.function_grouping ?? '—' },
        { field: 'frequency', label: 'Frequency' },
        {
            field: 'entity_instances_count',
            label: 'Entities',
            render: (row) => (row.library_level === 'group' ? row.entity_instances_count : '—'),
        },
        {
            field: 'implementation_progress',
            label: 'Implementation',
            render: (row) => (
                <div className="w-24">
                    <div className="progress-bar">
                        <div className="progress-bar-fill bg-[var(--color-secondary)]" style={{ width: `${row.implementation_progress ?? 0}%` }} />
                    </div>
                    <p className="mt-0.5 text-[10px] text-gray-400">{row.implementation_status}</p>
                </div>
            ),
        },
        {
            field: 'overall_rating',
            label: 'Rating',
            render: (row) => (row.overall_rating ? <StatusBadge status={row.overall_rating} /> : '—'),
        },
        { field: 'owner', label: 'Owner', render: (row) => row.owner?.name ?? '—' },
        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
    ];

    return (
        <AuthenticatedLayout header="Control Library">
            <Head title="Control Library" />

            <PageHeader
                title="Control Library"
                subtitle="Manage and assess organizational controls"
                actions={
                    <>
                        {auth.permissions.includes('view distributions') && (
                            <Link href={route('distributions.index')} className="btn-secondary">
                                Distribution
                            </Link>
                        )}
                        {auth.permissions.includes('export reports') && (
                            <a href={route('reports.controls.xlsx')} className="btn-secondary">Excel</a>
                        )}
                        {canCreate && (
                            <>
                                <Link href={route('controls.templates')} className="btn-secondary">
                                    Starter library
                                </Link>
                                <Link href={route('controls.create')} className="btn-primary">
                                    + Add Control
                                </Link>
                            </>
                        )}
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
                <StatCard title="Total Controls" value={stats.total ?? 0} color="primary" />
                <StatCard title="Design Adequate" value={stats.designAdequate ?? 0} color="success" />
                <StatCard title="Design Inadequate" value={stats.designInadequate ?? 0} color="danger" />
                <StatCard title="Operating Effective" value={stats.operatingEffective ?? 0} color="success" />
                <StatCard title="Operating Ineffective" value={stats.operatingIneffective ?? 0} color="danger" />
                <StatCard title="Not Tested" value={stats.notTested ?? 0} color="warning" />
            </div>

            <FilterBar
                route="controls.index"
                resource="controls"
                currentFilters={filters}
                searchPlaceholder="Search controls…"
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    { name: 'type', type: 'select', label: 'Control Type', options: types },
                    { name: 'nature', type: 'select', label: 'Nature', options: natures },
                    { name: 'design', type: 'select', label: 'Design', options: designRatings },
                    { name: 'operating', type: 'select', label: 'Operating', options: operatingRatings },
                    { name: 'status', type: 'select', label: 'Status', options: statuses },
                    { name: 'category_id', type: 'select', label: 'Category', options: categories.map((c) => ({ value: c.id, label: c.name })) },
                    { name: 'unit_id', type: 'select', label: 'Entity', options: units.map((u) => ({ value: u.id, label: u.name })) },
                    { name: 'library_level', type: 'select', label: 'Library', options: [{ value: 'group', label: 'Group' }, { value: 'entity', label: 'Entity' }] },
                    { name: 'function_grouping', type: 'select', label: 'Function', options: functionGroupings },
                    { name: 'control_level', type: 'select', label: 'Level', options: controlLevels },
                    { name: 'implementation_status', type: 'select', label: 'Implementation', options: implementationStatuses },
                ]}
            />

            <div className="card">
                <DataTable
                    columns={columns}
                    data={controls.data}
                    emptyMessage="No controls match the current filters."
                    onRowClick={(row) => router.visit(route('controls.show', row.id))}
                />
                <Pagination paginator={controls} />
            </div>
        </AuthenticatedLayout>
    );
}
