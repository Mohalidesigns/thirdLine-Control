import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import RichTextEditor from '@/Components/RichTextEditor';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Activity, CircleSlash, Clock, Plus } from 'lucide-react';

const SEVERITY_CLASSES = {
    Critical: 'badge-critical',
    High: 'badge-high',
    Medium: 'badge-medium',
    Low: 'badge-low',
};

const TYPE_LABELS = {
    threshold: 'Threshold',
    reconciliation: 'Reconciliation',
    duplicate: 'Duplicate',
    gap: 'Gap',
    sod_conflict: 'SoD conflict',
    exception_list: 'Exception list',
    completeness: 'Completeness',
    timeliness: 'Timeliness',
    trend: 'Trend',
    pattern: 'Pattern',
    custom_sql: 'Custom SQL',
};

export default function Index({
    rules,
    filters = {},
    options = {},
    schemas = {},
    datasets = [],
    controls = [],
    owners = [],
    stats = {},
    can = {},
}) {
    const [creating, setCreating] = useState(false);

    const columns = [
        {
            field: 'rule_ref',
            label: 'Ref',
            render: (row) => <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{row.rule_ref}</span>,
        },
        {
            field: 'name',
            label: 'Rule',
            render: (row) => (
                <div className="max-w-sm">
                    <p className="font-medium text-[var(--color-text-primary)]">{row.name}</p>
                    <p className="text-xs text-gray-400">
                        {TYPE_LABELS[row.rule_type] ?? row.rule_type} · {row.frequency} · {row.runs_count ?? 0} run(s)
                    </p>
                </div>
            ),
        },
        {
            field: 'control',
            label: 'Control',
            render: (row) =>
                row.control ? (
                    <div>
                        <p className="font-mono text-xs">{row.control.control_ref}</p>
                        {row.control.is_key_control && <p className="text-xs text-[var(--color-accent)]">Key control</p>}
                    </div>
                ) : (
                    <span className="text-xs text-gray-400">Not linked</span>
                ),
        },
        {
            field: 'severity',
            label: 'Severity',
            render: (row) => <span className={`badge ${SEVERITY_CLASSES[row.severity]}`}>{row.severity}</span>,
        },
        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
        {
            field: 'approved_by',
            label: 'Approval',
            render: (row) =>
                row.approved_at ? (
                    <span className="text-xs text-[var(--color-success)]">v{row.version_no} approved</span>
                ) : (
                    <span className="text-xs text-[var(--color-warning)]">Not approved</span>
                ),
        },
    ];

    return (
        <AuthenticatedLayout header="Monitoring Rules">
            <Head title="Monitoring Rules" />

            <PageHeader
                title="Monitoring rules"
                subtitle="Continuous tests of a control against real data. A rule runs only once a second person has approved it."
                breadcrumbs={[{ label: 'Continuous monitoring', href: route('monitoring.dashboard') }, { label: 'Rules' }]}
                actions={
                    <div className="flex flex-wrap gap-2">
                        <Link href={route('monitoring-runs.index')} className="btn-secondary">
                            Runs
                        </Link>
                        {can.manage && <PrimaryButton onClick={() => setCreating(true)}><Plus className="h-4 w-4" strokeWidth={2} /> New rule</PrimaryButton>}
                    </div>
                }
            />

            <div className="mb-6 grid grid-cols-3 gap-4">
                <StatCard icon={Activity} title="Active" value={stats.active ?? 0} color="success" />
                <StatCard icon={Clock}
                    title="Awaiting approval"
                    value={stats.pending ?? 0}
                    color="warning"
                    prominent={(stats.pending ?? 0) > 0}
                />
                <StatCard icon={CircleSlash} title="Paused" value={stats.paused ?? 0} color="info" />
            </div>

            <FilterBar
                route="monitoring-rules.index"
                resource="monitoring-rules"
                currentFilters={filters}
                searchPlaceholder="Search rules…"
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    { name: 'status', type: 'select', label: 'Status', options: options.statuses ?? [] },
                    {
                        name: 'rule_type',
                        type: 'select',
                        label: 'Type',
                        options: (options.types ?? []).map((t) => ({ value: t, label: TYPE_LABELS[t] ?? t })),
                    },
                    { name: 'severity', type: 'select', label: 'Severity', options: options.severities ?? [] },
                    {
                        name: 'control_id',
                        type: 'select',
                        label: 'Control',
                        options: controls.map((c) => ({ value: c.id, label: `${c.control_ref} — ${c.title}` })),
                    },
                ]}
            />

            <div className="card">
                <DataTable
                    columns={columns}
                    data={rules.data}
                    emptyMessage="No monitoring rules match the filters."
                    onRowClick={(row) => router.visit(route('monitoring-rules.show', row.id))}
                />
                <Pagination paginator={rules} />
            </div>

            <CreateModal
                show={creating}
                onClose={() => setCreating(false)}
                options={options}
                schemas={schemas}
                datasets={datasets}
                controls={controls}
                owners={owners}
            />
        </AuthenticatedLayout>
    );
}

