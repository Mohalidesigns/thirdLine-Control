import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import ProgressBar from '@/Components/ProgressBar';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({
    objectives,
    filters = {},
    perspectives = [],
    statuses = [],
    periods = [],
    entities = [],
    owners = [],
    parents = [],
    metrics = [],
    stats = {},
    can = {},
}) {
    const [creating, setCreating] = useState(false);

    const columns = [
        {
            field: 'code',
            label: 'Code',
            render: (row) => <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{row.code}</span>,
        },
        {
            field: 'title',
            label: 'Objective',
            render: (row) => (
                <div className="max-w-sm">
                    <p className="font-medium text-[var(--color-text-primary)]">{row.title}</p>
                    <p className="text-xs text-gray-400">
                        {row.perspective_label}
                        {row.parent ? ` · under ${row.parent.code}` : ''}
                        {row.period ? ` · ${row.period}` : ''}
                    </p>
                </div>
            ),
        },
        {
            field: 'progress',
            label: 'Progress',
            render: (row) => (
                <div className="w-32">
                    <div className="mb-1 flex items-center justify-between text-xs">
                        <span className="font-semibold tabular-nums" style={{ color: row.band.colour }}>
                            {row.progress === null ? 'Not measured' : `${row.progress}%`}
                        </span>
                        <span className="text-gray-400">{row.progress_mode === 'manual' ? 'reported' : 'rolled up'}</span>
                    </div>
                    <ProgressBar value={row.progress ?? 0} color={row.band.colour} />
                </div>
            ),
        },
        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
        {
            field: 'measures_count',
            label: 'Measures',
            render: (row) => (
                <span className="text-xs text-[var(--color-text-secondary)]">
                    {row.measures_count} KRI · {row.initiatives_count} initiative{row.initiatives_count === 1 ? '' : 's'}
                </span>
            ),
        },
        { field: 'owner', label: 'Owner', render: (row) => row.owner?.name ?? '—' },
        {
            field: 'days_to_target',
            label: 'Target',
            render: (row) =>
                row.days_to_target === null ? (
                    <span className="text-xs text-gray-400">—</span>
                ) : (
                    <span className={`text-xs ${row.days_to_target < 0 ? 'font-semibold text-[var(--color-error)]' : ''}`}>
                        {row.days_to_target < 0 ? `${Math.abs(row.days_to_target)}d overdue` : `${row.days_to_target}d`}
                    </span>
                ),
        },
    ];

    return (
        <AuthenticatedLayout header="Strategic Objectives">
            <Head title="Strategic Objectives" />

            <PageHeader
                title="Strategic objectives"
                subtitle="What the institution is trying to achieve — and what the risk and control position says about whether it will"
                actions={
                    <>
                        <Link href={route('strategy.map')} className="btn-secondary">
                            Strategy map
                        </Link>
                        <Link href={route('strategy.scorecard')} className="btn-secondary">
                            Scorecard
                        </Link>
                        {can.manage && <PrimaryButton onClick={() => setCreating(true)}>+ New objective</PrimaryButton>}
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
                <StatCard title="Live objectives" value={stats.active ?? 0} color="primary" />
                <StatCard title="At risk" value={stats.at_risk ?? 0} color="danger" prominent={(stats.at_risk ?? 0) > 0} />
                <StatCard title="Achieved" value={stats.achieved ?? 0} color="success" />
                <StatCard title="Live initiatives" value={stats.initiatives ?? 0} color="info" />
            </div>

            <FilterBar
                route="objectives.index"
                resource="objectives"
                currentFilters={filters}
                searchPlaceholder="Search objectives…"
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    {
                        name: 'perspective',
                        type: 'select',
                        label: 'Perspective',
                        options: perspectives.map((p) => ({ value: p.value, label: p.label })),
                    },
                    { name: 'status', type: 'select', label: 'Status', options: statuses },
                    { name: 'period', type: 'select', label: 'Period', options: periods.map((p) => ({ value: p, label: p })) },
                    {
                        name: 'entity_id',
                        type: 'select',
                        label: 'Entity',
                        options: entities.map((e) => ({ value: e.id, label: e.legal_name })),
                    },
                    { name: 'owner_id', type: 'select', label: 'Owner', options: owners.map((o) => ({ value: o.id, label: o.name })) },
                ]}
            />

            <div className="card">
                <DataTable
                    columns={columns}
                    data={objectives.data}
                    emptyMessage="No objectives match the filters."
                    onRowClick={(row) => router.visit(route('objectives.show', row.id))}
                />
                <Pagination paginator={objectives} />
            </div>

            <CreateModal
                show={creating}
                onClose={() => setCreating(false)}
                perspectives={perspectives}
                entities={entities}
                owners={owners}
                parents={parents}
                metrics={metrics}
            />
        </AuthenticatedLayout>
    );
}

