import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';

const TYPE_LABELS = { csa: 'Control Self-Assessment', attestation: 'Attestation', survey: 'Survey' };

export default function Index({ campaigns, filters = {}, statuses = [] }) {
    const { auth } = usePage().props;
    const canManage = auth.permissions.includes('manage campaigns');
    const activeType = filters.type ?? '';

    const columns = [
        {
            field: 'reference',
            label: 'Reference',
            render: (row) => <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{row.reference}</span>,
        },
        {
            field: 'name',
            label: 'Campaign',
            render: (row) => (
                <div className="max-w-sm">
                    <p className="font-medium text-[var(--color-text-primary)]">{row.name}</p>
                    <p className="text-xs text-gray-400">
                        {TYPE_LABELS[row.campaign_type]}
                        {row.is_anonymous ? ' · Anonymous' : ''}
                        {row.period_label ? ` · ${row.period_label}` : ''}
                    </p>
                </div>
            ),
        },
        {
            field: 'responses',
            label: 'Responses',
            render: (row) =>
                row.is_anonymous
                    ? `${row.submitted_responses_count} received`
                    : `${row.submitted_responses_count}/${row.responses_count}`,
        },
        {
            field: 'progress',
            label: 'Response rate',
            render: (row) => {
                const rate = row.responses_count > 0 ? Math.round((row.submitted_responses_count / row.responses_count) * 100) : 0;
                return row.is_anonymous ? '—' : (
                    <div className="w-24">
                        <div className="progress-bar">
                            <div className="progress-bar-fill bg-[var(--color-secondary)]" style={{ width: `${rate}%` }} />
                        </div>
                        <p className="mt-0.5 text-[10px] text-gray-400">{rate}%</p>
                    </div>
                );
            },
        },
        { field: 'closes_at', label: 'Closes', render: (row) => (row.closes_at ? new Date(row.closes_at).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—') },
        { field: 'creator', label: 'Created by', render: (row) => row.creator?.name ?? '—' },
        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
    ];

    return (
        <AuthenticatedLayout header="Campaigns">
            <Head title="Campaigns" />

            <PageHeader
                title="Assessment & Survey Campaigns"
                subtitle="Control self-assessments, attestations and surveys"
                actions={
                    <>
                        <Link href={route('csa-responses.mine')} className="btn-secondary">My responses</Link>
                        {canManage && (
                            <Link href={route('csa-campaigns.create', activeType ? { type: activeType } : {})} className="btn-primary">
                                <Plus className="h-4 w-4" strokeWidth={2} /> New campaign
                            </Link>
                        )}
                    </>
                }
            />

            <div className="mb-4 flex flex-wrap gap-2">
                {[['', 'All'], ['csa', 'CSA'], ['attestation', 'Attestations'], ['survey', 'Surveys']].map(([value, label]) => (
                    <button
                        key={value}
                        type="button"
                        onClick={() => router.get(route('csa-campaigns.index'), value ? { ...filters, type: value } : { ...filters, type: undefined }, { preserveState: true })}
                        className={`rounded-full px-4 py-1.5 text-sm font-medium transition-colors duration-200 ${
                            activeType === value
                                ? 'bg-[var(--color-primary)] text-white'
                                : 'bg-white text-[var(--color-text-secondary)] ring-1 ring-gray-200 hover:bg-gray-50'
                        }`}
                    >
                        {label}
                    </button>
                ))}
            </div>

            <FilterBar
                route="csa-campaigns.index"
                resource="csa-campaigns"
                currentFilters={filters}
                searchPlaceholder="Search campaigns…"
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    { name: 'status', type: 'select', label: 'Status', options: statuses },
                ]}
            />

            <div className="card">
                <DataTable
                    columns={columns}
                    data={campaigns.data}
                    emptyMessage="No campaigns match the current filters."
                    onRowClick={(row) => router.visit(route('csa-campaigns.show', row.id))}
                />
                <Pagination paginator={campaigns} />
            </div>
        </AuthenticatedLayout>
    );
}
