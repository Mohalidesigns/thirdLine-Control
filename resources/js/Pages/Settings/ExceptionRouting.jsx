import DataTable from '@/Components/DataTable';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Plus } from 'lucide-react';

const EMPTY = {
    sequence: 1,
    is_active: true,
    match_unit_id: '',
    match_process_id: '',
    match_control_category_id: '',
    match_severity: '',
    match_source_type: '',
    route_to_type: 'unit_head',
    route_to_role: '',
    route_to_user_id: '',
    route_to_unit_id: '',
    sla_policy_id: '',
};

/**
 * CR-01: default recipient routing. Rules PRE-FILL the issue form in
 * sequence order, first match wins — the control officer always confirms.
 */
export default function ExceptionRouting({
    rules = [], units = [], processes = [], categories = [], users = [], roles = [], slaPolicies = [], severities = [],
}) {
    const [editing, setEditing] = useState(null);
    const form = useForm(EMPTY);

    const open = (rule = null) => {
        if (rule) {
            form.setData({ ...EMPTY, ...Object.fromEntries(Object.entries(rule).map(([k, v]) => [k, v ?? ''])) });
            setEditing(rule);
        } else {
            form.setData({ ...EMPTY, sequence: rules.length + 1 });
            setEditing('new');
        }
    };

    const close = () => { setEditing(null); form.reset(); form.clearErrors(); };

    const submit = (e) => {
        e.preventDefault();

        form.transform((data) => Object.fromEntries(
            Object.entries(data).map(([k, v]) => [k, v === '' ? null : v]),
        ));

        if (editing === 'new') {
            form.post(route('admin.exception-routing.store'), { preserveScroll: true, onSuccess: close });
        } else {
            form.put(route('admin.exception-routing.update', editing.id), { preserveScroll: true, onSuccess: close });
        }
    };

    const routeLabel = (rule) => {
        switch (rule.route_to_type) {
            case 'unit_head': return `Head of ${rule.route_to_unit?.name ?? 'matched unit'}`;
            case 'process_owner': return 'Process owner';
            case 'role': return `Role: ${rule.route_to_role}`;
            case 'user': return rule.route_to_user?.name ?? '—';
            default: return '—';
        }
    };

    return (
        <AuthenticatedLayout header="Exception Routing">
            <Head title="Exception Routing" />

            <PageHeader
                title="Exception routing rules"
                subtitle="Who an issued exception is addressed to by default — first match wins, and the issuer always confirms"
                actions={
                    <>
                        <Link href={route('admin.exception-sla')} className="btn-secondary">SLA policies</Link>
                        <PrimaryButton onClick={() => open()}><Plus className="h-4 w-4" strokeWidth={2} /> New rule</PrimaryButton>
                    </>
                }
            />

            <div className="card">
                <DataTable
                    emptyMessage="No routing rules — resolution falls back to the unit head or process owner."
                    columns={[
                        { field: 'sequence', label: '#' },
                        {
                            field: 'match',
                            label: 'Matches',
                            render: (r) => {
                                const parts = [
                                    r.match_unit?.name && `unit ${r.match_unit.name}`,
                                    r.match_process?.name && `process ${r.match_process.name}`,
                                    r.match_severity && `severity ${r.match_severity}`,
                                    r.match_source_type && `source ${r.match_source_type}`,
                                ].filter(Boolean);

                                return parts.length ? parts.join(' · ') : 'Everything';
                            },
                        },
                        { field: 'route_to_type', label: 'Routes to', render: routeLabel },
                        { field: 'sla_policy', label: 'SLA', render: (r) => r.sla_policy?.name ?? 'Severity default' },
                        {
                            field: 'is_active',
                            label: 'Active',
                            render: (r) => <span className={`badge ${r.is_active ? 'badge-status-active' : 'badge-status-draft'}`}>{r.is_active ? 'Active' : 'Inactive'}</span>,
                        },
                        {
                            field: 'id',
                            label: '',
                            render: (r) => (
                                <span className="flex gap-3">
                                    <button type="button" className="text-sm text-[var(--color-primary)] hover:underline" onClick={() => open(r)}>Edit</button>
                                    <Link
                                        href={route('admin.exception-routing.destroy', r.id)}
                                        method="delete"
                                        as="button"
                                        className="text-sm text-[var(--color-error)] hover:underline"
                                    >
                                        Remove
                                    </Link>
                                </span>
                            ),
                        },
                    ]}
                    data={rules}
                />
            </div>

            <Modal show={editing !== null} onClose={close} title={editing === 'new' ? 'New routing rule' : 'Edit routing rule'} maxWidth="lg">
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Sequence" required />
                            <TextInput type="number" min="1" value={form.data.sequence} onChange={(e) => form.setData('sequence', e.target.value)} />
                            <InputError message={form.errors.sequence} className="mt-1" />
                        </div>
                        <div className="flex items-end pb-2">
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" className="rounded border-gray-300" checked={!!form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                                Active
                            </label>
                        </div>
                    </div>

                    <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Match (empty = any)</p>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Unit" />
                            <SelectInput value={form.data.match_unit_id} onChange={(e) => form.setData('match_unit_id', e.target.value)}>
                                <option value="">Any</option>
                                {units.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Process" />
                            <SelectInput value={form.data.match_process_id} onChange={(e) => form.setData('match_process_id', e.target.value)}>
                                <option value="">Any</option>
                                {processes.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Control category" />
                            <SelectInput value={form.data.match_control_category_id} onChange={(e) => form.setData('match_control_category_id', e.target.value)}>
                                <option value="">Any</option>
                                {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Severity" />
                            <SelectInput value={form.data.match_severity} onChange={(e) => form.setData('match_severity', e.target.value)}>
                                <option value="">Any</option>
                                {severities.map((s) => <option key={s} value={s}>{s}</option>)}
                            </SelectInput>
                        </div>
                    </div>

                    <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Route to</p>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Recipient type" required />
                            <SelectInput value={form.data.route_to_type} onChange={(e) => form.setData('route_to_type', e.target.value)}>
                                <option value="unit_head">Unit head</option>
                                <option value="process_owner">Process owner</option>
                                <option value="role">First user in role</option>
                                <option value="user">Named user</option>
                            </SelectInput>
                        </div>
                        {form.data.route_to_type === 'unit_head' && (
                            <div>
                                <InputLabel value="Unit (empty = the matched unit)" />
                                <SelectInput value={form.data.route_to_unit_id} onChange={(e) => form.setData('route_to_unit_id', e.target.value)}>
                                    <option value="">Matched unit</option>
                                    {units.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                                </SelectInput>
                            </div>
                        )}
                        {form.data.route_to_type === 'role' && (
                            <div>
                                <InputLabel value="Role" required />
                                <SelectInput value={form.data.route_to_role} onChange={(e) => form.setData('route_to_role', e.target.value)}>
                                    <option value="">— Select —</option>
                                    {roles.map((r) => <option key={r} value={r}>{r}</option>)}
                                </SelectInput>
                                <InputError message={form.errors.route_to_role} className="mt-1" />
                            </div>
                        )}
                        {form.data.route_to_type === 'user' && (
                            <div>
                                <InputLabel value="User" required />
                                <SelectInput value={form.data.route_to_user_id} onChange={(e) => form.setData('route_to_user_id', e.target.value)}>
                                    <option value="">— Select —</option>
                                    {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                                </SelectInput>
                                <InputError message={form.errors.route_to_user_id} className="mt-1" />
                            </div>
                        )}
                        <div>
                            <InputLabel value="SLA policy (empty = severity default)" />
                            <SelectInput value={form.data.sla_policy_id} onChange={(e) => form.setData('sla_policy_id', e.target.value)}>
                                <option value="">Severity default</option>
                                {slaPolicies.map((p) => <option key={p.id} value={p.id}>{p.name} ({p.severity})</option>)}
                            </SelectInput>
                        </div>
                    </div>

                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={close}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={form.processing}>{editing === 'new' ? 'Create rule' : 'Save changes'}</PrimaryButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
