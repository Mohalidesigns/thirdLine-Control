import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import SeverityBadge from '@/Components/SeverityBadge';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import RichTextEditor from '@/Components/RichTextEditor';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Plus } from 'lucide-react';

export default function Index({
    improvements,
    filters = {},
    statuses = [],
    priorities = [],
    sources = [],
    stats = {},
    controls = [],
    risks = [],
    users = [],
}) {
    const { auth } = usePage().props;
    const [showCreate, setShowCreate] = useState(false);
    const canCreate = auth.permissions.includes('create improvements');
    const canApprove = auth.permissions.includes('approve improvements');
    const canVerify = auth.permissions.includes('verify improvements');

    const columns = [
        {
            field: 'reference',
            label: 'Ref',
            render: (r) => <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{r.reference}</span>,
        },
        {
            field: 'title',
            label: 'Improvement',
            render: (r) => (
                <div className="max-w-sm">
                    <p className="font-medium text-[var(--color-text-primary)]">{r.title}</p>
                    <p className="text-xs text-gray-400">
                        from {r.source_type}
                        {r.control ? ` · ${r.control.control_ref}` : ''}
                        {r.risk ? ` · ${r.risk.code}` : ''}
                    </p>
                </div>
            ),
        },
        { field: 'priority', label: 'Priority', render: (r) => <SeverityBadge severity={r.priority} /> },
        { field: 'owner', label: 'Owner', render: (r) => r.owner?.name ?? '—' },
        { field: 'due_at', label: 'Due', render: (r) => (r.due_at ? formatDate(r.due_at) : '—') },
        { field: 'status', label: 'Status', render: (r) => <StatusBadge status={r.status} /> },
        {
            field: 'actions',
            label: '',
            render: (r) => <RowActions row={r} canApprove={canApprove} canVerify={canVerify} userId={auth.user.id} />,
        },
    ];

    return (
        <AuthenticatedLayout header="Improvements">
            <Head title="Improvements" />
            <PageHeader
                title="Improvement Database"
                subtitle="Actions from tests, CSAs, spot checks, exceptions and surveys — approved, owned and independently verified"
                actions={canCreate && <PrimaryButton onClick={() => setShowCreate(true)}><Plus className="h-4 w-4" strokeWidth={2} /> Propose improvement</PrimaryButton>}
            />

            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
                <StatCard title="Open" value={stats.open ?? 0} color="primary" />
                <StatCard title="Awaiting approval" value={stats.proposed ?? 0} color="warning" />
                <StatCard title="Implemented (to verify)" value={stats.implemented ?? 0} color="primary" />
                <StatCard title="Verified" value={stats.verified ?? 0} color="success" />
            </div>

            <FilterBar
                route="improvements.index"
                resource="improvements"
                currentFilters={filters}
                searchPlaceholder="Search improvements…"
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    { name: 'status', type: 'select', label: 'Status', options: statuses },
                    { name: 'priority', type: 'select', label: 'Priority', options: priorities },
                    { name: 'source_type', type: 'select', label: 'Source', options: sources },
                ]}
            />

            <div className="card">
                <DataTable columns={columns} data={improvements.data} emptyMessage="No improvement actions match the filters." />
                <Pagination paginator={improvements} />
            </div>

            <CreateModal
                show={showCreate}
                onClose={() => setShowCreate(false)}
                {...{ priorities, sources, controls, risks, users }}
            />
        </AuthenticatedLayout>
    );
}

