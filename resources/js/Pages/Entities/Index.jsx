import DataTable from '@/Components/DataTable';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import StatusBadge from '@/Components/StatusBadge';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const EMPTY = {
    legal_name: '',
    trading_name: '',
    entity_type: 'bank',
    parent_entity_id: '',
    organisation_unit_id: '',
    jurisdiction: 'NG',
    registration_number: '',
    fiscal_year_end: '12-31',
    functional_currency: 'NGN',
    consolidation_method: 'full',
    ownership_percentage: 100,
    is_regulated: true,
    data_residency_country: 'NG',
    status: 'active',
};

function flatten(tree, depth = 0) {
    return tree.flatMap(({ entity, children }) => [
        { ...entity, depth },
        ...flatten(children, depth + 1),
    ]);
}

export default function Index({ tree, accessibleIds = [], units = [], users = [], grants = [], entityTypes, consolidationMethods, statuses }) {
    const { auth } = usePage().props;
    const can = (p) => auth.permissions?.includes(p);

    const [editing, setEditing] = useState(null); // null | 'new' | entity
    const [grantsFor, setGrantsFor] = useState(null);
    const form = useForm(EMPTY);

    const rows = flatten(tree);

    const open = (entity) => {
        if (entity === 'new') {
            form.setDefaults(EMPTY);
            form.reset();
        } else {
            form.setData({
                ...EMPTY,
                ...Object.fromEntries(Object.keys(EMPTY).map((k) => [k, entity[k] ?? EMPTY[k]])),
                parent_entity_id: entity.parent_entity_id ?? '',
                organisation_unit_id: entity.organisation_unit_id ?? '',
            });
        }
        setEditing(entity);
    };

    const submit = (e) => {
        e.preventDefault();

        if (editing === 'new') {
            form.post(route('entities.store'), { preserveScroll: true, onSuccess: () => setEditing(null) });
        } else {
            // The residency declaration is set at creation only (16.2).
            const { data_residency_country: _omitted, ...data } = form.data;
            router.put(route('entities.update', editing.id), data, {
                preserveScroll: true,
                onSuccess: () => setEditing(null),
            });
        }
    };

    const grantEntity = grants.find((g) => g.id === grantsFor?.id);

    const columns = [
        {
            field: 'legal_name',
            label: 'Legal entity',
            render: (row) => (
                <div style={{ paddingLeft: `${row.depth * 1.25}rem` }}>
                    <span className="font-medium text-gray-900">{row.legal_name}</span>
                    <span className="ml-2 font-mono text-xs text-gray-400">{row.reference}</span>
                </div>
            ),
        },
        { field: 'entity_type', label: 'Type', render: (row) => row.entity_type?.replaceAll('_', ' ') },
        { field: 'jurisdiction', label: 'Jurisdiction' },
        { field: 'data_residency_country', label: 'Residency' },
        {
            field: 'consolidation_method',
            label: 'Consolidation',
            render: (row) => `${row.consolidation_method} · ${Number(row.ownership_percentage)}%`,
        },
        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
        {
            field: 'actions',
            label: '',
            render: (row) => (
                <div className="flex justify-end gap-2">
                    {can('grant entity-access') && (
                        <button type="button" className="text-xs font-medium text-[var(--color-primary)] hover:underline" onClick={(e) => { e.stopPropagation(); setGrantsFor(row); }}>
                            Access
                        </button>
                    )}
                    {can('manage entities') && (
                        <button type="button" className="text-xs font-medium text-[var(--color-primary)] hover:underline" onClick={(e) => { e.stopPropagation(); open(row); }}>
                            Edit
                        </button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <AuthenticatedLayout header="Entities">
            <Head title="Entities" />

            <PageHeader
                title="Legal entities"
                subtitle="The group structure: who is licensed for what, consolidated how, with data resident where"
                actions={can('manage entities') && (
                    <PrimaryButton onClick={() => open('new')}>New entity</PrimaryButton>
                )}
            />

            <div className="card">
                <div className="card-body p-0">
                    <DataTable columns={columns} data={rows} emptyMessage="No entities yet — create the group structure to unlock the consolidated dashboard." />
                </div>
            </div>

            <p className="mt-3 text-xs text-gray-500">
                Sight of an entity's data requires an explicit access grant — holding a role is never enough.
                You currently hold grants on {accessibleIds.length} of {rows.length} entities.
            </p>

            <Modal show={editing !== null} onClose={() => setEditing(null)} title={editing === 'new' ? 'New legal entity' : 'Edit legal entity'}>
                <form onSubmit={submit} className="space-y-4 p-6">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Legal name" required />
                            <TextInput value={form.data.legal_name} onChange={(e) => form.setData('legal_name', e.target.value)} className="w-full" />
                            <InputError message={form.errors.legal_name} />
                        </div>
                        <div>
                            <InputLabel value="Trading name" />
                            <TextInput value={form.data.trading_name ?? ''} onChange={(e) => form.setData('trading_name', e.target.value)} className="w-full" />
                        </div>
                        <div>
                            <InputLabel value="Entity type" required />
                            <SelectInput value={form.data.entity_type} onChange={(e) => form.setData('entity_type', e.target.value)}>
                                {entityTypes.map((t) => <option key={t} value={t}>{t.replaceAll('_', ' ')}</option>)}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Parent entity" />
                            <SelectInput value={form.data.parent_entity_id} onChange={(e) => form.setData('parent_entity_id', e.target.value || '')}>
                                <option value="">— none (group root) —</option>
                                {rows.filter((r) => editing === 'new' || r.id !== editing?.id).map((r) => (
                                    <option key={r.id} value={r.id}>{r.legal_name}</option>
                                ))}
                            </SelectInput>
                            <InputError message={form.errors.parent_entity_id} />
                        </div>
                        <div>
                            <InputLabel value="Anchored organisation unit" />
                            <SelectInput value={form.data.organisation_unit_id} onChange={(e) => form.setData('organisation_unit_id', e.target.value || '')}>
                                <option value="">— none —</option>
                                {units.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Jurisdiction (ISO-2)" required />
                            <TextInput value={form.data.jurisdiction} maxLength={2} onChange={(e) => form.setData('jurisdiction', e.target.value.toUpperCase())} className="w-full" />
                            <InputError message={form.errors.jurisdiction} />
                        </div>
                        <div>
                            <InputLabel value="Registration number" />
                            <TextInput value={form.data.registration_number ?? ''} onChange={(e) => form.setData('registration_number', e.target.value)} className="w-full" />
                        </div>
                        <div>
                            <InputLabel value="Fiscal year end (MM-DD)" />
                            <TextInput value={form.data.fiscal_year_end ?? ''} placeholder="12-31" onChange={(e) => form.setData('fiscal_year_end', e.target.value)} className="w-full" />
                            <InputError message={form.errors.fiscal_year_end} />
                        </div>
                        <div>
                            <InputLabel value="Functional currency" required />
                            <SelectInput value={form.data.functional_currency} onChange={(e) => form.setData('functional_currency', e.target.value)}>
                                {['NGN', 'GHS', 'KES', 'ZAR', 'USD', 'EUR', 'GBP'].map((c) => <option key={c} value={c}>{c}</option>)}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Consolidation method" required />
                            <SelectInput value={form.data.consolidation_method} onChange={(e) => form.setData('consolidation_method', e.target.value)}>
                                {consolidationMethods.map((m) => <option key={m} value={m}>{m}</option>)}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Ownership %" required />
                            <TextInput type="number" min="0" max="100" step="0.01" value={form.data.ownership_percentage} onChange={(e) => form.setData('ownership_percentage', e.target.value)} className="w-full" />
                            <InputError message={form.errors.ownership_percentage} />
                        </div>
                        {editing === 'new' ? (
                            <div>
                                <InputLabel value="Data residency country (set once)" required />
                                <TextInput value={form.data.data_residency_country} maxLength={2} onChange={(e) => form.setData('data_residency_country', e.target.value.toUpperCase())} className="w-full" />
                                <InputError message={form.errors.data_residency_country} />
                            </div>
                        ) : (
                            <div>
                                <InputLabel value="Data residency country" />
                                <p className="mt-2 text-sm text-gray-500">
                                    <span className="font-mono">{editing?.data_residency_country}</span> — changing a declaration is a re-provisioning event.
                                </p>
                            </div>
                        )}
                        <div>
                            <InputLabel value="Status" required />
                            <SelectInput value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                                {statuses.map((s) => <option key={s} value={s}>{s}</option>)}
                            </SelectInput>
                        </div>
                    </div>
                    <div className="flex justify-end gap-3">
                        <SecondaryButton type="button" onClick={() => setEditing(null)}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={form.processing}>{editing === 'new' ? 'Create entity' : 'Save changes'}</PrimaryButton>
                    </div>
                </form>
            </Modal>

            <Modal show={grantsFor !== null} onClose={() => setGrantsFor(null)} title={`Access — ${grantsFor?.legal_name ?? ''}`}>
                <div className="space-y-4 p-6">
                    <p className="text-sm text-gray-600">
                        These users see this entity's data in the group rollup. Absence of a grant is denial — for every role.
                    </p>
                    <ul className="divide-y divide-gray-100 rounded-lg border border-gray-200">
                        {(grantEntity?.users ?? []).map((u) => (
                            <li key={u.id} className="flex items-center justify-between px-4 py-2 text-sm">
                                <span>{u.name}</span>
                                <button
                                    type="button"
                                    className="text-xs font-medium text-red-600 hover:underline"
                                    onClick={() => router.delete(route('entities.grants.destroy', [grantsFor.id, u.id]), { preserveScroll: true })}
                                >
                                    Revoke
                                </button>
                            </li>
                        ))}
                        {(grantEntity?.users ?? []).length === 0 && (
                            <li className="px-4 py-3 text-sm text-gray-400">No one has been granted this entity yet.</li>
                        )}
                    </ul>
                    <GrantForm entity={grantsFor} users={users} granted={grantEntity?.users ?? []} />
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}

function GrantForm({ entity, users, granted }) {
    const form = useForm({ user_id: '', note: '' });
    const grantedIds = granted.map((u) => u.id);
    const candidates = users.filter((u) => !grantedIds.includes(u.id));

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(route('entities.grants.store', entity.id), { preserveScroll: true, onSuccess: () => form.reset() });
            }}
            className="flex flex-col gap-3 sm:flex-row sm:items-end"
        >
            <div className="flex-1">
                <InputLabel value="Grant a user" />
                <SelectInput value={form.data.user_id} onChange={(e) => form.setData('user_id', e.target.value)}>
                    <option value="">— select —</option>
                    {candidates.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                </SelectInput>
                <InputError message={form.errors.user_id} />
            </div>
            <div className="flex-1">
                <InputLabel value="Note" />
                <TextInput value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} className="w-full" placeholder="Why this person" />
            </div>
            <PrimaryButton disabled={form.processing || !form.data.user_id}>Grant</PrimaryButton>
        </form>
    );
}
