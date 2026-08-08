import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, Link, router, usePage } from '@inertiajs/react';

export default function Index({
    policies,
    filters = {},
    statuses = [],
    types = [],
    categories = [],
    owners = [],
    stats = {},
}) {
    const { auth } = usePage().props;
    const canCreate = auth?.permissions?.includes('create policies');

    const columns = [
        {
            field: 'policy_ref',
            label: 'Ref',
            render: (row) => (
                <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{row.policy_ref}</span>
            ),
        },
        {
            field: 'title',
            label: 'Policy',
            render: (row) => (
                <div className="max-w-sm">
                    <p className="font-medium text-[var(--color-text-primary)]">{row.title}</p>
                    <p className="text-xs capitalize text-gray-400">
                        {row.policy_type}
                        {row.category ? ` · ${row.category.name}` : ''}
                        {row.sections_count ? ` · ${row.sections_count} sections` : ''}
                    </p>
                </div>
            ),
        },
        { field: 'owner', label: 'Owner', render: (row) => row.owner?.name ?? '—' },
        {
            field: 'version_no',
            label: 'Version',
            render: (row) => <span className="font-mono text-xs">v{row.version_no}</span>,
        },
        {
            field: 'next_review_at',
            label: 'Next review',
            render: (row) => {
                const due = row.next_review_at && new Date(row.next_review_at) <= new Date();

                return (
                    <span className={due ? 'font-semibold text-[var(--color-error)]' : ''}>
                        {formatDate(row.next_review_at)}
                    </span>
                );
            },
        },
        {
            field: 'requires_attestation',
            label: 'Attestation',
            render: (row) =>
                row.requires_attestation ? (
                    <span className="badge badge-status-active">Required</span>
                ) : (
                    <span className="text-gray-400">—</span>
                ),
        },
        {
            field: 'open_exceptions_count',
            label: 'Waivers',
            render: (row) =>
                row.open_exceptions_count > 0 ? (
                    <span className="badge badge-medium">{row.open_exceptions_count}</span>
                ) : (
                    <span className="text-gray-400">—</span>
                ),
        },
        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
    ];

    return (
        <AuthenticatedLayout header="Policies">
            <Head title="Policies" />

            <PageHeader
                title="Policy Library"
                subtitle="Policies, procedures, standards and charters — with attestation, waivers and scheduled review"
                actions={
                    <>
                        <Link href={route('policies.gaps')} className="btn-secondary">
                            Gap analysis
                        </Link>
                        <Link href={route('policy-exceptions.index')} className="btn-secondary">
                            Waivers
                        </Link>
                        {canCreate && (
                            <Link href={route('policies.create')} className="btn-primary">
                                New policy
                            </Link>
                        )}
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
                <StatCard title="Published" value={stats.published ?? 0} color="success" />
                <StatCard title="In draft" value={stats.in_draft ?? 0} color="info" />
                <StatCard title="Awaiting approval" value={stats.awaiting_approval ?? 0} color="warning" />
                <StatCard
                    title="Review due"
                    value={stats.review_due ?? 0}
                    color="danger"
                    subtitle="within 30 days"
                    prominent={(stats.review_due ?? 0) > 0}
                />
            </div>

            <FilterBar
                route="policies.index"
                resource="policies"
                currentFilters={filters}
                searchPlaceholder="Search policies…"
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    { name: 'status', type: 'select', label: 'Status', options: statuses },
                    { name: 'policy_type', type: 'select', label: 'Type', options: types },
                    {
                        name: 'category_id',
                        type: 'select',
                        label: 'Category',
                        options: categories.map((c) => ({ value: c.id, label: c.name })),
                    },
                    {
                        name: 'owner_id',
                        type: 'select',
                        label: 'Owner',
                        options: owners.map((o) => ({ value: o.id, label: o.name })),
                    },
                    { name: 'review_due', type: 'checkbox', label: 'Review due' },
                ]}
            />

            <div className="card">
                <DataTable
                    columns={columns}
                    data={policies.data}
                    emptyMessage="No policies match the filters."
                    onRowClick={(row) => router.visit(route('policies.show', row.id))}
                />
                <Pagination paginator={policies} />
            </div>
        </AuthenticatedLayout>
    );
}
