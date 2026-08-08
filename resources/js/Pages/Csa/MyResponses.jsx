import DataTable from '@/Components/DataTable';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';

export default function MyResponses({ responses }) {
    const columns = [
        {
            field: 'campaign',
            label: 'Campaign',
            render: (r) => (
                <div>
                    <p className="font-medium text-[var(--color-text-primary)]">{r.campaign?.name}</p>
                    <p className="font-mono text-xs text-gray-400">{r.campaign?.reference}</p>
                </div>
            ),
        },
        {
            field: 'subject',
            label: 'Covers',
            render: (r) => (r.control ? `${r.control.control_ref} — ${r.control.title}` : r.entity?.name ?? 'General'),
        },
        {
            field: 'closes_at',
            label: 'Closes',
            render: (r) => (r.campaign?.closes_at ? new Date(r.campaign.closes_at).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'),
        },
        { field: 'status', label: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    ];

    return (
        <AuthenticatedLayout header="My Responses">
            <Head title="My Responses" />
            <PageHeader
                title="My Responses"
                subtitle="Self-assessments, attestations and surveys assigned to you"
                breadcrumbs={[{ label: 'Campaigns', href: route('csa-campaigns.index') }, { label: 'My responses' }]}
            />
            <div className="card">
                <DataTable
                    columns={columns}
                    data={responses.data}
                    emptyMessage="Nothing assigned to you right now."
                    onRowClick={(row) => router.visit(route('csa-responses.show', row.id))}
                />
                <Pagination paginator={responses} />
            </div>
        </AuthenticatedLayout>
    );
}
