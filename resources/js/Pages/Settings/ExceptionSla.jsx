import DataTable from '@/Components/DataTable';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import SeverityBadge from '@/Components/SeverityBadge';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

const EMPTY = {
    name: '',
    severity: 'High',
    acknowledge_within_hours: 24,
    respond_within_days: 5,
    reminder_offsets: '-3,-1,1,3,7',
    max_reminders: 5,
    escalate_after_days: 3,
    auto_escalate_to_matrix: true,
    is_active: true,
};

/**
 * CR-01 (R1): every Exception Manager clock lives here. Business days, in
 * the tenant's timezone, skipping seeded public holidays — never a
 * constant in code.
 */
export default function ExceptionSla({ policies = [], severities = [] }) {
    const [editing, setEditing] = useState(null); // null closed, 'new', or policy object
    const form = useForm(EMPTY);

    const open = (policy = null) => {
        if (policy) {
            form.setData({
                ...policy,
                reminder_offsets: (policy.reminder_offsets ?? []).join(','),
            });
            setEditing(policy);
        } else {
            form.setData(EMPTY);
            setEditing('new');
        }
    };

    const close = () => { setEditing(null); form.reset(); form.clearErrors(); };

    const submit = (e) => {
        e.preventDefault();

        form.transform((data) => ({
            ...data,
            reminder_offsets: String(data.reminder_offsets)
                .split(',')
                .map((v) => parseInt(v.trim(), 10))
                .filter((v) => !Number.isNaN(v)),
        }));

        if (editing === 'new') {
            form.post(route('admin.exception-sla.store'), { preserveScroll: true, onSuccess: close });
        } else {
            form.put(route('admin.exception-sla.update', editing.id), { preserveScroll: true, onSuccess: close });
        }
    };

    return (
        <AuthenticatedLayout header="Exception SLA Policies">
            <Head title="Exception SLA Policies" />

            <PageHeader
                title="Exception SLA policies"
                subtitle="Business-day clocks per severity — acknowledgement, response, reminders and the hand-off to the escalation matrix"
                actions={<PrimaryButton onClick={() => open()}>+ New policy</PrimaryButton>}
            />

            <div className="card">
                <DataTable
                    emptyMessage="No SLA policies configured — escalations cannot be issued until one exists per severity."
                    columns={[
                        { field: 'name', label: 'Policy' },
                        { field: 'severity', label: 'Severity', render: (r) => <SeverityBadge severity={r.severity} /> },
                        { field: 'acknowledge_within_hours', label: 'Acknowledge (hrs)' },
                        { field: 'respond_within_days', label: 'Respond (bus. days)' },
                        { field: 'reminder_offsets', label: 'Reminder offsets', render: (r) => <span className="font-mono text-xs">{(r.reminder_offsets ?? []).join(', ')}</span> },
                        { field: 'max_reminders', label: 'Max reminders' },
                        { field: 'escalate_after_days', label: 'Ladder after (days)' },
                        {
                            field: 'is_active',
                            label: 'Active',
                            render: (r) => <span className={`badge ${r.is_active ? 'badge-status-active' : 'badge-status-draft'}`}>{r.is_active ? 'Active' : 'Inactive'}</span>,
                        },
                        {
                            field: 'id',
                            label: '',
                            render: (r) => (
                                <button type="button" className="text-sm text-[var(--color-primary)] hover:underline" onClick={() => open(r)}>
                                    Edit
                                </button>
                            ),
                        },
                    ]}
                    data={policies}
                />
            </div>

            <Modal show={editing !== null} onClose={close} title={editing === 'new' ? 'New SLA policy' : 'Edit SLA policy'} maxWidth="lg">
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Name" required />
                            <TextInput value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            <InputError message={form.errors.name} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Severity" required />
                            <SelectInput value={form.data.severity} onChange={(e) => form.setData('severity', e.target.value)}>
                                {severities.map((s) => <option key={s} value={s}>{s}</option>)}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Acknowledge within (hours)" required />
                            <TextInput type="number" min="1" value={form.data.acknowledge_within_hours} onChange={(e) => form.setData('acknowledge_within_hours', e.target.value)} />
                            <InputError message={form.errors.acknowledge_within_hours} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Respond within (business days)" required />
                            <TextInput type="number" min="1" value={form.data.respond_within_days} onChange={(e) => form.setData('respond_within_days', e.target.value)} />
                            <InputError message={form.errors.respond_within_days} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Reminder offsets (business days around due)" />
                            <TextInput value={form.data.reminder_offsets} onChange={(e) => form.setData('reminder_offsets', e.target.value)} placeholder="-3,-1,1,3,7" />
                            <InputError message={form.errors.reminder_offsets} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Max reminders" required />
                            <TextInput type="number" min="0" value={form.data.max_reminders} onChange={(e) => form.setData('max_reminders', e.target.value)} />
                        </div>
                        <div>
                            <InputLabel value="Hand to matrix after (business days past due)" required />
                            <TextInput type="number" min="0" value={form.data.escalate_after_days} onChange={(e) => form.setData('escalate_after_days', e.target.value)} />
                        </div>
                    </div>
                    <div className="flex items-center gap-6">
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" className="rounded border-gray-300" checked={!!form.data.auto_escalate_to_matrix} onChange={(e) => form.setData('auto_escalate_to_matrix', e.target.checked)} />
                            Auto-escalate to the matrix ladder
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" className="rounded border-gray-300" checked={!!form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                            Active
                        </label>
                    </div>
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={close}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={form.processing}>{editing === 'new' ? 'Create policy' : 'Save changes'}</PrimaryButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
