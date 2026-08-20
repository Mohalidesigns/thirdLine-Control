import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import ProgressBar from '@/Components/ProgressBar';
import Sparkline from '@/Components/Sparkline';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDateTime, formatNumber } from '@/utils';
import { Head, Link } from '@inertiajs/react';
import { AlertCircle, RefreshCw, TrendingUp, XCircle } from 'lucide-react';

const HEALTH_CLASSES = {
    Healthy: 'badge-low',
    Degraded: 'badge-medium',
    Failed: 'badge-critical',
    Unknown: 'badge-status-draft',
};

const SEVERITY_CLASSES = {
    Critical: 'badge-critical',
    High: 'badge-high',
    Medium: 'badge-medium',
    Low: 'badge-low',
};

export default function Dashboard({ dashboard = {}, sources = [], uncoveredKeyControls = [], can = {} }) {
    const coverage = dashboard.coverage ?? {};
    const trend = (dashboard.exception_rate_trend ?? []).map((point) => point.rate);

    return (
        <AuthenticatedLayout header="Continuous Monitoring">
            <Head title="Continuous Monitoring" />

            <PageHeader
                title="Continuous controls monitoring"
                subtitle="Source health, rule coverage and what the rules found overnight"
                actions={
                    <div className="flex flex-wrap gap-2">
                        <Link href={route('monitoring-rules.index')} className="btn-secondary">
                            Rules
                        </Link>
                        <Link href={route('monitoring-findings.index')} className="btn-primary">
                            Open findings
                        </Link>
                    </div>
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard icon={TrendingUp}
                    title="Key control coverage"
                    value={`${coverage.percentage ?? 0}%`}
                    subtitle={`${coverage.monitored ?? 0} of ${coverage.key_controls ?? 0} key controls have an active rule`}
                    color={(coverage.percentage ?? 0) >= 60 ? 'success' : 'warning'}
                />
                <StatCard icon={AlertCircle}
                    title="Open findings"
                    value={formatNumber(dashboard.findings?.open ?? 0)}
                    subtitle={`${dashboard.findings?.critical ?? 0} critical · ${dashboard.findings?.high ?? 0} high`}
                    color="danger"
                    prominent={(dashboard.findings?.critical ?? 0) > 0}
                    href={route('monitoring-findings.index')}
                />
                <StatCard icon={XCircle}
                    title="Sources failing"
                    value={dashboard.sources?.failed ?? 0}
                    subtitle={`${dashboard.sources?.healthy ?? 0} healthy of ${dashboard.sources?.total ?? 0}`}
                    color={(dashboard.sources?.failed ?? 0) > 0 ? 'danger' : 'success'}
                />
                <StatCard icon={RefreshCw}
                    title="Rows ingested (30d)"
                    value={formatNumber(dashboard.rows_ingested_30d ?? 0)}
                    subtitle={`${dashboard.rules?.active ?? 0} active rule(s)`}
                    color="info"
                />
            </div>

            <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="card lg:col-span-2">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">Exception rate, last 30 days</h2>
                    </div>
                    <div className="card-body">
                        {trend.length > 1 ? (
                            <>
                                <Sparkline series={trend} />
                                <div className="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-xs text-[var(--color-text-secondary)]">
                                    <span>
                                        Latest {trend[trend.length - 1]?.toFixed(2)}% across{' '}
                                        {dashboard.exception_rate_trend?.[dashboard.exception_rate_trend.length - 1]?.runs ?? 0} run(s)
                                    </span>
                                    <span>{dashboard.exception_rate_trend?.length ?? 0} day(s) with runs</span>
                                </div>
                            </>
                        ) : (
                            <p className="text-sm text-[var(--color-text-secondary)]">
                                Not enough completed runs yet to draw a trend.
                            </p>
                        )}
                    </div>
                </div>

                <div className="card">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">Rule pipeline</h2>
                    </div>
                    <div className="card-body space-y-3 text-sm">
                        {[
                            ['Active', dashboard.rules?.active ?? 0, 'text-[var(--color-success)]'],
                            ['Pending approval', dashboard.rules?.pending_approval ?? 0, 'text-[var(--color-warning)]'],
                            ['Draft', dashboard.rules?.draft ?? 0, 'text-[var(--color-text-secondary)]'],
                            ['Paused', dashboard.rules?.paused ?? 0, 'text-[var(--color-text-secondary)]'],
                        ].map(([label, value, className]) => (
                            <div key={label} className="flex items-center justify-between">
                                <span className="text-[var(--color-text-secondary)]">{label}</span>
                                <span className={`font-semibold tabular-nums ${className}`}>{value}</span>
                            </div>
                        ))}
                        <hr className="border-gray-100" />
                        <div className="flex items-center justify-between">
                            <span className="text-[var(--color-text-secondary)]">Open SoD conflicts</span>
                            <Link href={route('sod.violations')} className="font-semibold text-[var(--color-primary)] hover:underline">
                                {dashboard.sod?.open ?? 0}
                            </Link>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-[var(--color-text-secondary)]">Accepted (time-boxed)</span>
                            <span className="font-semibold tabular-nums">{dashboard.sod?.accepted ?? 0}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div className="card">
                    <div className="card-header flex items-center justify-between">
                        <h2 className="text-sm font-semibold">Source health</h2>
                        {can.manage_sources && (
                            <Link href={route('admin.data-sources.index')} className="text-xs font-semibold text-[var(--color-primary)] hover:underline">
                                Manage
                            </Link>
                        )}
                    </div>
                    <div className="overflow-x-auto">
                        {sources.length === 0 ? (
                            <EmptyState title="No data sources registered yet." />
                        ) : (
                            <table className="data-table">
                                <thead>
                                    <tr>
                                        <th>Source</th>
                                        <th>Health</th>
                                        <th>Last sync</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {sources.map((source) => (
                                        <tr key={source.id}>
                                            <td>
                                                <p className="font-medium">{source.name}</p>
                                                <p className="text-xs text-gray-400">{source.vendor_key}</p>
                                            </td>
                                            <td>
                                                <span className={`badge ${HEALTH_CLASSES[source.health_status] ?? 'badge-status-draft'}`}>
                                                    {source.health_status}
                                                </span>
                                                {source.paused_at && (
                                                    <p className="mt-1 text-xs text-[var(--color-error)]">
                                                        Paused after {source.consecutive_failures} failure(s)
                                                    </p>
                                                )}
                                            </td>
                                            <td className="text-xs text-[var(--color-text-secondary)]">
                                                {source.last_sync_at ? formatDateTime(source.last_sync_at) : 'Never'}
                                                <br />
                                                {source.last_sync_status}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                </div>

                <div className="card">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">Top failing rules, last 30 days</h2>
                    </div>
                    <div className="overflow-x-auto">
                        {(dashboard.top_failing_rules ?? []).length === 0 ? (
                            <EmptyState title="No failures recorded in the period." />
                        ) : (
                            <table className="data-table">
                                <thead>
                                    <tr>
                                        <th>Rule</th>
                                        <th>Severity</th>
                                        <th className="text-right">Failures</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {(dashboard.top_failing_rules ?? []).map((rule) => (
                                        <tr key={rule.id}>
                                            <td>
                                                <Link
                                                    href={route('monitoring-rules.show', rule.id)}
                                                    className="font-medium text-[var(--color-primary)] hover:underline"
                                                >
                                                    {rule.name}
                                                </Link>
                                                <p className="font-mono text-xs text-gray-400">
                                                    {rule.rule_ref} · {rule.runs} run(s)
                                                </p>
                                            </td>
                                            <td>
                                                <span className={`badge ${SEVERITY_CLASSES[rule.severity] ?? 'badge-low'}`}>
                                                    {rule.severity}
                                                </span>
                                            </td>
                                            <td className="text-right font-semibold tabular-nums">{formatNumber(rule.failures)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                </div>
            </div>

            <div className="card mt-6">
                <div className="card-header">
                    <h2 className="text-sm font-semibold">Key controls with no automated rule</h2>
                    <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                        These are still tested by a person on their stated frequency. Coverage is {coverage.percentage ?? 0}%.
                    </p>
                </div>
                <div className="card-body">
                    <ProgressBar value={coverage.percentage ?? 0} />
                    {uncoveredKeyControls.length === 0 ? (
                        <p className="mt-4 text-sm text-[var(--color-success)]">
                            Every active key control has at least one active monitoring rule.
                        </p>
                    ) : (
                        <ul className="mt-4 divide-y divide-gray-100">
                            {uncoveredKeyControls.map((control) => (
                                <li key={control.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                    <div>
                                        <Link
                                            href={route('controls.show', control.id)}
                                            className="text-sm font-medium text-[var(--color-primary)] hover:underline"
                                        >
                                            {control.title}
                                        </Link>
                                        <p className="font-mono text-xs text-gray-400">{control.control_ref}</p>
                                    </div>
                                    <StatusBadge status={control.frequency} />
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