function RowActions({ row, canApprove, canVerify, userId }) {
    const act = (routeName, payload = {}) => router.post(route(routeName, row.id), payload, { preserveScroll: true });

    if (row.status === 'Proposed' && canApprove) {
        return (
            <div className="flex gap-1">
                <button type="button" className="btn-success !px-2 !py-1 text-xs" onClick={() => act('improvements.decide', { decision: 'approve' })}>
                    Approve
                </button>
                <button
                    type="button"
                    className="btn-danger !px-2 !py-1 text-xs"
                    onClick={() => {
                        const reason = window.prompt('Reason for rejection?');
                        if (reason) act('improvements.decide', { decision: 'reject', reason });
                    }}
                >
                    Reject
                </button>
            </div>
        );
    }

    if (row.status === 'Approved' && (row.owner?.id === userId || canApprove)) {
        return (
            <button type="button" className="btn-primary !px-2 !py-1 text-xs" onClick={() => act('improvements.progress', { to: 'In Progress' })}>
                Start
            </button>
        );
    }

    if (row.status === 'In Progress' && (row.owner?.id === userId || canApprove)) {
        return (
            <button type="button" className="btn-primary !px-2 !py-1 text-xs" onClick={() => act('improvements.progress', { to: 'Implemented' })}>
                Mark implemented
            </button>
        );
    }

    if (row.status === 'Implemented' && canVerify && row.owner?.id !== userId) {
        return (
            <button type="button" className="btn-success !px-2 !py-1 text-xs" onClick={() => act('improvements.verify')}>
                Verify
            </button>
        );
    }

    return null;
}

function CreateModal({ show, onClose, priorities, sources, controls, risks, users }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        title: '',
        description: '',
        description_rich: null,
        category: '',
        priority: 'Medium',
        source_type: 'manual',
        owner_id: '',
        due_at: '',
        benefit_description: '',
        effort_estimate: '',
        control_id: '',
        risk_id: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('improvements.store'), { onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-lg font-semibold">Propose improvement</h2>
                <div className="mt-4 space-y-4">
                    <div>
                        <InputLabel value="Title" required />
                        <TextInput value={data.title} onChange={(e) => setData('title', e.target.value)} />
                        <InputError message={errors.title} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Description" />
                        <RichTextEditor
                            value={data.description_rich ?? data.description}
                            onChange={(doc, plain) => setData((d) => ({ ...d, description: plain, description_rich: doc }))}
                            tools="default"
                            minHeight={110}
                        />
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <InputLabel value="Priority" required />
                            <SelectInput value={data.priority} onChange={(e) => setData('priority', e.target.value)}>
                                {priorities.map((p) => <option key={p} value={p}>{p}</option>)}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Source" required />
                            <SelectInput value={data.source_type} onChange={(e) => setData('source_type', e.target.value)}>
                                {sources.map((s) => <option key={s} value={s}>{s}</option>)}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Due" />
                            <TextInput type="date" value={data.due_at} onChange={(e) => setData('due_at', e.target.value)} />
                            <InputError message={errors.due_at} className="mt-1" />
                        </div>
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <InputLabel value="Owner" />
                            <SelectInput value={data.owner_id} onChange={(e) => setData('owner_id', e.target.value)}>
                                <option value="">Unassigned</option>
                                {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Linked control" />
                            <SelectInput value={data.control_id} onChange={(e) => setData('control_id', e.target.value)}>
                                <option value="">None</option>
                                {controls.map((c) => <option key={c.id} value={c.id}>{c.control_ref}</option>)}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Linked risk" />
                            <SelectInput value={data.risk_id} onChange={(e) => setData('risk_id', e.target.value)}>
                                <option value="">None</option>
                                {risks.map((r) => <option key={r.id} value={r.id}>{r.code}</option>)}
                            </SelectInput>
                        </div>
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Expected benefit" />
                            <TextArea rows={2} value={data.benefit_description} onChange={(e) => setData('benefit_description', e.target.value)} />
                        </div>
                        <div>
                            <InputLabel value="Effort estimate" />
                            <TextInput value={data.effort_estimate} onChange={(e) => setData('effort_estimate', e.target.value)} placeholder="e.g. 3 person-days" />
                        </div>
                    </div>
                </div>
                <div className="mt-6 flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>Cancel</SecondaryButton>
                    <PrimaryButton disabled={processing}>Propose</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
