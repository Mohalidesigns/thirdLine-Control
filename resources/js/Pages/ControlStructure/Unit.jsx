import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import ProgressBar from '@/Components/ProgressBar';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import SeverityBadge from '@/Components/SeverityBadge';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { useState } from 'react';

const EMPTY = {
    control_unit_id: '',
    parent_id: '',
    name: '',
    description: '',
    entity_kind: 'department',
    organisation_unit_id: '',
    business_process_id: '',
    owner_id: '',
    risk_rating: '',
    review_frequency: '',
    is_template: false,
    sequence: 10,
    is_active: true,
};

export default function Unit({
    unit,
    entities = { data: [] },
    templates = [],
    filters = {},
    kinds = [],
    riskRatings = [],
    reviewFrequencies = [],
    organisationUnits = [],
    businessProcesses = [],
    users = [],
    functionCounts = {},
    can = {},
}) {
    const isBranchDomain = unit.domain === 'branch';
    const [editing, setEditing] = useState(null); // null | 'new' | 'new-template' | entity
    const form = useForm(EMPTY);

    const open = (entity) => {
        const defaultKind = isBranchDomain ? 'activity' : (unit.domain === 'information_systems' ? 'domain' : 'department');

        if (entity === 'new' || entity === 'new-template') {
            form.setDefaults(EMPTY);
            form.reset();
            form.setData({
                ...EMPTY,
                control_unit_id: unit.id,
                entity_kind: defaultKind,
                is_template: entity === 'new-template',
            });
        } else {
            form.setData({
                ...EMPTY,
                ...Object.fromEntries(Object.keys(EMPTY).map((k) => [k, entity[k] ?? EMPTY[k]])),
                control_unit_id: unit.id,
                parent_id: entity.parent_id ?? '',
                organisation_unit_id: entity.organisation_unit_id ?? '',
                business_process_id: entity.business_process_id ?? '',
                owner_id: entity.owner_id ?? '',
                risk_rating: entity.risk_rating ?? '',
                review_frequency: entity.review_frequency ?? '',
            });
        }
        setEditing(entity);
    };

    const submit = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setEditing(null) };

        if (editing === 'new' || editing === 'new-template') {
            form.post(route('control-structure.entities.store'), options);
        } else {
            form.put(route('control-structure.entities.update', editing.id), options);
        }
    };

    // CR-03: how many departmental control functions this entity runs,
    // and how much of the last 30 days' work it actually completed.
    const functionColumns = [
        {
            field: 'control_functions',
            label: 'Functions',
            width: '7rem',
            render: (row) => <span className="tabular-nums">{functionCounts[row.id]?.functions ?? 0}</span>,
        },
        {
            field: 'task_completion',
            label: 'Tasks (30d)',
            width: '13rem',
            render: (row) => {
                const stats = functionCounts[row.id];

                if (!stats || stats.tasks === 0) return <span className="text-gray-400">—</span>;

                return (
                    <div className="flex items-center gap-2">
                        <ProgressBar
                            value={stats.completion_rate}
                            color={stats.completion_rate >= 95 ? 'var(--color-success)' : stats.completion_rate >= 80 ? 'var(--color-warning)' : 'var(--color-error)'}
                        />
                        <span className="shrink-0 text-xs tabular-nums text-gray-500">
                            {stats.completed}/{stats.tasks}
                            {stats.overdue > 0 && <span className="ms-1 font-semibold text-red-600">({stats.overdue} late)</span>}
                        </span>
                    </div>
                );
            },
        },
    ];

    const branchColumns = [
        {
            field: 'name',
            label: 'Branch',
            render: (row) => (
                <Link href={route('control-structure.entity', row.id)} className="flex items-center gap-2 font-medium text-[var(--color-primary)] hover:underline">
                    <Building2 className="h-4 w-4 text-gray-400" strokeWidth={1.8} />
                    {row.name}
                    <span className="font-mono text-xs text-gray-400">{row.reference}</span>
                </Link>
            ),
        },
        { field: 'code', label: 'Code', render: (row) => <span className="font-mono text-xs">{row.organisation_unit?.code ?? '—'}</span> },
        { field: 'head', label: 'Branch head', render: (row) => row.organisation_unit?.head?.name ?? '—' },
        { field: 'children_count', label: 'Activities', render: (row) => row.children_count },
        { field: 'controls_count', label: 'Controls', render: (row) => row.controls_count },
        ...functionColumns,
    ];

    const flatColumns = [
        {
            field: 'name',
            label: 'Entity',
            render: (row) => (
                <Link href={route('control-structure.entity', row.id)} className="font-medium text-[var(--color-primary)] hover:underline">
                    {row.name}
                    <span className="ml-2 font-mono text-xs text-gray-400">{row.reference}</span>
                </Link>
            ),
        },
        { field: 'organisation_unit', label: 'Bridged unit', render: (row) => row.organisation_unit?.name ?? '—' },
        { field: 'owner', label: 'Relationship officer', render: (row) => row.owner?.name ?? '—' },
        { field: 'risk_rating', label: 'Rating', render: (row) => row.risk_rating ? <SeverityBadge severity={row.risk_rating} /> : '—' },
        { field: 'controls_count', label: 'Controls', render: (row) => row.controls_count },
        ...functionColumns,
        {
            field: 'next_review_due_at',
            label: 'Next review',
            render: (row) => row.next_review_due_at
                ? <span className={new Date(row.next_review_due_at) < new Date() ? 'font-medium text-red-600' : ''}>{row.next_review_due_at.substring(0, 10)}</span>
                : '—',
        },
    ];

    const editColumn = {
        field: 'actions',
        label: '',
        render: (row) => can.manage && (
            <button type="button" className="text-xs font-medium text-[var(--color-primary)] hover:underline" onClick={(e) => { e.stopPropagation(); open(row); }}>
                Edit
            </button>
        ),
    };

    return (
        <AuthenticatedLayout header={unit.name}>
            <Head title={unit.name} />

            <PageHeader
                title={unit.name}
                subtitle={isBranchDomain
                    ? 'The branch network, derived from the organisation tree — drill into a branch for its control activities'
                    : 'The register of entities this sub-unit oversees'}
                breadcrumbs={[
                    { label: 'Control Structure', href: route('control-structure.index') },
                    { label: unit.name },
                ]}
                actions={can.manage && !isBranchDomain && (
                    <PrimaryButton onClick={() => open('new')}>New entity</PrimaryButton>
                )}
            />

            <div className="filter-bar">
                <div className="filter-bar-inner">
                    <div className="filter-bar-group flex-1">
                        <TextInput
                            defaultValue={filters.search ?? ''}
                            placeholder={isBranchDomain ? 'Search branches…' : 'Search entities…'}
                            className="w-full sm:max-w-xs"
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    router.get(route('control-structure.unit', unit.id), { search: e.target.value }, { preserveState: true, replace: true });
                                }
                            }}
                        />
                    </div>
                </div>
            </div>

            <div className="card mt-4">
                <div className="card-body p-0">
                    <DataTable
                        columns={[...(isBranchDomain ? branchColumns : flatColumns), editColumn]}
                        data={entities.data}
                        emptyMessage={isBranchDomain
                            ? 'No branches yet — create Branch organisation units and they appear here automatically.'
                            : 'No entities in this register yet.'}
                    />
                </div>
            </div>
            <Pagination paginator={entities} />

            {isBranchDomain && (
                <div className="card mt-6">
                    <div className="card-header flex items-center justify-between">
                        <h3 className="text-sm font-semibold">Branch activity template</h3>
                        {can.manage && (
                            <SecondaryButton onClick={() => open('new-template')}>Add template activity</SecondaryButton>
                        )}
                    </div>
                    <div className="card-body">
                        {templates.length === 0 ? (
                            <EmptyState title="No template activities" description="Template activities are instantiated under every branch on the next sync." />
                        ) : (
                            <div className="flex flex-wrap gap-2">
                                {templates.map((t) => (
                                    <span key={t.id} className={`badge ${t.is_active ? 'badge-status-active' : 'badge-status-draft'}`}>
                                        {t.name}
                                    </span>
                                ))}
                            </div>
                        )}
                        <p className="mt-3 text-xs text-gray-500">
                            New template activities reach every branch on the next sync (add-only). Editing or removing a template never rewrites activities already provisioned.
                        </p>
                    </div>
                </div>
            )}

            <Modal show={editing !== null} onClose={() => setEditing(null)}
                title={editing === 'new' ? 'New control entity' : editing === 'new-template' ? 'New template activity' : 'Edit control entity'}>
                <form onSubmit={submit} className="space-y-4 p-6">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <InputLabel value="Name" required />
                            <TextInput value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className="w-full" />
                            <InputError message={form.errors.name} />
                        </div>
                        <div>
                            <InputLabel value="Kind" required />
                            <SelectInput value={form.data.entity_kind} onChange={(e) => form.setData('entity_kind', e.target.value)} disabled={editing === 'new-template'}>
                                {kinds.map((k) => <option key={k} value={k}>{k}</option>)}
                            </SelectInput>
                            <InputError message={form.errors.entity_kind} />
                        </div>
                        <div>
                            <InputLabel value="Bridged organisation unit" required={form.data.entity_kind === 'branch'} />
                            <SelectInput value={form.data.organisation_unit_id} onChange={(e) => form.setData('organisation_unit_id', e.target.value || '')}>
                                <option value="">— none —</option>
                                {organisationUnits.map((u) => <option key={u.id} value={u.id}>{u.name} ({u.type})</option>)}
                            </SelectInput>
                            <InputError message={form.errors.organisation_unit_id} />
                        </div>
                        <div>
                            <InputLabel value="Business process" />
                            <SelectInput value={form.data.business_process_id} onChange={(e) => form.setData('business_process_id', e.target.value || '')}>
                                <option value="">— none —</option>
                                {businessProcesses.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Relationship officer" />
                            <SelectInput value={form.data.owner_id} onChange={(e) => form.setData('owner_id', e.target.value || '')}>
                                <option value="">— none —</option>
                                {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                            </SelectInput>
                            <InputError message={form.errors.owner_id} />
                        </div>
                        <div>
                            <InputLabel value="Risk rating" />
                            <SelectInput value={form.data.risk_rating} onChange={(e) => form.setData('risk_rating', e.target.value || '')}>
                                <option value="">— unrated —</option>
                                {riskRatings.map((r) => <option key={r} value={r}>{r}</option>)}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Review frequency" />
                            <SelectInput value={form.data.review_frequency} onChange={(e) => form.setData('review_frequency', e.target.value || '')}>
                                <option value="">— none —</option>
                                {reviewFrequencies.map((f) => <option key={f} value={f}>{f}</option>)}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Sequence" />
                            <TextInput type="number" min="0" value={form.data.sequence} onChange={(e) => form.setData('sequence', e.target.value)} className="w-full" />
                        </div>
                        <div className="sm:col-span-2">
                            <InputLabel value="Description" />
                            <TextInput value={form.data.description ?? ''} onChange={(e) => form.setData('description', e.target.value)} className="w-full" />
                        </div>
                    </div>
                    <div className="flex justify-end gap-3">
                        <SecondaryButton type="button" onClick={() => setEditing(null)}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={form.processing}>
                            {editing === 'new' || editing === 'new-template' ? 'Create' : 'Save changes'}
                        </PrimaryButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
