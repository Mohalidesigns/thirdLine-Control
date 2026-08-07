import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import VerificationBadge from '@/Components/VerificationBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { daysUntil, formatDate, formatMoney } from '@/utils';
import { Head, Link, router } from '@inertiajs/react';

export default function Instances({ instances, filters = {}, statuses = [], exposure = {} }) {
    const exposureEntries = Object.entries(exposure);

    const columns = [
        {
            field: 'obligation',
            label: 'Obligation',
            render: (row) => (
                <div className="max-w-md">
                    <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">
                        {row.obligation?.obligation_ref}
                    </span>
                    <p className="truncate text-sm">{row.obligation?.title}</p>
                    <p className="text-xs text-gray-400">{row.obligation?.regulator?.name}</p>
                </div>
            ),
        },
        { field: 'entity', label: 'Entity', render: (row) => row.assignment?.entity?.name ?? '—' },
        { field: 'period_label', label: 'Period' },
        {
            field: 'due_at',
            label: 'Due',
            render: (row) => {
                const remaining = daysUntil(row.due_at);

                return (
                    <div>
                        <p className="text-sm">{formatDate(row.due_at)}</p>
                        {remaining !== null && !['Accepted', 'Waived'].includes(row.status) && (
                            <p className={`text-xs ${remaining < 0 ? 'text-[var(--color-error)]' : 'text-gray-400'}`}>
                                {remaining < 0 ? `${Math.abs(remaining)}d overdue` : `in ${remaining}d`}
                            </p>
                        )}
                    </div>
                );
            },
        },
        { field: 'owner', label: 'Owner', render: (row) => row.assignment?.owner?.name ?? '—' },
        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
        {
            field: 'penalty_exposure',
            label: 'Exposure',
            render: (row) =>
                row.penalty_exposure && row.penalty_exposure_minor > 0 ? (
                    <span className="text-[var(--color-error)]">{formatMoney(row.penalty_exposure)}</span>
                ) : (
                    '—'
                ),
        },
        {
            field: 'verification_status',
            label: 'Source',
            render: (row) => <VerificationBadge status={row.obligation?.verification_status} />,
        },
    ];

    return (
        <AuthenticatedLayout header="Filings">
            <Head title="Filings" />

            <PageHeader
                title="Filings & deadlines"
                subtitle="Every generated calendar entry, newest deadline first"
                actions={
                    <Link href={route('obligations.calendar')} className="btn-secondary">
                        Calendar view
                    </Link>
                }
            />

            {exposureEntries.length > 0 && (
                <div className="mb-4 rounded-lg border-l-4 border-l-[var(--color-error)] bg-red-50 px-4 py-3 text-sm">
                    <span className="font-semibold">Live penalty exposure: </span>
                    {exposureEntries.map(([currency, money]) => (
                        <span key={currency} className="mr-4">
                            {formatMoney(money)}
                        </span>
                    ))}
                </div>
            )}

            <FilterBar
                route="obligations.instances.index"
                resource="obligation-instances"
                currentFilters={filters}
                searchPlaceholder="Search obligations…"
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    {
                        name: 'status',
                        type: 'select',
                        label: 'Status',
                        options: statuses.map((status) => ({ value: status, label: status })),
                    },
                    {
                        name: 'mine',
                        type: 'select',
                        label: 'Ownership',
                        options: [{ value: '1', label: 'Only mine' }],
                    },
                ]}
            />

            <div className="card">
                <DataTable
                    columns={columns}
                    data={instances.data}
                    emptyMessage="No filings match the current filters."
                    onRowClick={(row) => router.visit(route('obligations.instances.show', row.id))}
                />
                <Pagination paginator={instances} />
            </div>
        </AuthenticatedLayout>
    );
}
