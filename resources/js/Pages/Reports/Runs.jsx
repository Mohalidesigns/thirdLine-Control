import DataTable from '@/Components/DataTable';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, Link, router } from '@inertiajs/react';

export default function Runs({ runs, filters = {}, statuses = [], definitions = [] }) {
    const filter = (changes) => router.get(route('reports.runs'), { ...filters, ...changes }, {
        preserveState: true,
        replace: true,
    });

    return (
        <AuthenticatedLayout header="Report runs">
            <Head title="Report runs" />

            <PageHeader
                title="Report runs"
                subtitle="Every generation, with its checksum and who received it."
                breadcrumbs={[{ label: 'Report library', href: route('reports.library') }, { label: 'Run history' }]}
                actions={<Link href={route('reports.library')} className="btn-secondary">Back to library</Link>}
            />

            <div className="filter-bar">
                <div className="filter-bar-inner">
                    <div className="filter-bar-group">
                        <label className="filter-bar-label" htmlFor="run-status">Status</label>
                        <select
                            id="run-status"
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
                        <label className="filter-bar-label" htmlFor="run-definition">Report</label>
                        <select
                            id="run-definition"
                            className="filter-bar-select"
                            value={filters.report_definition_id ?? ''}
                            onChange={(event) => filter({ report_definition_id: event.target.value })}
                        >
                            <option value="">All reports</option>
                            {definitions.map((definition) => (
                                <option key={definition.id} value={definition.id}>{definition.name}</option>
                            ))}
                        </select>
                    </div>
                </div>
            </div>

            <div className="card">
                <DataTable
                    columns={[
                        {
                            field: 'run_ref',
                            label: 'Reference',
                            render: (run) => <span className="font-mono text-xs font-semibold">{run.run_ref}</span>,
                        },
                        { field: 'definition', label: 'Report', render: (run) => run.definition?.name },
                        { field: 'output_format', label: 'Format', render: (run) => run.output_format.toUpperCase() },
                        { field: 'status', label: 'Status', render: (run) => <StatusBadge status={run.status} /> },
                        { field: 'schedule', label: 'Trigger', render: (run) => run.schedule?.name ?? 'On demand' },
                        {
                            field: 'distributed_to',
                            label: 'Distribution',
                            render: (run) => {
                                const delivered = run.distributed_to?.delivered?.length ?? 0;
                                const withheld = run.distributed_to?.withheld?.length ?? 0;

                                if (!run.distributed_to) {
                                    return '—';
                                }

                                return withheld > 0
                                    ? `${delivered} sent · ${withheld} withheld`
                                    : `${delivered} sent`;
                            },
                        },
                        { field: 'completed_at', label: 'Completed', render: (run) => formatDate(run.completed_at) },
                        {
                            field: 'checksum',
                            label: 'Checksum',
                            render: (run) => run.checksum
                                ? <span className="font-mono text-[10px] text-gray-400">{run.checksum.slice(0, 12)}…</span>
                                : '—',
                        },
                        {
                            field: 'download',
                            label: '',
                            render: (run) => run.status === 'Completed' && (
                                <a
                                    href={route('reports.runs.download', run.id)}
                                    className="text-sm font-medium text-[var(--color-primary)] hover:underline"
                                >
                                    Download
                                </a>
                            ),
                        },
                    ]}
                    data={runs.data}
                    emptyMessage="No reports have been generated yet."
                />
                <Pagination paginator={runs} />
            </div>
        </AuthenticatedLayout>
    );
}
