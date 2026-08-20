import DataTable from '@/Components/DataTable';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import SeverityBadge from '@/Components/SeverityBadge';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDateTime } from '@/utils';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Plus } from 'lucide-react';

const TRIGGERS = {
    exception_unassigned: 'Exception unassigned after N days',
    exception_overdue: 'Exception past due date + N days',
    exception_inactive: 'Exception with no activity for N days',
    test_overdue: 'Test instance overdue by N days',
    extension_pending: 'Extension request pending N days',
};

export default function EscalationMatrix({ rules = [], events = [], users = [], tierRoles = {} }) {
    const [showForm, setShowForm] = useState(false);
    const [editing, setEditing] = useState(null);

    const form = useForm({
        severity: 'Critical',
        tier_no: 1,
        trigger_condition: 'exception_overdue',
        days_threshold: 0,
        recipient_role: 'Control Owner',
        recipient_user_id: '',
        channel: 'both',
        is_active: true,
    });

    const openNew = () => {
        form.reset();
        setEditing(null);
        setShowForm(true);
    };

    const openEdit = (rule) => {
        Object.entries({
            severity: rule.severity,
            tier_no: rule.tier_no,
            trigger_condition: rule.trigger_condition,
            days_threshold: rule.days_threshold,
            recipient_role: rule.recipient_role,
            recipient_user_id: rule.recipient_user_id ?? '',
            channel: rule.channel,
            is_active: !!rule.is_active,
        }).forEach(([key, value]) => form.setData(key, value));
        setEditing(rule);
        setShowForm(true);
    };

    const submit = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setShowForm(false) };
        if (editing) {
            form.put(route('admin.escalation-matrix.update', editing.id), options);
        } else {
            form.post(route('admin.escalation-matrix.store'), options);
        }
    };

    return (
        <AuthenticatedLayout header="Escalation Matrix">
            <Head title="Escalation Matrix" />

            <PageHeader
                title="Escalation matrix"
                subtitle="Tiered escalation rules per severity — Critical escalates faster and further than Low"
                actions={<PrimaryButton onClick={openNew}><Plus className="h-4 w-4" strokeWidth={2} /> Add rule</PrimaryButton>}
            />

            <div className="card mb-6">
                <DataTable
                    columns={[
                        { field: 'severity', label: 'Severity', render: (row) => <SeverityBadge severity={row.severity} /> },
                        { field: 'tier_no', label: 'Tier', render: (row) => `Tier ${row.tier_no} — ${tierRoles[row.tier_no] ?? ''}` },
                        { field: 'trigger_condition', label: 'Trigger', render: (row) => TRIGGERS[row.trigger_condition] ?? row.trigger_condition },
                        { field: 'days_threshold', label: 'Days', render: (row) => `${row.days_threshold}d` },
                        { field: 'recipient_role', label: 'Recipient', render: (row) => row.recipient?.name ?? row.recipient_role },
                        { field: 'channel', label: 'Channel' },
                        {
                            field: 'is_active',
                            label: 'Active',
                            render: (row) => (
                                <span className={`badge ${row.is_active ? 'badge-status-active' : 'badge-status-draft'}`}>
                                    {row.is_active ? 'Active' : 'Off'}
                                </span>
                            ),
                        },
                        {
                            field: 'actions',
                            label: '',
                            render: (row) => (
                                <div className="flex gap-2">
                                    <button type="button" className="text-xs text-[var(--color-primary)] hover:underline" onClick={() => openEdit(row)}>
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        className="text-xs text-[var(--color-error)] hover:underline"
                                        onClick={() => router.delete(route('admin.escalation-matrix.destroy', row.id), { preserveScroll: true })}
                                    >
                                        Delete
                                    </button>
                                </div>
                            ),
                        },
                    ]}
                    data={rules}
                    emptyMessage="No escalation rules configured."
                />
            </div>

            <div className="card">
                <div className="card-header"><h3 className="text-sm font-semibold">Recent escalation events</h3></div>
                <DataTable
                    columns={[
                        { field: 'triggered_at', label: 'When', render: (row) => formatDateTime(row.triggered_at) },
                        { field: 'exception', label: 'Item', render: (row) => <span className="font-mono text-xs">{row.exception?.reference ?? `Test #${row.test_instance_id}`}</span> },
                        { field: 'tier_no', label: 'Tier' },
                        { field: 'recipient', label: 'Recipient', render: (row) => row.recipient?.name ?? '—' },
                        {
                            field: 'delivery_status',
                            label: 'Delivery',
                            render: (row) => (
                                <span className={`badge ${row.delivery_status === 'Sent' ? 'badge-status-active' : row.delivery_status === 'Failed' ? 'badge-status-overdue' : 'badge-status-pending'}`}>
                                    {row.delivery_status}
                                </span>
                            ),
                        },
                    ]}
                    data={events}
                    emptyMessage="No escalations fired yet."
                />
            </div>

            <Modal show={showForm} onClose={() => setShowForm(false)} title={editing ? 'Edit rule' : 'New escalation rule'} maxWidth="lg">
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel value="Severity" required />
                            <SelectInput value={form.data.severity} onChange={(e) => form.setData('severity', e.target.value)}>
                                {['Critical', 'High', 'Medium', 'Low'].map((s) => <option key={s} value={s}>{s}</option>)}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Tier" required />
                            <SelectInput value={form.data.tier_no} onChange={(e) => form.setData('tier_no', Number(e.target.value))}>
                                {Object.entries(tierRoles).map(([tier, role]) => (
                                    <option key={tier} value={tier}>Tier {tier} — {role}</option>
                                ))}
                            </SelectInput>
                        </div>
                        <div className="col-span-2">
                            <InputLabel value="Trigger" required />
                            <SelectInput value={form.data.trigger_condition} onChange={(e) => form.setData('trigger_condition', e.target.value)}>
                                {Object.entries(TRIGGERS).map(([value, label]) => (
                                    <option key={value} value={value}>{label}</option>
                                ))}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Days threshold" required />
                            <TextInput type="number" min="0" value={form.data.days_threshold} onChange={(e) => form.setData('days_threshold', Number(e.target.value))} />
                        </div>
                        <div>
                            <InputLabel value="Channel" required />
                            <SelectInput value={form.data.channel} onChange={(e) => form.setData('channel', e.target.value)}>
                                <option value="both">In-app + Email</option>
                                <option value="in_app">In-app only</option>
                                <option value="email">Email only</option>
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Recipient role" required />
                            <SelectInput value={form.data.recipient_role} onChange={(e) => form.setData('recipient_role', e.target.value)}>
                                {['Control Owner', 'Line Manager', 'Control Function Head', 'Executive Viewer', 'System Administrator'].map((role) => (
                                    <option key={role} value={role}>{role}</option>
                                ))}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Specific user (optional)" />
                            <SelectInput value={form.data.recipient_user_id} onChange={(e) => form.setData('recipient_user_id', e.target.value)}>
                                <option value="">— By role —</option>
                                {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                            </SelectInput>
                        </div>
                    </div>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            className="rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                            checked={form.data.is_active}
                            onChange={(e) => form.setData('is_active', e.target.checked)}
                        />
                        <span className="text-sm text-gray-600">Rule is active</span>
                    </label>
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={() => setShowForm(false)}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={form.processing}>{editing ? 'Save' : 'Add rule'}</PrimaryButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
