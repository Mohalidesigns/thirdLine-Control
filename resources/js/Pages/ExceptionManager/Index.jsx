import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import SeverityBadge from '@/Components/SeverityBadge';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, router, usePage } from '@inertiajs/react';

const VIEWS = [
    { key: 'register', label: 'Register' },
    { key: 'scorecard', label: 'Department scorecard' },
    { key: 'ageing', label: 'Ageing' },
];

/**
 * CR1.7 — the Exception Manager cockpit. Rows are escalations, not
 * exceptions: who was issued what, and where each answer stands.
 */
export default function Index({
    escalations = { data: [] },
    filters = {},
    stats = {},
    statuses = [],
    severities = [],
    units = [],
    scorecard = null,
    ageingReport = null,
}) {
    const { auth } = usePage().props;
    const view = filters.view ?? 'register';

    const switchView = (key) => {
        router.get(route('exception-manager.index'), { ...filters, view: key === 'register' ? undefined : key }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout header="Exception Manager">
            <Head title="Exception Manager" />

            <PageHeader
                title="Exception Manager"
                subtitle="Exceptions issued to departments — response, review and closure, round by round"
                actions={
                    auth.permissions.includes('export reports') && (
                        <>
                            <a href={route('reports.exception-manager.xlsx', filters)} className="btn-secondary">Excel</a>
                            <a href={route('reports.exception-manager.pdf', filters)} className="btn-secondary">PDF</a>
                        </>
                    )
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <StatCard title="Issued" value={stats.issued ?? 0} />
                <StatCard title="Awaiting response" value={stats.awaitingResponse ?? 0} color="info" />
                <StatCard title="Overdue" value={stats.overdue ?? 0} color="danger" prominent={stats.overdue > 0} />
                <StatCard title="Awaiting review" value={stats.awaitingReview ?? 0} color="warning" />
                <StatCard title="Closed this period" value={stats.closedThisPeriod ?? 0} color="success" />
                <StatCard title="Avg response days" value={stats.averageResponseDays ?? '—'} />
            </div>

            <div className="mb-4 flex gap-1 rounded-lg bg-gray-100 p-1 w-fit">
                {VIEWS.map((v) => (
                    <button
                        key={v.key}
                        type="button"
                        onClick={() => switchView(v.key)}
                        className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                            view === v.key ? 'bg-white shadow-sm text-[var(--color-primary)]' : 'text-gray-500 hover:text-gray-700'
                        }`}
                    >
                        {v.label}
                    </button>
                ))}
            </div>

            {view === 'register' && (
                <>
                    <FilterBar
                        route="exception-manager.index"
                        resource="exception-manager"
                        currentFilters={filters}
                        filters={[
                            { name: 'search', type: 'search', label: 'Search' },
                            { name: 'unit_id', type: 'select', label: 'Department', options: units.map((u) => ({ value: u.id, label: u.name })) },
                            { name: 'severity', type: 'select', label: 'Severity', options: severities },
                            { name: 'status', type: 'select', label: 'Status', options: [{ value: 'open', label: 'All open' }, ...statuses] },
                            { name: 'ageing', type: 'select', label: 'Ageing', options: ['0-30', '31-60', '61-90', '90+'] },
                            { name: 'overdue', type: 'checkbox', label: 'Overdue only' },
                            { name: 'reissued', type: 'checkbox', label: 'Round > 1' },
                        ]}
                    />

                    <div className="card">
                        <DataTable
                            emptyMessage="No escalations issued yet."
                            onRowClick={(row) => router.get(route('exception-manager.show', row.id))}
                            columns={[
                                {
                                    field: 'reference',
                                    label: 'Ref',
                                    render: (row) => (
                                        <div>
                                            <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{row.reference}</span>
                                            {row.round_no > 1 && <span className="ms-1.5 badge badge-high">R{row.round_no}</span>}
                                        </div>
                                    ),
                                },
                                {
                                    field: 'exception',
                                    label: 'Exception',
                                    render: (row) => (
                                        <div>
                                            <p className="max-w-sm truncate font-medium text-[var(--color-text-primary)]">{row.exception?.title}</p>
                                            <p className="font-mono text-xs text-gray-400">{row.exception?.reference}</p>
                                        </div>
                                    ),
                                },
                                {
                                    field: 'unit',
                                    label: 'Department',
                                    render: (row) => row.unit?.name ?? row.process?.name ?? row.external_organisation ?? row.respondent?.name ?? '—',
                                },
                                { field: 'respondent', label: 'Respondent', render: (row) => row.respondent?.name ?? row.external_email ?? '—' },
                                { field: 'severity', label: 'Severity', render: (row) => <SeverityBadge severity={row.severity} /> },
                                {
                                    field: 'response_due_at',
                                    label: 'Response due',
                                    render: (row) => (
                                        <span className={row.is_response_late ? 'font-semibold text-[var(--color-error)]' : ''}>
                                            {formatDate(row.response_due_at)}
                                        </span>
                                    ),
                                },
                                { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
                            ]}
                            data={escalations.data}
                        />
                        <Pagination paginator={escalations} />
                    </div>
                </>
            )}

            {view === 'scorecard' && (
                <div className="card">
                    <DataTable
                        emptyMessage="Nothing issued yet — the scorecard builds itself from escalations."
                        columns={[
                            { field: 'department', label: 'Department' },
                            { field: 'issued', label: 'Issued' },
                            { field: 'acknowledged_on_time_pct', label: 'Ack on time', render: (r) => (r.acknowledged_on_time_pct !== null ? `${r.acknowledged_on_time_pct}%` : '—') },
                            { field: 'responded_on_time_pct', label: 'Responded on time', render: (r) => (r.responded_on_time_pct !== null ? `${r.responded_on_time_pct}%` : '—') },
                            { field: 'average_response_days', label: 'Avg response (d)', render: (r) => r.average_response_days ?? '—' },
                            {
                                field: 'open_overdue',
                                label: 'Open overdue',
                                render: (r) => <span className={r.open_overdue > 0 ? 'font-semibold text-[var(--color-error)]' : ''}>{r.open_overdue}</span>,
                            },
                            { field: 'closure_rate_pct', label: 'Closure rate', render: (r) => `${r.closure_rate_pct}%` },
                            { field: 'reissue_rate_pct', label: 'Re-issue rate', render: (r) => `${r.reissue_rate_pct}%` },
                        ]}
                        data={scorecard ?? []}
                    />
                </div>
            )}

            {view === 'ageing' && (
                <div className="card">
                    <DataTable
                        emptyMessage="No open escalations."
                        columns={[
                            { field: 'department', label: 'Department' },
                            { field: 'severity', label: 'Severity', render: (r) => <SeverityBadge severity={r.severity} /> },
                            { field: '0-30', label: '0–30 days' },
                            { field: '31-60', label: '31–60 days' },
                            { field: '61-90', label: '61–90 days' },
                            { field: '90+', label: '90+ days', render: (r) => <span className={r['90+'] > 0 ? 'font-semibold text-[var(--color-error)]' : ''}>{r['90+']}</span> },
                        ]}
                        data={ageingReport ?? []}
                    />
                </div>
            )}
        </AuthenticatedLayout>
    );
}