function CreateModal({ show, onClose, perspectives, entities, owners, parents, metrics }) {
    const form = useForm({
        perspective: 'financial',
        code: '',
        title: '',
        description: '',
        entity_id: '',
        parent_objective_id: '',
        owner_id: '',
        period: '',
        start_date: '',
        target_date: '',
        progress_mode: 'auto',
        weight: 1,
        measures: [],
    });

    const addMeasure = () =>
        form.setData('measures', [...form.data.measures, { metric_id: '', baseline_value: '', target_value: '', weight: 1 }]);

    const setMeasure = (index, field, value) => {
        const next = [...form.data.measures];
        next[index] = { ...next[index], [field]: value };
        form.setData('measures', next);
    };

    const removeMeasure = (index) =>
        form.setData(
            'measures',
            form.data.measures.filter((_, i) => i !== index),
        );

    const submit = (event) => {
        event.preventDefault();
        form.post(route('objectives.store'), { onSuccess: onClose });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="3xl" title="New strategic objective">
            <form onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
                    <div>
                        <InputLabel value="Perspective" required />
                        <SelectInput value={form.data.perspective} onChange={(e) => form.setData('perspective', e.target.value)}>
                            {perspectives.map((p) => (
                                <option key={p.value} value={p.value}>
                                    {p.label}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Code" required />
                        <TextInput value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} placeholder="OBJ-FIN-1" />
                        {form.errors.code && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.code}</p>}
                    </div>
                    <div className="sm:col-span-2">
                        <InputLabel value="Title" required />
                        <TextInput value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                        {form.errors.title && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.title}</p>}
                    </div>
                </div>

                <div>
                    <InputLabel value="Description" />
                    <TextArea rows={2} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
                    <div>
                        <InputLabel value="Owner" />
                        <SelectInput value={form.data.owner_id} onChange={(e) => form.setData('owner_id', e.target.value)}>
                            <option value="">—</option>
                            {owners.map((owner) => (
                                <option key={owner.id} value={owner.id}>
                                    {owner.name}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Entity" />
                        <SelectInput value={form.data.entity_id} onChange={(e) => form.setData('entity_id', e.target.value)}>
                            <option value="">Group-wide</option>
                            {entities.map((entity) => (
                                <option key={entity.id} value={entity.id}>
                                    {entity.legal_name}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Cascades from" />
                        <SelectInput
                            value={form.data.parent_objective_id}
                            onChange={(e) => form.setData('parent_objective_id', e.target.value)}
                        >
                            <option value="">Top level</option>
                            {parents.map((parent) => (
                                <option key={parent.id} value={parent.id}>
                                    {parent.code} — {parent.title}
                                </option>
                            ))}
                        </SelectInput>
                        {form.errors.parent_objective_id && (
                            <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.parent_objective_id}</p>
                        )}
                    </div>
                    <div>
                        <InputLabel value="Period" />
                        <TextInput value={form.data.period} onChange={(e) => form.setData('period', e.target.value)} placeholder="FY2026" />
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <InputLabel value="Start date" />
                        <TextInput type="date" value={form.data.start_date} onChange={(e) => form.setData('start_date', e.target.value)} />
                    </div>
                    <div>
                        <InputLabel value="Target date" />
                        <TextInput type="date" value={form.data.target_date} onChange={(e) => form.setData('target_date', e.target.value)} />
                        {form.errors.target_date && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.target_date}</p>}
                    </div>
                    <div>
                        <InputLabel value="Progress" required />
                        <SelectInput value={form.data.progress_mode} onChange={(e) => form.setData('progress_mode', e.target.value)}>
                            <option value="auto">Rolled up</option>
                            <option value="manual">Reported by owner</option>
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Weight" required />
                        <TextInput
                            type="number"
                            min="1"
                            value={form.data.weight}
                            onChange={(e) => form.setData('weight', e.target.value)}
                        />
                    </div>
                </div>

                <div>
                    <div className="mb-2 flex items-center justify-between">
                        <InputLabel value="Measures" />
                        <button type="button" className="text-xs font-semibold text-[var(--color-primary)]" onClick={addMeasure}>
                            + Add measure
                        </button>
                    </div>
                    <p className="mb-2 text-xs text-[var(--color-text-secondary)]">
                        Each measure scales its indicator's latest reading from baseline to target. A measure with no target is
                        shown but not scored — an unmeasured objective is unknown, not failing.
                    </p>
                    <div className="space-y-2">
                        {form.data.measures.map((measure, index) => (
                            <div key={index} className="grid grid-cols-2 gap-2 sm:grid-cols-5">
                                <SelectInput value={measure.metric_id} onChange={(e) => setMeasure(index, 'metric_id', e.target.value)}>
                                    <option value="">Select indicator…</option>
                                    {metrics.map((metric) => (
                                        <option key={metric.id} value={metric.id}>
                                            {metric.code} — {metric.name}
                                        </option>
                                    ))}
                                </SelectInput>
                                <TextInput
                                    type="number"
                                    step="any"
                                    placeholder="Baseline"
                                    value={measure.baseline_value}
                                    onChange={(e) => setMeasure(index, 'baseline_value', e.target.value)}
                                />
                                <TextInput
                                    type="number"
                                    step="any"
                                    placeholder="Target"
                                    value={measure.target_value}
                                    onChange={(e) => setMeasure(index, 'target_value', e.target.value)}
                                />
                                <TextInput
                                    type="number"
                                    min="1"
                                    placeholder="Weight"
                                    value={measure.weight}
                                    onChange={(e) => setMeasure(index, 'weight', e.target.value)}
                                />
                                <button
                                    type="button"
                                    className="text-xs text-[var(--color-error)]"
                                    onClick={() => removeMeasure(index)}
                                >
                                    Remove
                                </button>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Draft objective</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
