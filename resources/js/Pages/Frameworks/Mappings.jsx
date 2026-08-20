import DangerButton from '@/Components/DangerButton';
import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import SecondaryButton from '@/Components/SecondaryButton';
import StatCard from '@/Components/StatCard';
import TextArea from '@/Components/TextArea';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDateTime } from '@/utils';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { CheckCircle2, Clock } from 'lucide-react';

export default function Mappings({ mappings, filters = {}, stats = {}, can = {} }) {
    const [rejecting, setRejecting] = useState(null);
    const [reason, setReason] = useState('');

    const approve = (row) =>
        router.post(route('control-mappings.approve', row.id), {}, { preserveScroll: true });

    const reject = () => {
        router.post(
            route('control-mappings.reject', rejecting.id),
            { reason },
            {
                preserveScroll: true,
                onFinish: () => {
                    setRejecting(null);
                    setReason('');
                },
            },
        );
    };

    const columns = [
        {
            field: 'control',
            label: 'Control',
            render: (row) => (
                <Link href={route('controls.show', row.control?.id)} className="min-w-0">
                    <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">
                        {row.control?.control_ref}
                    </span>
                    <p className="truncate text-sm">{row.control?.title}</p>
                </Link>
            ),
        },
        {
            field: 'requirement',
            label: 'Requirement',
            render: (row) => (
                <div className="min-w-0">
                    <span className="font-mono text-xs font-semibold">{row.requirement?.ref_code}</span>
                    <p className="truncate text-sm">{row.requirement?.title}</p>
                    <p className="text-xs text-gray-400">{row.requirement?.framework?.code}</p>
                </div>
            ),
        },
        {
            field: 'coverage',
            label: 'Coverage',
            render: (row) => <span className="badge badge-status-draft">{row.coverage}</span>,
        },
        { field: 'mapper', label: 'Mapped by', render: (row) => row.mapper?.name ?? '—' },
        { field: 'mapped_at', label: 'Mapped', render: (row) => formatDateTime(row.mapped_at) },
        {
            field: 'status',
            label: 'Status',
            render: (row) =>
                row.approved_at ? (
                    <span className="badge badge-status-active">Approved by {row.approver?.name ?? '—'}</span>
                ) : (
                    <span className="badge badge-status-pending">Awaiting approval</span>
                ),
        },
        {
            field: 'actions',
            label: '',
            render: (row) =>
                !row.approved_at && can.approve ? (
                    <div className="flex gap-2">
                        <button type="button" onClick={() => approve(row)} className="btn-success text-xs">
                            Approve
                        </button>
                        <button type="button" onClick={() => setRejecting(row)} className="btn-danger text-xs">
                            Reject
                        </button>
                    </div>
                ) : null,
        },
    ];

    return (
        <AuthenticatedLayout header="Control mappings">
            <Head title="Control mappings" />

            <PageHeader
                title="Control ↔ requirement mappings"
                subtitle="A mapping is a claim that a control satisfies a requirement. It counts for nothing until a second person approves it."
            />

            <div className="mb-6 grid grid-cols-2 gap-4">
                <StatCard icon={Clock}
                    title="Awaiting approval"
                    value={stats.pending ?? 0}
                    color="warning"
                    prominent={(stats.pending ?? 0) > 0}
                />
                <StatCard icon={CheckCircle2} title="Approved" value={stats.approved ?? 0} color="success" />
            </div>

            <FilterBar
                route="control-mappings.index"
                resource="control-mappings"
                currentFilters={filters}
                filters={[
                    {
                        name: 'status',
                        type: 'select',
                        label: 'Status',
                        options: [
                            { value: 'pending', label: 'Awaiting approval' },
                            { value: 'approved', label: 'Approved' },
                        ],
                    },
                ]}
            />

            <div className="card">
                <DataTable columns={columns} data={mappings.data} emptyMessage="No mappings match these filters." />
                <Pagination paginator={mappings} />
            </div>

            <Modal show={Boolean(rejecting)} maxWidth="md" title="Reject this mapping?" onClose={() => setRejecting(null)}>
                <p className="text-sm text-[var(--color-text-secondary)]">
                    Rejecting removes the claim. Record why — the reason is written to the audit trail.
                </p>
                <TextArea
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                    rows={3}
                    className="mt-3 block w-full"
                    placeholder="Reason for rejection"
                />
                <div className="mt-6 flex justify-end gap-2">
                    <SecondaryButton onClick={() => setRejecting(null)}>Cancel</SecondaryButton>
                    <DangerButton onClick={reject} disabled={reason.trim().length === 0}>
                        Reject mapping
                    </DangerButton>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
