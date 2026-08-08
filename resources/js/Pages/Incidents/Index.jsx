import Countdown from '@/Components/Countdown';
import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import SeverityBadge from '@/Components/SeverityBadge';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, Link, router, usePage } from '@inertiajs/react';

export default function Index({
    incidents,
    filters = {},
    statuses = [],
    severities = [],
    types = [],
    entities = [],
    stats = {},
    lossByBaselEventType = [],
}) {
    const { auth } = usePage().props;
    const canCreate = auth?.permissions?.includes('create incidents');

    const columns = [
        {
            field: 'incident_ref',
            label: 'Ref',
            render: (row) => (
                <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{row.incident_ref}</span>
            ),
        },
        {
            field: 'title',
            label: 'Incident',
            render: (row) => (
                <div className="max-w-sm">
                    <p className="font-medium text-[var(--color-text-primary)]">{row.title}</p>
                    <p className="text-xs capitalize text-gray-400">
                        {row.incident_type?.replace('_', ' ')}
                        {row.entity ? ` · ${row.entity.name}` : ''}
                        {row.near_miss ? ' · near miss' : ''}
                    </p>
                </div>
            ),
        },
        { field: 'severity', label: 'Severity', render: (row) => <SeverityBadge severity={row.severity} /> },
        {
            field: 'occurred_at',
            label: 'Occurred',
            render: (row) => <span className="text-xs">{formatDate(row.occurred_at)}</span>,
        },
        {
            field: 'net_loss',
            label: 'Net loss',
            render: (row) =>
                row.net_loss?.formatted && row.net_loss.amount !== 0 ? (
                    <span className="tabular-nums">{row.net_loss.formatted}</span>
                ) : (
                    <span className="text-gray-400">—</span>
                ),
        },
        {
            field: 'notification_due_at',
            label: 'Notification',
            render: (row) =>
                row.outstanding_notifications_count > 0 ? (
                    <Countdown dueAt={row.notification_due_at} compact />
                ) : row.is_reportable ? (
                    <span className="badge badge-status-completed">Settled</span>
                ) : (
                    <span className="text-gray-400">—</span>
                ),
        },
        {
            field: 'open_actions_count',
            label: 'Actions',
            render: (row) =>
                row.open_actions_count > 0 ? (
                    <span className="badge badge-medium">{row.open_actions_count} open</span>
                ) : (
                    <span className="text-gray-400">{row.actions_count ?? 0}</span>
                ),
        },
        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
    ];

    return (
        <AuthenticatedLayout header="Incidents">
            <Head title="Incidents" />

            <PageHeader
                title="Incident Register"
                subtitle="Operational, security, fraud and data incidents — with Basel loss capture and live regulatory countdowns"
                actions={
                    canCreate && (
                        <Link href={route('incidents.create')} className="btn-primary">
                            Report an incident
                        </Link>
                    )
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
                <StatCard title="Open" value={stats.open ?? 0} color="primary" />
                <StatCard title="Near misses" value={stats.near_misses ?? 0} color="info" subtitle="leading indicator" />
                <StatCard title="Notifications due" value={stats.notification_due ?? 0} color="warning" />
                <StatCard
                    title="Windows missed"
                    value={stats.notification_breached ?? 0}
                    color="danger"
                    prominent={(stats.notification_breached ?? 0) > 0}
                />
            </div>

            {lossByBaselEventType.length > 0 && (
                <div className="card mb-6">
                    <div className="card-header">Net loss by Basel event type</div>
                    <div className="card-body">
                        <div className="overflow-x-auto">
                            <table className="data-table">
                                <thead>
                                    <tr>
                                        <th>Basel event type</th>
                                        <th>Incidents</th>
                                        <th>Net loss</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {lossByBaselEventType.map((row, index) => (
                                        <tr key={index}>
                                            <td>{row.basel_event_type}</td>
                                            <td className="tabular-nums">{row.incidents}</td>
                                            <td className="tabular-nums">{row.net_loss?.formatted}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <p className="mt-2 text-xs text-gray-400">
                            Near misses are excluded. Currencies are reported separately and never summed.
                        </p>
                    </div>
                </div>
            )}

            <FilterBar
                route="incidents.index"
                resource="incidents"
                currentFilters={filters}
                searchPlaceholder="Search incidents…"
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    { name: 'status', type: 'select', label: 'Status', options: statuses },
                    { name: 'severity', type: 'select', label: 'Severity', options: severities },
                    { name: 'incident_type', type: 'select', label: 'Type', options: types },
                    {
                        name: 'entity_id',
                        type: 'select',
                        label: 'Entity',
                        options: entities.map((e) => ({ value: e.id, label: e.name })),
                    },
                    { name: 'near_miss_only', type: 'checkbox', label: 'Near misses only' },
                    { name: 'reportable_only', type: 'checkbox', label: 'Reportable only' },
                ]}
            />

            <div className="card">
                <DataTable
                    columns={columns}
                    data={incidents.data}
                    emptyMessage="No incidents match the filters."
                    onRowClick={(row) => router.visit(route('incidents.show', row.id))}
                />
                <Pagination paginator={incidents} />
            </div>
        </AuthenticatedLayout>
    );
}
