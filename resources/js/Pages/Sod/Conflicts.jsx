import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import StatCard from '@/Components/StatCard';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Activity, AlertCircle, Plus, ShieldAlert } from 'lucide-react';

const RISK_CLASSES = {
    Critical: 'badge-critical',
    High: 'badge-high',
    Medium: 'badge-medium',
    Low: 'badge-low',
};

const TYPE_LABELS = {
    create_approve: 'Create / approve',
    create_pay: 'Create / pay',
    maintain_reconcile: 'Maintain / reconcile',
    request_authorise: 'Request / authorise',
    custom: 'Custom',
};

export default function Conflicts({ rules, filters = {}, options = {}, controls = [], stats = {}, can = {} }) {
    const [editing, setEditing] = useState(null);
    const [creating, setCreating] = useState(false);

    const columns = [
        {
            field: 'name',
            label: 'Conflict',
            render: (row) => (
                <div className="max-w-sm">
                    <p className="font-medium">{row.name}</p>
                    {row.description && <p className="text-xs text-gray-400">{row.description}</p>}
                </div>
            ),
        },
        {
            field: 'functions',
            label: 'Toxic pair',
            render: (row) => (
                <div className="text-xs">
                    <span className="font-mono">{row.function_a}</span>
                    <span className="mx-1 text-gray-400">+</span>
                    <span className="font-mono">{row.function_b}</span>
                    <p className="mt-0.5 text-gray-400">
                        {row.system_key ?? 'any system'} · {TYPE_LABELS[row.conflict_type] ?? row.conflict_type}
                    </p>
                </div>
            ),
        },
        {
            field: 'risk_level',
            label: 'Risk',
            render: (row) => <span className={`badge ${RISK_CLASSES[row.risk_level]}`}>{row.risk_level}</span>,
        },
        {
            field: 'mitigating_control',
            label: 'Mitigating control',
            render: (row) =>
                row.mitigating_control ? (
                    <Link
                        href={route('controls.show', row.mitigating_control.id)}
                        className="font-mono text-xs text-[var(--color-primary)] hover:underline"
                    >
                        {row.mitigating_control.control_ref}
                    </Link>
                ) : (
                    <span className="text-xs text-gray-400">None named</span>
                ),
        },
        {
            field: 'open_violations_count',
            label: 'Open',
            render: (row) =>
                row.open_violations_count > 0 ? (
                    <Link
                        href={route('sod.violations', { rule_id: row.id })}
                        className="font-semibold text-[var(--color-error)] hover:underline"
                    >
                        {row.open_violations_count}
                    </Link>
                ) : (
                    <span className="text-gray-400">0</span>
                ),
        },
        {
            field: 'is_active',
            label: 'State',
            render: (row) => (
                <span className={`badge ${row.is_active ? 'badge-status-active' : 'badge-status-draft'}`}>
                    {row.is_active ? 'Active' : 'Inactive'}
                </span>
            ),
        },
    ];

    if (can.manage) {
        columns.push({
            field: 'actions',
            label: '',
            render: (row) => <SecondaryButton onClick={() => setEditing(row)}>Edit</SecondaryButton>,
        });
    }

    return (
        <AuthenticatedLayout header="Segregation of Duties">
            <Head title="SoD Conflict Matrix" />

            <PageHeader
                title="Segregation-of-duties conflict matrix"
                subtitle="Toxic combinations in the systems the institution runs, named in each system's own vocabulary"
                breadcrumbs={[{ label: 'Segregation of duties' }, { label: 'Conflict matrix' }]}
                actions={
                    <div className="flex flex-wrap gap-2">
                        <Link href={route('sod.violations')} className="btn-secondary">
                            Violations
                        </Link>
                        {can.manage && <PrimaryButton onClick={() => setCreating(true)}><Plus className="h-4 w-4" strokeWidth={2} /> New conflict</PrimaryButton>}
                    </div>
                }
            />

            <div className="mb-6 grid grid-cols-3 gap-4">
                <StatCard icon={Activity} title="Active conflict rules" value={stats.rules ?? 0} color="primary" />
                <StatCard icon={AlertCircle} title="Open violations" value={stats.open_violations ?? 0} color="warning" />
                <StatCard icon={ShieldAlert}
                    title="Critical unmitigated"
                    value={stats.unmitigated_critical ?? 0}
                    color="danger"
                    prominent={(stats.unmitigated_critical ?? 0) > 0}
                />
            </div>

            <FilterBar
                route="sod.conflicts"
                resource="sod-conflicts"
                currentFilters={filters}
                searchPlaceholder="Search conflicts or functions…"
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    { name: 'system_key', type: 'select', label: 'System', options: options.systems ?? [] },
                    {
                        name: 'conflict_type',
                        type: 'select',
                        label: 'Type',
                        options: (options.conflict_types ?? []).map((t) => ({ value: t, label: TYPE_LABELS[t] ?? t })),
                    },
                    { name: 'risk_level', type: 'select', label: 'Risk', options: options.risk_levels ?? [] },
                ]}
            />

            <div className="card">
                <DataTable columns={columns} data={rules.data} emptyMessage="No conflict rules match the filters." />
                <Pagination paginator={rules} />
            </div>

            <ConflictModal
                show={creating || !!editing}
                rule={editing}
                options={options}
                controls={controls}
                onClose={() => {
                    setCreating(false);
                    setEditing(null);
                }}
            />
        </AuthenticatedLayout>
    );
}

