import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, Link, router, usePage } from '@inertiajs/react';

export default function Index({ compensatingControls, filters = {}, statuses = [] }) {
    const { auth } = usePage().props;
    const canApprove = auth.permissions.includes('approve compensating-controls');

    return (
        <AuthenticatedLayout header="Compensating Controls">
            <Head title="Compensating Controls" />

            <PageHeader
                title="Compensating controls"
                subtitle="Alternative controls covering primary control weaknesses"
            />

            <FilterBar
                route="compensating-controls.index"
                resource="compensating-controls"
                currentFilters={filters}
                filters={[{ name: 'status', type: 'select', label: 'Status', options: statuses }]}
            />

            <div className="card">
                <DataTable
                    columns={[
                        {
                            field: 'description',
                            label: 'Compensating control',
                            render: (row) => (
                                <div>
                                    <p className="max-w-md font-medium text-[var(--color-text-primary)]">{row.description}</p>
                                    <p className="text-xs text-gray-400">
                                        {row.is_temporary ? 'Temporary' : 'Permanent'}
                                        {row.effective_to && ` · until ${formatDate(row.effective_to)}`}
                                    </p>
                                </div>
                            ),
                        },
                        {
                            field: 'primary_control',
                            label: 'Covers',
                            render: (row) =>
                                row.primary_control ? (
                                    <Link href={route('controls.show', row.primary_control.id)} className="font-mono text-xs text-[var(--color-primary)] hover:underline">
                                        {row.primary_control.control_ref}
                                    </Link>
                                ) : '—',
                        },
                        {
                            field: 'exception',
                            label: 'Exception',
                            render: (row) =>
                                row.exception ? (
                                    <Link href={route('exceptions.show', row.exception.id)} className="font-mono text-xs text-[var(--color-primary)] hover:underline">
                                        {row.exception.reference}
                                    </Link>
                                ) : '—',
                        },
                        { field: 'owner', label: 'Owner', render: (row) => row.owner?.name ?? '—' },
                        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
                        {
                            field: 'actions',
                            label: '',
                            render: (row) =>
                                canApprove && row.status === 'Proposed' ? (
                                    <PrimaryButton onClick={() => router.post(route('compensating-controls.approve', row.id), {}, { preserveScroll: true })}>
                                        Approve
                                    </PrimaryButton>
                                ) : null,
                        },
                    ]}
                    data={compensatingControls.data}
                    emptyMessage="No compensating controls registered."
                />
                <Pagination paginator={compensatingControls} />
            </div>
        </AuthenticatedLayout>
    );
}
