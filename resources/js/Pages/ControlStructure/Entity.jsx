import ConfirmDialog from '@/Components/ConfirmDialog';
import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SeverityBadge from '@/Components/SeverityBadge';
import StatusBadge from '@/Components/StatusBadge';
import TabBar from '@/Components/TabBar';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertCircle, Briefcase, FlaskConical, ShieldAlert, ShieldCheck, TrendingUp } from 'lucide-react';
import { useMemo, useState } from 'react';

export default function Entity({ entity, exceptions = [], tests = [], availableControls = [], can = {} }) {
    const [tab, setTab] = useState('Controls');
    const [attaching, setAttaching] = useState(false);
    const [detaching, setDetaching] = useState(null);

    const tabs = [
        { name: 'Controls', icon: ShieldCheck, count: entity.controls?.length ?? 0 },
        { name: 'Exceptions', icon: AlertCircle, count: exceptions.length },
        { name: 'Tests', icon: FlaskConical, count: tests.length },
        // Shells filled by CR2-B and CR2-D.
        { name: 'Risks', icon: ShieldAlert },
        { name: 'Trend', icon: TrendingUp },
        { name: 'Investigations', icon: Briefcase },
    ];

    const detach = () => {
        router.delete(route('control-structure.entities.detach', [entity.id, detaching.id]), {
            preserveScroll: true,
            onFinish: () => setDetaching(null),
        });
    };

    return (
        <AuthenticatedLayout header={entity.name}>
            <Head title={entity.name} />

            <PageHeader
                title={entity.name}
                subtitle={`${entity.reference} · ${entity.entity_kind} under ${entity.control_unit?.name ?? ''}`}
                breadcrumbs={[
                    { label: 'Control Structure', href: route('control-structure.index') },
                    { label: entity.control_unit?.name ?? '', href: entity.control_unit ? route('control-structure.unit', entity.control_unit.id) : undefined },
                    ...(entity.parent ? [{ label: entity.parent.name, href: route('control-structure.entity', entity.parent.id) }] : []),
                    { label: entity.name },
                ]}
                actions={can.attach && (
                    <PrimaryButton onClick={() => setAttaching(true)}>Attach controls</PrimaryButton>
                )}
            />

            <div className="mb-6 grid grid-cols-1 gap-5 lg:grid-cols-4">
                <div className="card lg:col-span-3">
                    <div className="card-body grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                        <Field label="Bridged organisation unit" value={entity.organisation_unit ? `${entity.organisation_unit.name}` : '—'} />
                        <Field label="Business process" value={entity.business_process?.name ?? '—'} />
                        <Field label="Relationship officer" value={entity.owner?.name ?? '—'} />
                        <Field label="Review cadence" value={entity.review_frequency ?? '—'} />
                        <Field label="Risk rating" value={entity.risk_rating ? <SeverityBadge severity={entity.risk_rating} /> : 'Unrated'} />
                        <Field label="Last reviewed" value={entity.last_reviewed_at?.substring(0, 10) ?? '—'} />
                        <Field
                            label="Next review due"
                            value={entity.next_review_due_at
                                ? <span className={new Date(entity.next_review_due_at) < new Date() ? 'font-medium text-red-600' : ''}>{entity.next_review_due_at.substring(0, 10)}</span>
                                : '—'}
                        />
                        <Field label="Status" value={<StatusBadge status={entity.is_active ? 'active' : 'inactive'} />} />
                    </div>
                </div>

                {(entity.children ?? []).length > 0 && (
                    <div className="card">
                        <div className="card-header"><h3 className="text-sm font-semibold">Activities</h3></div>
                        <div className="card-body p-0">
                            <ul className="divide-y divide-gray-100">
                                {entity.children.map((child) => (
                                    <li key={child.id}>
                                        <Link href={route('control-structure.entity', child.id)} className="flex items-center justify-between px-4 py-2 text-sm hover:bg-gray-50">
                                            <span className="text-[var(--color-primary)]">{child.name}</span>
                                            <span className="text-xs text-gray-400">{child.controls_count} control(s)</span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>
                )}
            </div>

            <TabBar tabs={tabs} active={tab} onChange={setTab} />

            {tab === 'Controls' && (
                <div className="card">
                    <div className="card-body p-0">
                        <DataTable
                            columns={[
                                {
                                    field: 'control_ref',
                                    label: 'Control',
                                    render: (row) => (
                                        <Link href={route('controls.show', row.id)} className="font-medium text-[var(--color-primary)] hover:underline">
                                            <span className="font-mono text-xs">{row.control_ref}</span> {row.title}
                                        </Link>
                                    ),
                                },
                                { field: 'unit', label: 'Owning unit', render: (row) => row.unit?.name ?? '—' },
                                { field: 'owner', label: 'Owner', render: (row) => row.owner?.name ?? '—' },
                                {
                                    field: 'is_key',
                                    label: 'Key here',
                                    render: (row) => row.pivot?.is_key ? <span className="badge badge-status-active">Key</span> : '—',
                                },
                                { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
                                {
                                    field: 'actions',
                                    label: '',
                                    render: (row) => can.attach && (
                                        <button type="button" className="text-xs font-medium text-red-600 hover:underline" onClick={() => setDetaching(row)}>
                                            Detach
                                        </button>
                                    ),
                                },
                            ]}
                            data={entity.controls ?? []}
                            emptyMessage="No controls attached to this entity yet."
                        />
                    </div>
                </div>
            )}

            {tab === 'Exceptions' && (
                <div className="card">
                    <div className="card-body p-0">
                        <DataTable
                            columns={[
                                {
                                    field: 'reference',
                                    label: 'Reference',
                                    render: (row) => (
                                        <Link href={route('exceptions.show', row.id)} className="font-mono text-xs font-medium text-[var(--color-primary)] hover:underline">
                                            {row.reference}
                                        </Link>
                                    ),
                                },
                                { field: 'title', label: 'Title' },
                                { field: 'control', label: 'Control', render: (row) => row.control?.control_ref ?? '—' },
                                { field: 'severity', label: 'Severity', render: (row) => <SeverityBadge severity={row.severity} /> },
                                { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
                                { field: 'date_raised', label: 'Raised', render: (row) => row.date_raised?.substring(0, 10) },
                            ]}
                            data={exceptions}
                            emptyMessage="No exceptions on this entity's attached controls."
                        />
                    </div>
                </div>
            )}

            {tab === 'Tests' && (
                <div className="card">
                    <div className="card-body p-0">
                        <DataTable
                            columns={[
                                {
                                    field: 'reference',
                                    label: 'Test',
                                    render: (row) => (
                                        <Link href={route('test-instances.show', row.id)} className="font-mono text-xs font-medium text-[var(--color-primary)] hover:underline">
                                            {row.reference ?? `#${row.id}`}
                                        </Link>
                                    ),
                                },
                                { field: 'control', label: 'Control', render: (row) => row.control?.control_ref ?? '—' },
                                { field: 'tester', label: 'Tester', render: (row) => row.tester?.name ?? '—' },
                                { field: 'period_start', label: 'Period', render: (row) => row.period_start?.substring(0, 10) },
                                { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
                            ]}
                            data={tests}
                            emptyMessage="No tests on this entity's attached controls."
                        />
                    </div>
                </div>
            )}

            {tab === 'Risks' && (
                <EmptyState
                    icon={ShieldAlert}
                    title="Internal control risk register"
                    description="Risks raised against this entity's control failures will appear here once the internal control register ships (CR2-B)."
                />
            )}

            {tab === 'Trend' && (
                <EmptyState
                    icon={TrendingUp}
                    title="Trend analysis"
                    description="Repeated-failure signals for this entity's controls will appear here once the trend engine ships (CR2-B)."
                />
            )}

            {tab === 'Investigations' && (
                <EmptyState
                    icon={Briefcase}
                    title="Internal control investigations"
                    description="Formal investigations opened from this entity's control failures will appear here once the investigation workbench ships (CR2-D)."
                />
            )}

            <AttachControlsModal
                show={attaching}
                onClose={() => setAttaching(false)}
                entity={entity}
                availableControls={availableControls}
            />

            <ConfirmDialog
                show={detaching !== null}
                title="Detach control"
                message={`Detach ${detaching?.control_ref} from ${entity.name}? Detaching is blocked while the control has open exceptions or tests in flight.`}
                variant="danger"
                onConfirm={detach}
                onCancel={() => setDetaching(null)}
            />
        </AuthenticatedLayout>
    );
}

function Field({ label, value }) {
    return (
        <div>
            <dt className="text-xs text-gray-400">{label}</dt>
            <dd className="mt-0.5 text-[var(--color-text-primary)]">{value}</dd>
        </div>
    );
}

function AttachControlsModal({ show, onClose, entity, availableControls }) {
    const [search, setSearch] = useState('');
    const form = useForm({ attachments: [] });

    const filtered = useMemo(() => {
        const term = search.toLowerCase();

        return availableControls.filter((c) =>
            !term || c.title.toLowerCase().includes(term) || c.control_ref.toLowerCase().includes(term)
            || (c.unit?.name ?? '').toLowerCase().includes(term));
    }, [availableControls, search]);

    const selected = (id) => form.data.attachments.find((a) => a.control_id === id);

    const toggle = (control) => {
        form.setData('attachments', selected(control.id)
            ? form.data.attachments.filter((a) => a.control_id !== control.id)
            : [...form.data.attachments, { control_id: control.id, is_key: control.is_key_control }]);
    };

    const toggleKey = (id) => {
        form.setData('attachments', form.data.attachments.map((a) =>
            a.control_id === id ? { ...a, is_key: !a.is_key } : a));
    };

    const submit = (e) => {
        e.preventDefault();
        form.post(route('control-structure.entities.attach', entity.id), {
            preserveScroll: true,
            onSuccess: () => { form.reset(); onClose(); },
        });
    };

    return (
        <Modal show={show} onClose={onClose} title={`Attach controls — ${entity.name}`} maxWidth="2xl">
            <form onSubmit={submit} className="space-y-4 p-6">
                <TextInput
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Filter by reference, title or unit…"
                    className="w-full"
                />
                <div className="max-h-80 overflow-y-auto rounded-lg border border-gray-200">
                    <ul className="divide-y divide-gray-100">
                        {filtered.map((control) => {
                            const picked = selected(control.id);

                            return (
                                <li key={control.id} className="flex items-center justify-between gap-3 px-4 py-2 text-sm">
                                    <label className="flex flex-1 cursor-pointer items-center gap-3">
                                        <input
                                            type="checkbox"
                                            className="rounded border-gray-300 text-[var(--color-primary)]"
                                            checked={!!picked}
                                            onChange={() => toggle(control)}
                                        />
                                        <span>
                                            <span className="font-mono text-xs text-gray-500">{control.control_ref}</span>{' '}
                                            {control.title}
                                            {control.unit && <span className="ml-2 text-xs text-gray-400">{control.unit.name}</span>}
                                        </span>
                                    </label>
                                    {picked && (
                                        <label className="flex shrink-0 cursor-pointer items-center gap-1.5 text-xs text-gray-500">
                                            <input
                                                type="checkbox"
                                                className="rounded border-gray-300 text-[var(--color-primary)]"
                                                checked={!!picked.is_key}
                                                onChange={() => toggleKey(control.id)}
                                            />
                                            Key control here
                                        </label>
                                    )}
                                </li>
                            );
                        })}
                        {filtered.length === 0 && (
                            <li className="px-4 py-6 text-center text-sm text-gray-400">No controls match.</li>
                        )}
                    </ul>
                </div>
                <div className="flex items-center justify-between">
                    <p className="text-xs text-gray-500">{form.data.attachments.length} selected</p>
                    <div className="flex gap-3">
                        <SecondaryButton type="button" onClick={onClose}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={form.processing || form.data.attachments.length === 0}>Attach</PrimaryButton>
                    </div>
                </div>
            </form>
        </Modal>
    );
}