function ConflictModal({ show, rule, options, controls, onClose }) {
    const form = useForm({
        name: rule?.name ?? '',
        description: rule?.description ?? '',
        system_key: rule?.system_key ?? '',
        function_a: rule?.function_a ?? '',
        function_b: rule?.function_b ?? '',
        conflict_type: rule?.conflict_type ?? 'create_approve',
        risk_level: rule?.risk_level ?? 'High',
        mitigating_control_id: rule?.mitigating_control_id ?? '',
        is_active: rule?.is_active ?? true,
    });

    const submit = (event) => {
        event.preventDefault();

        if (rule) {
            form.put(route('sod.conflicts.update', rule.id), { onSuccess: onClose, preserveScroll: true });
        } else {
            form.post(route('sod.conflicts.store'), { onSuccess: onClose, preserveScroll: true });
        }
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl" title={rule ? 'Edit conflict rule' : 'New conflict rule'}>
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel value="Name" required />
                    <TextInput
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        placeholder="Post and authorise a GL entry"
                    />
                    {form.errors.name && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.name}</p>}
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <InputLabel value="System" />
                        <TextInput
                            value={form.data.system_key}
                            onChange={(e) => form.setData('system_key', e.target.value)}
                            placeholder="finacle"
                        />
                        <p className="mt-1 text-xs text-gray-400">Leave blank to apply to every system.</p>
                    </div>
                    <div>
                        <InputLabel value="Function A" required />
                        <TextInput
                            value={form.data.function_a}
                            onChange={(e) => form.setData('function_a', e.target.value)}
                            placeholder="TXN_ENTRY"
                        />
                        {form.errors.function_a && (
                            <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.function_a}</p>
                        )}
                    </div>
                    <div>
                        <InputLabel value="Function B" required />
                        <TextInput
                            value={form.data.function_b}
                            onChange={(e) => form.setData('function_b', e.target.value)}
                            placeholder="TXN_AUTH"
                        />
                        {form.errors.function_b && (
                            <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.function_b}</p>
                        )}
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <InputLabel value="Conflict type" required />
                        <SelectInput
                            value={form.data.conflict_type}
                            onChange={(e) => form.setData('conflict_type', e.target.value)}
                        >
                            {(options.conflict_types ?? []).map((type) => (
                                <option key={type} value={type}>
                                    {TYPE_LABELS[type] ?? type}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Risk level" required />
                        <SelectInput value={form.data.risk_level} onChange={(e) => form.setData('risk_level', e.target.value)}>
                            {(options.risk_levels ?? []).map((level) => (
                                <option key={level} value={level}>
                                    {level}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Mitigating control" />
                        <SelectInput
                            value={form.data.mitigating_control_id ?? ''}
                            onChange={(e) => form.setData('mitigating_control_id', e.target.value)}
                        >
                            <option value="">None</option>
                            {controls.map((control) => (
                                <option key={control.id} value={control.id}>
                                    {control.control_ref} — {control.title}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                </div>

                <div>
                    <InputLabel value="Description" />
                    <TextArea
                        rows={2}
                        value={form.data.description}
                        onChange={(e) => form.setData('description', e.target.value)}
                    />
                </div>

                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        className="rounded border-gray-300"
                        checked={form.data.is_active}
                        onChange={(e) => form.setData('is_active', e.target.checked)}
                    />
                    Active — evaluated on every entitlement sweep
                </label>

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>{rule ? 'Save' : 'Add conflict'}</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
