import PageHeader from '@/Components/PageHeader';
import ProgressBar from '@/Components/ProgressBar';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, Link, router, usePage } from '@inertiajs/react';

const SEVERITY_COLORS = {
    Critical: '#C53030',
    High: '#DD6B20',
    Medium: '#D69E2E',
    Low: '#2D7D46',
};

/** Reference-style stat card: title, big value, subtitle, soft icon box. */
function MetricCard({ title, value, subtitle, icon, iconClass, href }) {
    const content = (
        <div className="stat-card h-full">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-sm text-[var(--color-text-secondary)]">{title}</p>
                    <p className="mt-2 text-3xl font-bold text-[var(--color-text-primary)]">{value}</p>
                    {subtitle && <p className="mt-2 text-xs text-[var(--color-text-secondary)]">{subtitle}</p>}
                </div>
                <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ${iconClass}`}>
                    {icon}
                </div>
            </div>
        </div>
    );

    return href ? <Link href={href}>{content}</Link> : content;
}

/** SVG donut: segments = [{ label, value, color }]; children rendered in the centre. */
function Donut({ segments, size = 180, stroke = 30, children }) {
    const radius = (size - stroke) / 2;
    const circumference = 2 * Math.PI * radius;
    const total = segments.reduce((sum, s) => sum + s.value, 0);

    let cumulative = 0;
    const arcs = segments
        .filter((s) => s.value > 0)
        .map((s) => {
            const length = (s.value / total) * circumference;
            const arc = { ...s, length, offset: cumulative };
            cumulative += length;
            return arc;
        });

    return (
        <div className="relative" style={{ width: size, height: size }}>
            <svg width={size} height={size} className="-rotate-90">
                <circle
                    cx={size / 2} cy={size / 2} r={radius}
                    fill="none" stroke="#E5E7EB" strokeWidth={stroke}
                />
                {arcs.map((arc) => (
                    <circle
                        key={arc.label}
                        cx={size / 2} cy={size / 2} r={radius}
                        fill="none" stroke={arc.color} strokeWidth={stroke}
                        strokeDasharray={`${arc.length} ${circumference - arc.length}`}
                        strokeDashoffset={-arc.offset}
                    />
                ))}
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center">
                {children}
            </div>
        </div>
    );
}

const ICONS = {
    clipboard: (
        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M9 5h6a2 2 0 012 2v12a2 2 0 01-2 2H9a2 2 0 01-2-2V7a2 2 0 012-2zm0 0V4a1 1 0 011-1h4a1 1 0 011 1v1m-5 6h4m-4 4h4" />
        </svg>
    ),
    warning: (
        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v4m0 4h.01M10.29 3.86l-8.4 14.53A2 2 0 003.6 21.5h16.8a2 2 0 001.71-3.11l-8.4-14.53a2 2 0 00-3.42 0z" />
        </svg>
    ),
    clock: (
        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
    ),
    chat: (
        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M8 12h8m-8 4h5M21 12a9 9 0 11-18 0 9 9 0 0118 0zM7 20l-2 2v-4" />
        </svg>
    ),
};

export default function Dashboard({ metrics, filters = {}, units = [] }) {
    const { auth } = usePage().props;
    const { exceptions, testing, universe, recentTests = [], upcomingTests = [] } = metrics;

    const overdueItems = exceptions.overdue + testing.overdue;
    const planned = testing.total;
    const severitySegments = Object.entries(exceptions.bySeverity).map(([label, value]) => ({
        label, value, color: SEVERITY_COLORS[label],
    }));
    const severityTotal = severitySegments.reduce((sum, s) => sum + s.value, 0);
    const planPct = Math.round(testing.completionRate);
    const planYear = new Date().getFullYear();

    return (
        <AuthenticatedLayout header="Dashboard">
            <Head title="Dashboard" />

            <PageHeader
                title="Dashboard"
                subtitle="Internal Control Management Overview"
                actions={
                    <>
                        <a
                            href={route('reports.board-pack', { sections: ['dashboard', 'exceptions', 'effectiveness', 'compensating'] })}
                            className="btn-secondary"
                        >
                            Board pack (PDF)
                        </a>
                        <select
                            className="form-select w-44"
                            value={filters.unit_id ?? ''}
                            onChange={(e) => router.get(route('dashboard'), e.target.value ? { unit_id: e.target.value } : {}, { preserveState: true })}
                        >
                            <option value="">All units</option>
                            {units.map((unit) => (
                                <option key={unit.id} value={unit.id}>{unit.name}</option>
                            ))}
                        </select>
                        {auth.permissions.includes('create controls') && (
                            <Link href={route('controls.create')} className="btn-primary">
                                + Add Control
                            </Link>
                        )}
                    </>
                }
            />

            {/* Headline metrics */}
            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard
                    title="Active Tests"
                    value={testing.inProgress}
                    subtitle="In progress test runs"
                    icon={ICONS.clipboard}
                    iconClass="bg-blue-50 text-blue-600"
                    href={route('test-instances.index')}
                />
                <MetricCard
                    title="Open Exceptions"
                    value={exceptions.open}
                    subtitle={`${exceptions.bySeverity.Critical} critical, ${exceptions.bySeverity.High} high`}
                    icon={ICONS.warning}
                    iconClass="bg-red-50 text-red-600"
                    href={route('exceptions.index', { status: 'open' })}
                />
                <MetricCard
                    title="Overdue Items"
                    value={overdueItems}
                    subtitle={`${exceptions.overdue} exceptions, ${testing.overdue} tests`}
                    icon={ICONS.clock}
                    iconClass="bg-amber-50 text-amber-600"
                    href={route('exceptions.index', { status: 'open', overdue: 1 })}
                />
                <MetricCard
                    title="Pending Verification"
                    value={exceptions.pendingVerification}
                    subtitle="Awaiting closure verification"
                    icon={ICONS.chat}
                    iconClass="bg-purple-50 text-purple-600"
                    href={route('exceptions.index')}
                />
            </div>

            {/* Middle row: plan progress, severity donut, risk universe */}
            <div className="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold">Testing Plan Progress</h3>
                        <span className="text-sm text-gray-400">{planYear}</span>
                    </div>
                    <div className="card-body flex items-center gap-8">
                        <Donut
                            size={150} stroke={12}
                            segments={[
                                { label: 'done', value: planPct, color: 'var(--color-success, #2D7D46)' },
                                { label: 'rest', value: Math.max(100 - planPct, 0), color: 'transparent' },
                            ]}
                        >
                            <span className="text-2xl font-bold text-[var(--color-text-primary)]">{planPct}%</span>
                        </Donut>
                        <div className="flex-1 space-y-3 text-sm">
                            <div className="flex items-center justify-between">
                                <span className="text-gray-600">Planned</span>
                                <span className="font-semibold text-[var(--color-text-primary)]">{planned}</span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-gray-600">Completed</span>
                                <span className="font-semibold text-[var(--color-success)]">{testing.completed}</span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-gray-600">In Progress</span>
                                <span className="font-semibold text-blue-600">{testing.inProgress}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold">Exceptions by Severity</h3>
                    </div>
                    <div className="card-body flex items-center justify-center gap-8">
                        <Donut size={170} stroke={34} segments={severitySegments}>
                            <span className="text-3xl font-bold text-[var(--color-text-primary)]">{severityTotal}</span>
                            <span className="text-xs text-gray-400">Total</span>
                        </Donut>
                        <div className="space-y-2.5 text-sm">
                            {severitySegments.map((segment) => (
                                <Link
                                    key={segment.label}
                                    href={route('exceptions.index', { status: 'open', severity: segment.label })}
                                    className="flex items-center gap-2 hover:underline"
                                >
                                    <span className="h-2.5 w-2.5 rounded-full" style={{ background: segment.color }} />
                                    <span className="text-gray-600">{segment.label}</span>
                                    <span className="ms-2 font-semibold text-[var(--color-text-primary)]">{segment.value}</span>
                                </Link>
                            ))}
                        </div>
                    </div>
                </div>

                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold">Risk Universe</h3>
                        <Link href={route('risks.index')} className="text-sm text-[var(--color-primary)] hover:underline">
                            View All
                        </Link>
                    </div>
                    <div className="card-body space-y-5">
                        <div className="flex items-center justify-between">
                            <span className="text-sm text-gray-600">Total Risks</span>
                            <span className="text-2xl font-bold text-[var(--color-text-primary)]">{universe.totalRisks}</span>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-sm text-gray-600">High Risk</span>
                            <span className="text-2xl font-bold text-[var(--color-warning)]">{universe.highRisks}</span>
                        </div>
                        <div>
                            <div className="mb-1 flex items-center justify-between text-sm">
                                <span className="text-gray-600">Control Coverage</span>
                                <span className="font-semibold text-[var(--color-text-primary)]">{universe.coveragePct}%</span>
                            </div>
                            <ProgressBar value={universe.coveragePct} color="#2D7D46" />
                        </div>
                    </div>
                </div>
            </div>

            {/* Bottom row: recent activity + upcoming tests */}
            <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold">Recent Test Activity</h3>
                        <Link href={route('test-instances.index')} className="text-sm text-[var(--color-primary)] hover:underline">
                            View All
                        </Link>
                    </div>
                    {recentTests.length ? (
                        <div className="overflow-x-auto">
                            <table className="data-table">
                                <thead>
                                    <tr>
                                        <th>Test</th>
                                        <th>Period</th>
                                        <th>Status</th>
                                        <th>Tester</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {recentTests.map((test) => (
                                        <tr key={test.id} className="cursor-pointer" onClick={() => router.visit(route('test-instances.show', test.id))}>
                                            <td>
                                                <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{test.reference}</span>
                                                <p className="max-w-[170px] truncate font-medium text-[var(--color-text-primary)]">{test.control?.title}</p>
                                            </td>
                                            <td>{test.period_label}</td>
                                            <td><StatusBadge status={test.status} /></td>
                                            <td>{test.tester?.name ?? 'Unassigned'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="card-body"><p className="text-sm text-gray-400">No test activity yet.</p></div>
                    )}
                </div>

                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold">Upcoming Tests</h3>
                        <Link href={route('test-instances.index', { status: 'Scheduled' })} className="text-sm text-[var(--color-primary)] hover:underline">
                            View Plan
                        </Link>
                    </div>
                    {upcomingTests.length ? (
                        <div className="overflow-x-auto">
                            <table className="data-table">
                                <thead>
                                    <tr>
                                        <th>Test</th>
                                        <th>Period</th>
                                        <th>Due Date</th>
                                        <th>Tester</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {upcomingTests.map((test) => (
                                        <tr key={test.id} className="cursor-pointer" onClick={() => router.visit(route('test-instances.show', test.id))}>
                                            <td>
                                                <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{test.reference}</span>
                                                <p className="max-w-[170px] truncate font-medium text-[var(--color-text-primary)]">{test.control?.title}</p>
                                            </td>
                                            <td>{test.period_label}</td>
                                            <td>{formatDate(test.due_date)}</td>
                                            <td>{test.tester?.name ?? 'Unassigned'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="card-body"><p className="text-sm text-gray-400">No scheduled tests.</p></div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