function CreateModal({ show, onClose, options, schemas, datasets, controls, owners }) {
    const form = useForm({
        name: '',
        description: '',
        control_id: '',
        rule_type: 'exception_list',
        dataset_ids: [],
        rule_definition: {},
        severity: 'Medium',
        frequency: 'Daily',
        sample_config: { method: 'full' },
        auto_create_exception: true,
        auto_create_incident: false,
        owner_id: '',
        description_rich: null,
    });

    const [definitionText, setDefinitionText] = useState('{\n  "conditions": [\n    { "field": "status", "operator": "eq", "value": "FAILED" }\n  ]\n}');
    const [definitionError, setDefinitionError] = useState(null);

    const schema = schemas[form.data.rule_type];

    const toggleDataset = (id) => {
        const next = form.data.dataset_ids.includes(id)
            ? form.data.dataset_ids.filter((d) => d !== id)
            : [...form.data.dataset_ids, id];
        form.setData('dataset_ids', next);
    };

    const submit = (event) => {
        event.preventDefault();

        let parsed;
        try {
            parsed = JSON.parse(definitionText);
        } catch (e) {
            setDefinitionError('The rule definition is not valid JSON.');
            return;
        }

        setDefinitionError(null);
        form.transform((data) => ({ ...data, rule_definition: parsed }));
        form.post(route('monitoring-rules.store'), { onSuccess: onClose });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="4xl" title="New monitoring rule">
            <form onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel value="Name" required />
                        <TextInput
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            placeholder="Same-day maker and checker on GL postings"
                        />
                        {form.errors.name && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.name}</p>}
                    </div>
                    <div>
                        <InputLabel value="Control tested" />
                        <SelectInput value={form.data.control_id} onChange={(e) => form.setData('control_id', e.target.value)}>
                            <option value="">Not linked to a control</option>
                            {controls.map((control) => (
                                <option key={control.id} value={control.id}>
                                    {control.control_ref} — {control.title}
                                </option>
                            ))}
                        </SelectInput>
                        <p className="mt-1 text-xs text-gray-400">
                            A linked control gets a real test instance from every run, so the result feeds its effectiveness rating.
                        </p>
                    </div>
                </div>

                <div>
                    <InputLabel value="Description" />
                    <RichTextEditor
                        value={form.data.description_rich ?? form.data.description}
                        onChange={(doc, plain) => form.setData((d) => ({ ...d, description: plain, description_rich: doc }))}
                        tools="minimal"
                        minHeight={90}
                    />
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
                    <div>
                        <InputLabel value="Rule type" required />
                        <SelectInput value={form.data.rule_type} onChange={(e) => form.setData('rule_type', e.target.value)}>
                            {(options.types ?? []).map((type) => (
                                <option key={type} value={type}>
                                    {TYPE_LABELS[type] ?? type}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Severity" required />
                        <SelectInput value={form.data.severity} onChange={(e) => form.setData('severity', e.target.value)}>
                            {(options.severities ?? []).map((s) => (
                                <option key={s} value={s}>
                                    {s}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Frequency" required />
                        <SelectInput value={form.data.frequency} onChange={(e) => form.setData('frequency', e.target.value)}>
                            {(options.frequencies ?? []).map((f) => (
                                <option key={f} value={f}>
                                    {f}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Owner" />
                        <SelectInput value={form.data.owner_id} onChange={(e) => form.setData('owner_id', e.target.value)}>
                            <option value="">Unassigned</option>
                            {owners.map((owner) => (
                                <option key={owner.id} value={owner.id}>
                                    {owner.name}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                </div>

                <div>
                    <InputLabel value="Datasets" required />
                    {datasets.length === 0 ? (
                        <p className="text-sm text-[var(--color-warning)]">
                            No datasets are available. Register a data source and add a dataset first.
                        </p>
                    ) : (
                        <div className="mt-1 max-h-40 space-y-1 overflow-y-auto rounded-lg border border-gray-200 p-2">
                            {datasets.map((dataset) => (
                                <label key={dataset.id} className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        className="rounded border-gray-300"
                                        checked={form.data.dataset_ids.includes(dataset.id)}
                                        onChange={() => toggleDataset(dataset.id)}
                                    />
                                    <span>
                                        {dataset.name}
                                        <span className="text-xs text-gray-400">
                                            {' '}
                                            · {dataset.source} · table <span className="font-mono">{dataset.table_name}</span>
                                            {dataset.pii_classification !== 'none' && ` · ${dataset.pii_classification}`}
                                        </span>
                                    </span>
                                </label>
                            ))}
                        </div>
                    )}
                    {form.errors.dataset_ids && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.dataset_ids}</p>}
                </div>

                <div>
                    <InputLabel value="Rule definition (JSON)" required />
                    {schema && (
                        <p className="mb-1 text-xs text-gray-400">
                            {schema.description}
                            {schema.required.length > 0 && <> Required: <span className="font-mono">{schema.required.join(', ')}</span>.</>}
                            {schema.optional.length > 0 && <> Optional: <span className="font-mono">{schema.optional.join(', ')}</span>.</>}
                        </p>
                    )}
                    <textarea
                        className="form-input font-mono text-xs"
                        rows={8}
                        value={definitionText}
                        onChange={(e) => setDefinitionText(e.target.value)}
                    />
                    {definitionError && <p className="mt-1 text-xs text-[var(--color-error)]">{definitionError}</p>}
                    {form.errors.rule_definition && (
                        <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.rule_definition}</p>
                    )}
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <InputLabel value="Sampling" />
                        <SelectInput
                            value={form.data.sample_config.method}
                            onChange={(e) => form.setData('sample_config', { ...form.data.sample_config, method: e.target.value })}
                        >
                            {(options.sampling_methods ?? []).map((method) => (
                                <option key={method} value={method}>
                                    {method}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    {form.data.sample_config.method !== 'full' && (
                        <div>
                            <InputLabel value="Sample size" />
                            <TextInput
                                type="number"
                                value={form.data.sample_config.size ?? ''}
                                onChange={(e) =>
                                    form.setData('sample_config', { ...form.data.sample_config, size: e.target.value })
                                }
                            />
                        </div>
                    )}
                    <div className="flex items-end gap-4">
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="rounded border-gray-300"
                                checked={form.data.auto_create_exception}
                                onChange={(e) => form.setData('auto_create_exception', e.target.checked)}
                            />
                            Raise an exception on failure
                        </label>
                    </div>
                </div>

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Create as draft</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
