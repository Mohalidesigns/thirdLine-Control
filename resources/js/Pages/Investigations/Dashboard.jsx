import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatCurrency, formatDateTime, formatNumber } from '@/utils';
import { Head, Link, router } from '@inertiajs/react';
import { Activity, AlertTriangle, Archive, BarChart3, CheckCircle2, Clock, Gavel, PauseCircle } from 'lucide-react';

const humanise = (value) => (value ? String(value).replace(/_/g, ' ') : '—');

const PERIODS = [
    ['current_quarter', 'This quarter'],
    ['current_month', 'This month'],
    ['current_year', 'This year'],
    ['last_12_months', 'Last 12 months'],
];

/**
 * CR-04 — the caseload dashboard.
 *
 * The Executive Viewer holds this page and nothing else in the module:
 * every figure here is computed over the reader's own visibility, and no
 * widget carries a subject name, staff ID or account number. "Top cases by
 * loss" is references and titles, for cases the reader could already open.
 */
export default function Dashboard({ data = {}, filters = {}, can = {} }) {
    const kpis = data.kpis ?? {};
    const financials = data.financials ?? {};
    const consequences = data.consequences ?? {};
    const ageing = data.ageing ?? {};
    const currency = financials.top_cases?.[0]?.currency ?? 'NGN';

    const setPeriod = (period) => router.get(route('investigations.dashboard'), { period }, { preserveState: true, replace: true });

    // Spec §4 — the timeline's own controls. They carry the current period
    // with them, so paging the feed never silently widens the dashboard.
    const activity = data.activity ?? {};
    const goActivity = (changes) =>
        router.get(
            route('investigations.dashboard'),
            { ...filters, ...changes },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <AuthenticatedLayout header="Investigations">
            <Head title="Investigation dashboard" />

            <PageHeader
                title="Investigation dashboard"
                subtitle={`The caseload for ${data.period?.label ?? 'the current period'} — counts, losses, recoveries and consequences`}
                icon={BarChart3}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <select
                            className="form-select"
                            value={filters.period ?? 'current_quarter'}
                            onChange={(e) => setPeriod(e.target.value)}
                            aria-label="Period"
                        >
                            {PERIODS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                        </select>
                        {can.export && (
                            <a href={route('investigations.dashboard.export', filters)} className="btn-secondary">Export CSV</a>
                        )}
                        {can.view_register && (
                            <Link href={route('investigations.index')} className="btn-primary">
                                <Gavel className="me-1.5 h-4 w-4" aria-hidden="true" />
                                Register
                            </Link>
                        )}
                    </div>
                }
            />

            {/* Spec §4 — the six the specification asks for, in its order.
                "Outstanding" excludes drafts and "Past target" includes
                them; the two are deliberately not the same population. */}
            <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <Kpi icon={Gavel} title="Total cases" kpi={kpis.opened} tone="blue" />
                <Kpi icon={CheckCircle2} title="Completed" kpi={kpis.completed} tone="emerald" />
                <Kpi icon={Clock} title="Outstanding" kpi={kpis.outstanding} tone="amber" />
                <Kpi icon={AlertTriangle} title="Overdue" kpi={kpis.overdue} tone="red" />
                <Kpi icon={Activity} title="Avg days to close" kpi={kpis.average_days_to_close} tone="violet" />
                <Kpi icon={Archive} title="Archived" kpi={kpis.archived} tone="slate" />
            </div>

            {/* Two this product keeps that the specification has no place
                for: drafts are real work-in-progress, and a suspended case
                is neither outstanding nor finished. */}
            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-2">
                <Kpi icon={Clock} title="Open now (drafts included)" kpi={kpis.open_now} tone="slate" />
                <Kpi icon={PauseCircle} title="Suspended" kpi={kpis.suspended} tone="slate" />
            </div>

            <div className="mb-6 grid grid-cols-1 gap-5 lg:grid-cols-3">
                <div className="card lg:col-span-2">
                    <div className="card-header"><h3 className="text-sm font-semibold">Opened vs completed, last 12 months</h3></div>
                    <div className="card-body">
                        <MonthlyBars trend={data.trend ?? []} />
                    </div>
                </div>

                <div className="card">
                    <div className="card-header"><h3 className="text-sm font-semibold">Risk rating</h3></div>
                    <div className="card-body space-y-2">
                        {(data.risk_distribution ?? []).map((row) => (
                            <div key={row.rating} className="flex items-center justify-between text-sm">
                                <StatusBadge status={row.rating} />
                                <span className="font-semibold tabular-nums">{formatNumber(row.total)}</span>
                            </div>
                        ))}
                        <p className="pt-2 text-xs text-[var(--color-text-secondary)]">
                            An investigation has no rating until it is completed — that is what makes completion mean something.
                        </p>
                    </div>
                </div>
            </div>

            <div className="mb-6 grid grid-cols-1 gap-5 lg:grid-cols-2">
                <div className="card">
                    <div className="card-header"><h3 className="text-sm font-semibold">Financial position</h3></div>
                    <div className="card-body space-y-3">
                        <div className="grid grid-cols-2 gap-3 text-sm">
                            {/* Spec §4 — exposure first: what the cases were
                                opened fearing, against what they established. */}
                            <Figure label="Estimated exposure" value={formatCurrency(financials.estimated_exposure?.value ?? 0, currency)} />
                            <Figure label="Confirmed loss" value={formatCurrency(financials.confirmed_loss?.value ?? 0, currency)} />
                            <Figure label="Recovered" value={formatCurrency(financials.recovered?.value ?? 0, currency)} />
                            <Figure label="Net loss" value={formatCurrency(financials.net_loss?.value ?? 0, currency)} />
                            <Figure label="Recovery rate" value={financials.recovery_rate?.value == null ? '—' : `${financials.recovery_rate.value}%`} />
                        </div>
                        <DataTable
                            columns={[
                                { field: 'category', label: 'Category', render: (row) => humanise(row.category) },
                                { field: 'total', label: 'Cases', width: '5rem' },
                                { field: 'loss', label: 'Loss', width: '9rem', render: (row) => formatCurrency(row.loss, currency) },
                                { field: 'recovered', label: 'Recovered', width: '9rem', render: (row) => formatCurrency(row.recovered, currency) },
                            ]}
                            data={financials.by_category ?? []}
                            emptyMessage="No loss recorded in this period."
                        />
                    </div>
                </div>

                <div className="card">
                    <div className="card-header"><h3 className="text-sm font-semibold">Largest losses</h3></div>
                    <DataTable
                        columns={[
                            { field: 'reference', label: 'Ref', width: '9rem', render: (row) => <span className="font-mono text-xs">{row.reference}</span> },
                            { field: 'title', label: 'Investigation' },
                            { field: 'loss', label: 'Loss', width: '9rem', render: (row) => formatCurrency(row.loss, row.currency) },
                            { field: 'recovered', label: 'Recovered', width: '9rem', render: (row) => formatCurrency(row.recovered, row.currency) },
                        ]}
                        data={financials.top_cases ?? []}
                        emptyMessage="No confirmed loss in this period."
                    />
                    <div className="border-t border-gray-100 px-5 py-3 text-xs text-[var(--color-text-secondary)]">
                        References and titles only. No subject name, staff ID or account number reaches this page.
                    </div>
                </div>
            </div>

            <div className="mb-6 grid grid-cols-1 gap-5 lg:grid-cols-3">
                <div className="card">
                    <div className="card-header"><h3 className="text-sm font-semibold">Consequences by type</h3></div>
                    <div className="card-body space-y-2">
                        {Object.entries(consequences.by_type ?? {}).length === 0
                            ? <EmptyState title="No consequence recommended yet." />
                            : Object.entries(consequences.by_type ?? {}).map(([type, total]) => (
                                <div key={type} className="flex items-center justify-between text-sm">
                                    <span className="capitalize">{humanise(type)}</span>
                                    <span className="font-semibold tabular-nums">{formatNumber(total)}</span>
                                </div>
                            ))}
                    </div>
                </div>

                <div className="card">
                    <div className="card-header"><h3 className="text-sm font-semibold">Consequence progress</h3></div>
                    <div className="card-body space-y-2">
                        {Object.entries(consequences.by_status ?? {}).map(([status, total]) => (
                            <div key={status} className="flex items-center justify-between text-sm">
                                <StatusBadge status={humanise(status)} />
                                <span className="font-semibold tabular-nums">{formatNumber(total)}</span>
                            </div>
                        ))}
                        <div className="flex items-center justify-between border-t border-gray-100 pt-2 text-sm">
                            <span>Implementation rate</span>
                            <span className="font-semibold tabular-nums">
                                {consequences.implementation_rate == null ? '—' : `${consequences.implementation_rate}%`}
                            </span>
                        </div>
                        <div className="flex items-center justify-between text-sm">
                            <span>Overdue</span>
                            <span className="font-semibold tabular-nums text-[var(--color-error)]">{formatNumber(consequences.overdue ?? 0)}</span>
                        </div>
                    </div>
                </div>

                <div className="card">
                    <div className="card-header"><h3 className="text-sm font-semibold">Subject outcomes</h3></div>
                    <div className="card-body space-y-2">
                        {Object.entries(consequences.subject_outcomes ?? {}).length === 0
                            ? <EmptyState title="No subject named yet." />
                            : Object.entries(consequences.subject_outcomes ?? {}).map(([outcome, total]) => (
                                <div key={outcome} className="flex items-center justify-between text-sm">
                                    <span className="capitalize">{humanise(outcome)}</span>
                                    <span className="font-semibold tabular-nums">{formatNumber(total)}</span>
                                </div>
                            ))}
                        <p className="pt-2 text-xs text-[var(--color-text-secondary)]">Outcomes, counted — never the people they belong to.</p>
                    </div>
                </div>
            </div>

            <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                <div className="card">
                    <div className="card-header"><h3 className="text-sm font-semibold">By desk and branch</h3></div>
                    <DataTable
                        columns={[
                            { field: 'name', label: 'Control entity' },
                            { field: 'kind', label: 'Kind', width: '8rem', render: (row) => humanise(row.kind) },
                            { field: 'total', label: 'Cases', width: '6rem' },
                            { field: 'loss', label: 'Loss', width: '9rem', render: (row) => formatCurrency(row.loss, currency) },
                        ]}
                        data={data.by_control_entity ?? []}
                        emptyMessage="No investigation is attached to a desk or branch in this period."
                    />
                </div>

                <div className="card">
                    <div className="card-header"><h3 className="text-sm font-semibold">Ageing</h3></div>
                    <div className="card-body space-y-2">
                        {/* Spec §4 — the empty state, not a column of zeros. */}
                        {(ageing.buckets ?? []).every((b) => !b.total) && (
                            <p className="py-4 text-center text-sm text-[var(--color-text-secondary)]">No open cases</p>
                        )}
                        {(ageing.buckets ?? []).some((b) => b.total > 0) &&
                            (ageing.buckets ?? []).map((bucket) => (
                            <div key={bucket.bucket} className="flex items-center justify-between text-sm">
                                <span>{bucket.bucket === 'Suspended' ? 'Suspended (clock stopped)' : `${bucket.bucket} days`}</span>
                                <span className="font-semibold tabular-nums">{formatNumber(bucket.total)}</span>
                            </div>
                            ))}
                        <div className="flex items-center justify-between border-t border-gray-100 pt-2 text-sm">
                            <span>Past target completion date</span>
                            <span className="font-semibold tabular-nums text-[var(--color-error)]">{formatNumber(ageing.sla_breaches ?? 0)}</span>
                        </div>
                        <p className="pt-1 text-xs text-[var(--color-text-secondary)]">
                            A suspended case gets its own bucket and is left out of the average: six months waiting on a
                            police report is not six months of nobody working.
                        </p>
                    </div>
                </div>
            </div>

            <div className="card mt-6">
                <div className="card-header flex flex-wrap items-center justify-between gap-3">
                    <h3 className="text-sm font-semibold">
                        Activity timeline
                        <span className="ms-2 text-xs font-normal text-[var(--color-text-secondary)]">
                            everything within {data.period?.label ?? 'the period'}
                        </span>
                    </h3>
                    <select
                        className="rounded-md border-gray-300 text-xs"
                        value={activity.activity_type ?? ''}
                        onChange={(e) => goActivity({ activity_type: e.target.value || undefined, activity_page: 1 })}
                        aria-label="Filter by activity type"
                    >
                        <option value="">All activity types</option>
                        {(activity.types ?? []).map((t) => (
                            <option key={t} value={t} className="capitalize">
                                {humanise(t)}
                            </option>
                        ))}
                    </select>
                </div>
                <DataTable
                    columns={[
                        { field: 'activity_date', label: 'When', width: '13rem', render: (row) => formatDateTime(row.activity_date) },
                        { field: 'reference', label: 'Case', width: '9rem', render: (row) => <span className="font-mono text-xs">{row.reference}</span> },
                        { field: 'activity_type', label: 'What happened', render: (row) => humanise(row.activity_type) },
                        { field: 'performed_by', label: 'By', width: '11rem', render: (row) => row.performed_by ?? 'System' },
                    ]}
                    data={activity.rows ?? []}
                    emptyMessage="Nothing happened in this period."
                />

                {(activity.pages ?? 1) > 1 && (
                    <div className="flex items-center justify-between border-t border-gray-100 px-5 py-3 text-xs">
                        <span className="text-[var(--color-text-secondary)]">
                            {activity.total} events · page {activity.page} of {activity.pages}
                        </span>
                        <div className="flex items-center gap-1">
                            <button
                                type="button"
                                className="rounded px-2 py-1 disabled:opacity-40"
                                disabled={activity.page <= 1}
                                onClick={() => goActivity({ activity_page: activity.page - 1 })}
                            >
                                « Previous
                            </button>
                            {Array.from({ length: activity.pages }, (_, i) => i + 1).map((n) => (
                                <button
                                    key={n}
                                    type="button"
                                    className={
                                        n === activity.page
                                            ? 'rounded bg-[var(--color-primary)] px-2 py-1 font-semibold text-white'
                                            : 'rounded px-2 py-1 hover:bg-gray-100'
                                    }
                                    onClick={() => goActivity({ activity_page: n })}
                                >
                                    {n}
                                </button>
                            ))}
                            <button
                                type="button"
                                className="rounded px-2 py-1 disabled:opacity-40"
                                disabled={activity.page >= activity.pages}
                                onClick={() => goActivity({ activity_page: activity.page + 1 })}
                            >
                                Next »
                            </button>
                        </div>
                    </div>
                )}

                <div className="border-t border-gray-100 px-5 py-3 text-xs text-[var(--color-text-secondary)]">
                    The feed carries the kind of event and the case it happened on, never the diary line itself — a
                    diary line is free text and free text can name a person.
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Kpi({ icon, title, kpi = {}, tone }) {
    const change = kpi?.change;
    const subtitle = change == null
        ? undefined
        : `${change >= 0 ? '+' : ''}${change}% vs previous period`;

    return <StatCard icon={icon} title={title} value={kpi?.value ?? 0} subtitle={subtitle} tone={tone} />;
}

function Figure({ label, value }) {
    return (
        <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">{label}</p>
            <p className="mt-1 text-lg font-semibold tabular-nums">{value}</p>
        </div>
    );
}

/**
 * A deliberately plain twelve-month bar pair. The shared TrendChart draws
 * one series against thresholds; this needs two counts side by side, and a
 * table underneath would say the same thing more slowly.
 */
function MonthlyBars({ trend }) {
    if (trend.length === 0) {
        return <EmptyState title="No investigation activity in the last twelve months." />;
    }

    const max = Math.max(1, ...trend.flatMap((row) => [row.opened, row.completed]));

    return (
        <div>
            <div className="flex items-end gap-2" style={{ height: 180 }}>
                {trend.map((row) => (
                    <div key={row.month} className="flex flex-1 flex-col items-center gap-1">
                        <div className="flex h-full w-full items-end justify-center gap-0.5">
                            <div
                                className="w-1/2 rounded-t bg-[var(--color-primary)]"
                                style={{ height: `${(row.opened / max) * 100}%` }}
                                title={`${row.label}: ${row.opened} opened`}
                            />
                            <div
                                className="w-1/2 rounded-t bg-emerald-500"
                                style={{ height: `${(row.completed / max) * 100}%` }}
                                title={`${row.label}: ${row.completed} completed`}
                            />
                        </div>
                        <span className="text-[10px] text-[var(--color-text-secondary)]">{row.label}</span>
                    </div>
                ))}
            </div>
            <div className="mt-3 flex items-center gap-4 text-xs text-[var(--color-text-secondary)]">
                <span className="flex items-center gap-1.5"><span className="h-2 w-2 rounded-sm bg-[var(--color-primary)]" /> Opened</span>
                <span className="flex items-center gap-1.5"><span className="h-2 w-2 rounded-sm bg-emerald-500" /> Completed</span>
            </div>
        </div>
    );
}
