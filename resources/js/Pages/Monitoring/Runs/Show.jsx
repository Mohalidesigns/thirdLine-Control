import DataTable from '@/Components/DataTable';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDateTime, formatNumber } from '@/utils';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { Gauge, Timer, TrendingUp, XCircle } from 'lucide-react';

const SEVERITY_CLASSES = {
    Critical: 'badge-critical',
    High: 'badge-high',
    Medium: 'badge-medium',
    Low: 'badge-low',
};

export default function Show({ run, findings, snapshots = [], can = {} }) {
    const [expanded, setExpanded] = useState(null);

    const columns = [
        {
            field: 'record_identifier',
            label: 'Record',
            render: (row) => <span className="font-mono text-xs">{row.record_identifier || '—'}</span>,
        },
        {
            field: 'failure_reason',
            label: 'Why it failed',
            render: (row) => (
                <div className="max-w-xl">
                    <p className="text-sm">{row.failure_reason}</p>
                    <button
                        type="button"
                        className="mt-1 text-xs text-[var(--color-primary)] hover:underline"
                        onClick={() => setExpanded(expanded === row.id ? null : row.id)}
                    >
                        {expanded === row.id ? 'Hide record' : 'Show record'}
                    </button>
                    {expanded === row.id && (
                        <pre className="mt-2 max-h-48 overflow-auto rounded-lg bg-gray-50 p-2 font-mono text-xs">
                            {JSON.stringify(row.record_data, null, 2)}
                        </pre>
                    )}
                </div>
            ),
        },
        {
            field: 'severity',
            label: 'Severity',
            render: (row) => <span className={`badge ${SEVERITY_CLASSES[row.severity]}`}>{row.severity}</span>,
        },
        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
        {
            field: 'exception',
            label: 'Exception',
            render: (row) =>
                row.exception ? (
                    <Link href={route('exceptions.show', row.exception.id)} className="text-xs text-[var(--color-primary)] hover:underline">
                        {row.exception.reference}
                    </Link>
                ) : (
                    <span className="text-xs text-gray-400">—</span>
                ),
        },
    ];

    return (
        <AuthenticatedLayout header={`Run ${run.run_ref}`}>
            <Head title={`Run ${run.run_ref}`} />

            <PageHeader
                title={run.run_ref}
                subtitle={`${run.rule?.name} · ${run.trigger} run${run.triggered_by ? ` by ${run.triggered_by.name}` : ''}`}
                breadcrumbs={[
                    { label: 'Continuous monitoring', href: route('monitoring.dashboard') },
                    { label: 'Runs', href: route('monitoring-runs.index') },
                    { label: run.run_ref },
                ]}
                actions={
                    can.review && (
                        <Link href={route('monitoring-findings.index', { rule_id: run.rule_id })} className="btn-primary">
                            Review findings
                        </Link>
                    )
                }
            />

            {run.status === 'Failed' && (
                <div className="mb-6 rounded-lg border-l-4 border-l-[var(--color-error)] bg-red-50 px-4 py-3 text-sm">
                    <p className="font-semibold text-[var(--color-error)]">The run failed</p>
                    <p className="mt-1 text-[var(--color-text-secondary)]">{run.error_message}</p>
                </div>
            )}

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard icon={Gauge} title="Evaluated" value={formatNumber(run.records_evaluated)} color="primary" />
                <StatCard icon={XCircle}
                    title="Failed"
                    value={formatNumber(run.records_failed)}
                    color="danger"
                    prominent={run.records_failed > 0}
                />
                <StatCard icon={TrendingUp} title="Exception rate" value={`${(run.exception_rate * 100).toFixed(2)}%`} color="warning" />
                <StatCard icon={Timer}
                    title="Duration"
                    value={run.duration_ms != null ? `${run.duration_ms}ms` : '—'}
                    subtitle={formatDateTime(run.started_at)}
                    color="info"
                />
            </div>

            <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="card">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">Evidence</h2>
                    </div>
                    <div className="card-body space-y-3 text-sm">
                        {run.sampling_seed != null && (
                            <p className="rounded-lg bg-gray-50 p-2 text-xs">
                                Sampled with method <strong>{run.rule?.sample_config?.method ?? 'random'}</strong> and seed{' '}
                                <span className="font-mono">{run.sampling_seed}</span>. Re-running with this seed selects the same
                                items.
                            </p>
                        )}
                        {snapshots.length === 0 ? (
                            <p className="text-xs text-gray-400">No snapshots recorded on this run.</p>
                        ) : (
                            <ul className="space-y-2">
                                {snapshots.map((snapshot) => (
                                    <li key={snapshot.id} className="rounded-lg border border-gray-100 p-2 text-xs">
                                        <p className="font-mono font-semibold">{snapshot.snapshot_ref}</p>
                                        <p className="text-gray-400">
                                            {snapshot.dataset?.name} · {formatNumber(snapshot.row_count)} row(s) ·{' '}
                                            {formatDateTime(snapshot.captured_at)}
                                        </p>
                                        <p className="mt-1 break-all font-mono text-[10px] text-gray-400">
                                            sha256 {snapshot.checksum?.slice(0, 32)}…
                                        </p>
                                        <div className="mt-1 flex gap-1">
                                            <StatusBadge status={snapshot.status} />
                                            {snapshot.legal_hold && <span className="badge badge-critical">Legal hold</span>}
                                            {snapshot.dataset?.pii_classification !== 'none' && (
                                                <span className="badge badge-status-draft">
                                                    {snapshot.dataset?.pii_classification}
                                                </span>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>

                <div className="card lg:col-span-2">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">Result summary</h2>
                    </div>
                    <div className="card-body">
                        {run.test_instance && (
                            <p className="mb-3 rounded-lg bg-gray-50 p-2 text-xs">
                                This run populated test instance{' '}
                                <Link
                                    href={route('test-instances.show', run.test_instance.id)}
                                    className="font-mono text-[var(--color-primary)] hover:underline"
                                >
                                    {run.test_instance.reference}
                                </Link>{' '}
                                for {run.test_instance.period_label}. It is <StatusBadge status={run.test_instance.status} /> — a
                                person still reviews it and a second person still approves the rating.
                            </p>
                        )}
                        <pre className="overflow-x-auto rounded-lg bg-gray-50 p-3 font-mono text-xs">
                            {JSON.stringify(run.result_summary, null, 2)}
                        </pre>
                    </div>
                </div>
            </div>

            <div className="card">
                <div className="card-header">
                    <h2 className="text-sm font-semibold">Findings</h2>
                </div>
                <DataTable columns={columns} data={findings.data} emptyMessage="This run raised no findings." />
                <Pagination paginator={findings} />
            </div>
        </AuthenticatedLayout>
    );
}
