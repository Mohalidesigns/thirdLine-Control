import DataTable from '@/Components/DataTable';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';

export default function Library({ definitions, filters = {}, types = [], can = {} }) {
    const filter = (changes) => router.get(route('reports.library'), { ...filters, ...changes }, {
        preserveState: true,
        replace: true,
    });

    return (
        <AuthenticatedLayout header="Report library">
            <Head title="Report library" />

            <PageHeader
                title="Report library"
                subtitle="Designed reports, their approval state and how often they run."
                actions={
                    <>
                        {can.schedule && (
                            <Link href={route('reports.schedules')} className="btn-secondary">
                                Schedules
                            </Link>
                        )}
                        <Link href={route('reports.runs')} className="btn-secondary">
                            Run history
                        </Link>
                        {can.design && (
                            <Link href={route('reports.definitions.create')} className="btn-primary">
                                <Plus className="h-4 w-4" strokeWidth={2} /> New report
                            </Link>
                        )}
                    </>
                }
            />

            <div className="filter-bar">
                <div className="filter-bar-inner">
                    <div className="filter-bar-group">
                        <label className="filter-bar-label" htmlFor="report-search">Search</label>
                        <input
                            id="report-search"
                            className="filter-bar-input"
                            defaultValue={filters.search ?? ''}
                            onBlur={(event) => filter({ search: event.target.value })}
                            placeholder="Name or code"
                        />
                    </div>
                    <div className="filter-bar-group">
                        <label className="filter-bar-label" htmlFor="report-type">Type</label>
                        <select
                            id="report-type"
                            className="filter-bar-select"
                            value={filters.report_type ?? ''}
                            onChange={(event) => filter({ report_type: event.target.value })}
                        >
                            <option value="">All types</option>
                            {types.map((type) => (
                                <option key={type} value={type}>{type.replace(/_/g, ' ')}</option>
                            ))}
                        </select>
                    </div>
                </div>
            </div>

            <div className="card">
                <DataTable
                    columns={[
                        {
                            field: 'code',
                            label: 'Code',
                            render: (definition) => (
                                <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">
                                    {definition.code}
                                </span>
                            ),
                        },
                        {
                            field: 'name',
                            label: 'Report',
                            render: (definition) => (
                                <>
                                    <span className="font-medium text-[var(--color-text-primary)]">{definition.name}</span>
                                    {definition.is_system === true && (
                                        <span className="badge badge-status-active ms-2">Shipped</span>
                                    )}
                                </>
                            ),
                        },
                        {
                            field: 'report_type',
                            label: 'Type',
                            render: (definition) => definition.report_type.replace(/_/g, ' '),
                        },
                        { field: 'confidentiality', label: 'Classification' },
                        {
                            field: 'output_formats',
                            label: 'Formats',
                            render: (definition) => (definition.output_formats ?? []).join(', ').toUpperCase(),
                        },
                        {
                            field: 'approved_at',
                            label: 'Approval',
                            render: (definition) => (
                                <StatusBadge status={definition.approved_at ? 'Approved' : 'Draft'} />
                            ),
                        },
                        {
                            field: 'schedules_count',
                            label: 'Schedules',
                            render: (definition) => definition.schedules_count ?? 0,
                        },
                    ]}
                    data={definitions.data}
                    onRowClick={(definition) => router.visit(route('reports.definitions.show', definition.id))}
                    emptyMessage="No report definitions yet."
                />
                <Pagination paginator={definitions} />
            </div>
        </AuthenticatedLayout>
    );
}
