import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatCard from '@/Components/StatCard';
import VerificationBadge from '@/Components/VerificationBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatMoney } from '@/utils';
import { Head, Link, router } from '@inertiajs/react';

export default function Index({
    obligations,
    filters = {},
    regulators = [],
    types = [],
    frequencies = [],
    verificationStatuses = [],
    stats = {},
    exposure = {},
    can = {},
}) {
    const exposureEntries = Object.entries(exposure);

    const columns = [
        {
            field: 'obligation_ref',
            label: 'Reference',
            render: (row) => (
                <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{row.obligation_ref}</span>
            ),
        },
        {
            field: 'title',
            label: 'Obligation',
            render: (row) => (
                <div className="max-w-md">
                    <p className="font-medium text-[var(--color-text-primary)]">{row.title}</p>
                    <p className="truncate text-xs text-gray-400">{row.legal_reference ?? '—'}</p>
                </div>
            ),
        },
        { field: 'regulator', label: 'Regulator', render: (row) => row.regulator?.name ?? '—' },
        {
            field: 'obligation_type',
            label: 'Type',
            render: (row) => <span className="badge badge-status-draft">{row.obligation_type}</span>,
        },
        { field: 'frequency', label: 'Frequency', render: (row) => row.frequency.replace('_', ' ') },
        {
            field: 'penalty',
            label: 'Penalty',
            render: (row) =>
                row.penalty ? (
                    <span title={row.penalty_description ?? ''}>
                        {row.penalty_basis === 'percentage'
                            ? `${(row.penalty_amount_minor / 100).toFixed(2)}% of base`
                            : `${formatMoney(row.penalty)} ${row.penalty_basis?.replace('_', ' ')}`}
                    </span>
                ) : (
                    '—'
                ),
        },
        { field: 'assignments_count', label: 'Assigned to', render: (row) => `${row.assignments_count} entit(y/ies)` },
        {
            field: 'verification_status',
            label: 'Verification',
            render: (row) => <VerificationBadge status={row.verification_status} />,
        },
    ];

    return (
        <AuthenticatedLayout header="Obligation Register">
            <Head title="Obligation Register" />

            <PageHeader
                title="Regulatory Obligation Register"
                subtitle="Every filing, disclosure, notification and payment the organisation owes a regulator"
                actions={
                    <>
                        <Link href={route('obligations.calendar')} className="btn-secondary">
                            Compliance calendar
                        </Link>
                        {can.manage && (
                            <Link href={route('obligations.create')} className="btn-primary">
                                + Add Obligation
                            </Link>
                        )}
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-4">
                <StatCard title="Active obligations" value={stats.total ?? 0} color="primary" />
                <StatCard
                    title="Verified"
                    value={stats.verified ?? 0}
                    color="success"
                    subtitle="Usable in submissions"
                />
                <StatCard title="Assigned" value={stats.assigned ?? 0} color="info" />
                <StatCard
                    title="Overdue now"
                    value={stats.overdue ?? 0}
                    color="danger"
                    prominent={(stats.overdue ?? 0) > 0}
                    href={route('obligations.instances.index', { status: 'Overdue' })}
                />
            </div>

            {exposureEntries.length > 0 && (
                <div className="mb-6 card border-l-4 border-l-[var(--color-error)]">
                    <div className="card-body">
                        <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                            Live cost of non-compliance
                        </p>
                        <div className="mt-2 flex flex-wrap gap-6">
                            {exposureEntries.map(([currency, money]) => (
                                <p key={currency} className="text-2xl font-bold text-[var(--color-error)]">
                                    {formatMoney(money)}
                                </p>
                            ))}
                        </div>
                        <p className="mt-1 text-xs text-gray-400">
                            Accrued penalty exposure across overdue obligations, computed from each regulator’s stated
                            penalty basis.
                        </p>
                    </div>
                </div>
            )}

            <FilterBar
                route="obligations.index"
                resource="obligations"
                currentFilters={filters}
                searchPlaceholder="Search obligations…"
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    {
                        name: 'regulator_id',
                        type: 'select',
                        label: 'Regulator',
                        options: regulators.map((r) => ({ value: r.id, label: r.name })),
                    },
                    {
                        name: 'obligation_type',
                        type: 'select',
                        label: 'Type',
                        options: types.map((t) => ({ value: t, label: t })),
                    },
                    {
                        name: 'frequency',
                        type: 'select',
                        label: 'Frequency',
                        options: frequencies.map((f) => ({ value: f, label: f.replace('_', ' ') })),
                    },
                    {
                        name: 'verification_status',
                        type: 'select',
                        label: 'Verification',
                        options: verificationStatuses.map((v) => ({ value: v, label: v })),
                    },
                ]}
            />

            <div className="card">
                <DataTable
                    columns={columns}
                    data={obligations.data}
                    emptyMessage="No obligations match the current filters."
                    onRowClick={(row) => router.visit(route('obligations.show', row.id))}
                />
                <Pagination paginator={obligations} />
            </div>
        </AuthenticatedLayout>
    );
}
