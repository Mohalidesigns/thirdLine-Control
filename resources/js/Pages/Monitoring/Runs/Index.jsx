import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDateTime, formatNumber } from '@/utils';
import { Head, Link, router } from '@inertiajs/react';

export default function Index({ runs, filters = {}, statuses = [], rules = [], stats = {} }) {
    const columns = [
        {
            field: 'run_ref',
            label: 'Run',
            render: (row) => <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{row.run_ref}</span>,
        },
        {
            field: 'rule',
            label: 'Rule',
            render: (row) => (
                <div className="max-w-xs">
                    <p className="font-medium">{row.rule?.name}</p>
                    <p className="font-mono text-xs text-gray-400">
                        {row.rule?.rule_ref} · {row.rule?.rule_type}
                    </p>
                </div>
            ),
        },
        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
        {
            field: 'records',
            label: 'Result',
            render: (row) =>
                row.status === 'Failed' ? (
                    <span className="text-xs text-[var(--color-error)]">{row.error_message ?? 'Run failed'}</span>
                ) : (
                    <span className="tabular-nums">
                        {formatNumber(row.records_failed)} of {formatNumber(row.records_evaluated)} failed ·{' '}
                        {(row.exception_rate * 100).toFixed(2)}%
                    </span>
                ),
        },
        {
            field: 'test_instance',
            label: 'Test instance',
            render: (row) =>
                row.test_instance ? (
                    <Link
                        href={route('test-instances.show', row.test_instance.id)}
                        className="text-xs text-[var(--color-primary)] hover:underline"
                        onClick={(e) => e.stopPropagation()}
                    >
                        {row.test_instance.reference}
                    </Link>
                ) : (
                    <span className="text-xs text-gray-400">—</span>
                ),
        },
        {
            field: 'started_at',
            label: 'Started',
            render: (row) => (
                <span className="text-xs">
                    {formatDateTime(row.started_at)}
                    {row.duration_ms != null && <span className="text-gray-400"> · {row.duration_ms}ms</span>}
                </span>
            ),
        },
    ];

    return (
        <AuthenticatedLayout header="Monitoring Runs">
            <Head title="Monitoring Runs" />

            <PageHeader
                title="Monitoring runs"
                subtitle="Every execution, what it read and what it found"
                breadcrumbs={[
                    { label: 'Continuous monitoring', href: route('monitoring.dashboard') },
                    { label: 'Runs' },
                ]}
                actions={
                    <Link href={route('monitoring-rules.index')} className="btn-secondary">
                        Rules
                    </Link>
                }
            />

            <div className="mb-6 grid grid-cols-3 gap-4">
                <StatCard title="Runs today" value={stats.today ?? 0} color="primary" />
                <StatCard title="With findings" value={stats.with_findings ?? 0} color="warning" />
                <StatCard title="Failed to run" value={stats.failed ?? 0} color="danger" prominent={(stats.failed ?? 0) > 0} />
            </div>

            <FilterBar
                route="monitoring-runs.index"
                resource="monitoring-runs"
                currentFilters={filters}
                filters={[
                    { name: 'status', type: 'select', label: 'Status', options: statuses },
                    {
                        name: 'rule_id',
                        type: 'select',
                        label: 'Rule',
                        options: rules.map((r) => ({ value: r.id, label: `${r.rule_ref} — ${r.name}` })),
                    },
                    { name: 'failures_only', type: 'checkbox', label: 'With findings only' },
                ]}
            />

            <div className="card">
                <DataTable
                    columns={columns}
                    data={runs.data}
                    emptyMessage="No runs match the filters."
                    onRowClick={(row) => router.visit(route('monitoring-runs.show', row.id))}
                />
                <Pagination paginator={runs} />
            </div>
        </AuthenticatedLayout>
    );
}
