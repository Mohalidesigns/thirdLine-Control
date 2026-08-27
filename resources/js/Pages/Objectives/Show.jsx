import ConfirmDialog from '@/Components/ConfirmDialog';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import ProgressBar from '@/Components/ProgressBar';
import RelationshipsPanel from '@/Components/RelationshipsPanel';
import RichTextEditor from '@/Components/RichTextEditor';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import StatusBadge from '@/Components/StatusBadge';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate, formatNumber } from '@/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Plus } from 'lucide-react';

const CONTRIBUTION_LABELS = {
    metric: 'Indicator',
    initiative: 'Initiative',
    objective: 'Child objective',
};

export default function Show({
    objective,
    breakdown = { contributions: [], derived: null },
    links = [],
    metrics = [],
    owners = [],
    statuses = [],
    initiativeStatuses = [],
    can = {},
}) {
    const [reporting, setReporting] = useState(false);
    const [addingMeasure, setAddingMeasure] = useState(false);
    const [addingInitiative, setAddingInitiative] = useState(false);
    const [approving, setApproving] = useState(false);

    return (
        <AuthenticatedLayout header={objective.code}>
            <Head title={`${objective.code} — ${objective.title}`} />

            <PageHeader
                title={objective.title}
                subtitle={`${objective.perspective_label}${objective.period ? ` · ${objective.period}` : ''}${
                    objective.entity ? ` · ${objective.entity.legal_name}` : ' · Group-wide'
                }`}
                breadcrumbs={[
                    { label: 'Objectives', href: route('objectives.index') },
                    { label: objective.code },
                ]}
                actions={
                    <>
                        {can.approve && objective.status === 'Draft' && (
                            <PrimaryButton onClick={() => setApproving(true)}>Approve onto scorecard</PrimaryButton>
                        )}
                        {can.report && <SecondaryButton onClick={() => setReporting(true)}>Report progress</SecondaryButton>}
                        {can.report && objective.progress_mode === 'manual' && (
                            <SecondaryButton onClick={() => router.post(route('objectives.recalculate', objective.id))}>
                                Return to roll-up
                            </SecondaryButton>
                        )}
                    </>
                }
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <section className="card">
                        <div className="card-header flex items-center justify-between">
                            <h2 className="text-sm font-semibold text-[var(--color-primary)]">Progress</h2>
                            <StatusBadge status={objective.status} />
                        </div>
                        <div className="card-body">
                            <div className="flex items-end justify-between">
                                <span className="text-4xl font-bold tabular-nums" style={{ color: objective.band.colour }}>
                                    {objective.progress === null ? 'Not measured' : `${objective.progress}%`}
                                </span>
                                <span className="text-xs text-[var(--color-text-secondary)]">
                                    {objective.progress_mode === 'manual'
                                        ? `Reported by the owner${
                                              breakdown.derived === null ? '' : ` · measures say ${breakdown.derived}%`
                                          }`
                                        : 'Rolled up from the measures below'}
                                </span>
                            </div>
                            <div className="mt-3">
                                <ProgressBar value={objective.progress ?? 0} color={objective.band.colour} />
                            </div>

                            {breakdown.contributions.length === 0 ? (
                                <p className="mt-4 text-xs text-gray-400">
                                    Nothing measures this objective yet — add an indicator or an initiative below.
                                </p>
                            ) : (
                                <div className="mt-4 overflow-x-auto">
                                    <table className="data-table w-full min-w-[32rem]">
                                        <thead>
                                            <tr>
                                                <th>Contribution</th>
                                                <th className="text-right">Weight</th>
                                                <th className="text-right">Position</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {breakdown.contributions.map((row) => (
                                                <tr key={`${row.type}-${row.id}`}>
                                                    <td>
                                                        <span className="font-mono text-xs text-[var(--color-primary)]">{row.ref}</span>
                                                        <span className="ml-2 text-xs">{row.label}</span>
                                                        <span className="ml-2 text-[10px] uppercase tracking-wide text-gray-400">
                                                            {CONTRIBUTION_LABELS[row.type]}
                                                        </span>
                                                        {row.type === 'metric' && row.target !== null && (
                                                            <p className="text-[11px] text-gray-400">
                                                                {formatNumber(row.baseline ?? 0)} → {formatNumber(row.target)}
                                                                {row.latest !== null && row.latest !== undefined
                                                                    ? ` · now ${formatNumber(row.latest)} ${row.unit ?? ''}`
                                                                    : ' · no reading'}
                                                            </p>
                                                        )}
                                                    </td>
                                                    <td className="text-right tabular-nums">{row.weight}</td>
                                                    <td className="text-right font-semibold tabular-nums">
                                                        {row.progress === null ? (
                                                            <span className="text-xs font-normal text-gray-400">Not measured</span>
                                                        ) : (
                                                            `${row.progress}%`
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </section>

                    <section className="card">
                        <div className="card-header flex items-center justify-between">
                            <h2 className="text-sm font-semibold text-[var(--color-primary)]">Initiatives</h2>
                            {can.manage_initiatives && (
                                <button
                                    type="button"
                                    className="text-xs font-semibold text-[var(--color-primary)]"
                                    onClick={() => setAddingInitiative(true)}
                                >
                                    <Plus className="h-4 w-4" strokeWidth={2} /> New initiative
                                </button>
                            )}
                        </div>
                        <div className="card-body space-y-3">
                            {(objective.initiatives ?? []).length === 0 ? (
                                <p className="text-xs text-gray-400">No initiatives are delivering this objective.</p>
                            ) : (
                                objective.initiatives.map((initiative) => (
                                    <InitiativeRow
                                        key={initiative.id}
                                        initiative={initiative}
                                        canProgress={can.manage_initiatives}
                                    />
                                ))
                            )}
                        </div>
                    </section>

                    <RelationshipsPanel type="objective" id={objective.id} links={links} canManage={can.manage} />
                </div>

                <div className="space-y-6">
                    <section className="card">
                        <div className="card-header flex items-center justify-between">
                            <h2 className="text-sm font-semibold text-[var(--color-primary)]">Measures</h2>
                            {can.manage && (
                                <button
                                    type="button"
                                    className="text-xs font-semibold text-[var(--color-primary)]"
                                    onClick={() => setAddingMeasure(true)}
                                >
                                    <Plus className="h-4 w-4" strokeWidth={2} /> Add
                                </button>
                            )}
                        </div>
                        <div className="card-body space-y-2">
                            {(objective.measures ?? []).length === 0 ? (
                                <p className="text-xs text-gray-400">No indicators attached.</p>
                            ) : (
                                objective.measures.map((measure) => (
                                    <div key={measure.id} className="flex items-start justify-between gap-2 text-xs">
                                        <div>
                                            <Link
                                                href={route('metrics.show', measure.metric_id)}
                                                className="font-mono font-semibold text-[var(--color-primary)] hover:underline"
                                            >
                                                {measure.metric?.code}
                                            </Link>
                                            <p className="text-[var(--color-text-secondary)]">{measure.metric?.name}</p>
                                            <p className="text-gray-400">
                                                {measure.baseline_value === null ? '—' : formatNumber(measure.baseline_value)} →{' '}
                                                {measure.target_value === null ? 'no target' : formatNumber(measure.target_value)}
                                                {` · weight ${measure.weight}`}
                                            </p>
                                        </div>
                                        {can.manage && (
                                            <button
                                                type="button"
                                                className="text-[var(--color-error)]"
                                                onClick={() =>
                                                    router.delete(route('objectives.measures.destroy', [objective.id, measure.id]))
                                                }
                                            >
                                                Remove
                                            </button>
                                        )}
                                    </div>
                                ))
                            )}
                        </div>
                    </section>

                    <section className="card">
                        <div className="card-header">
                            <h2 className="text-sm font-semibold text-[var(--color-primary)]">Detail</h2>
                        </div>
                        <div className="card-body space-y-2 text-xs">
                            <Detail label="Owner" value={objective.owner?.name ?? 'Unowned'} />
                            <Detail label="Cascades from" value={objective.parent ? `${objective.parent.code} — ${objective.parent.title}` : 'Top level'} />
                            <Detail label="Weight" value={objective.weight} />
                            <Detail label="Start" value={objective.start_date ? formatDate(objective.start_date) : '—'} />
                            <Detail label="Target" value={objective.target_date ? formatDate(objective.target_date) : '—'} />
                            <Detail
                                label="Last calculated"
                                value={objective.progress_calculated_at ? formatDate(objective.progress_calculated_at) : 'Never'}
                            />
                            {objective.description && (
                                <p className="pt-2 text-[var(--color-text-secondary)]">{objective.description}</p>
                            )}
                        </div>
                    </section>

                    {(objective.children ?? []).length > 0 && (
                        <section className="card">
                            <div className="card-header">
                                <h2 className="text-sm font-semibold text-[var(--color-primary)]">Cascaded objectives</h2>
                            </div>
                            <div className="card-body space-y-2 text-xs">
                                {objective.children.map((child) => (
                                    <Link
                                        key={child.id}
                                        href={route('objectives.show', child.id)}
                                        className="flex items-center justify-between hover:underline"
                                    >
                                        <span>
                                            <span className="font-mono text-[var(--color-primary)]">{child.code}</span>{' '}
                                            {child.title}
                                        </span>
                                        <span className="tabular-nums">{child.progress}%</span>
                                    </Link>
                                ))}
                            </div>
                        </section>
                    )}
                </div>
            </div>

            <ReportModal
                show={reporting}
                onClose={() => setReporting(false)}
                objective={objective}
                statuses={statuses}
            />
            <MeasureModal
                show={addingMeasure}
                onClose={() => setAddingMeasure(false)}
                objective={objective}
                metrics={metrics}
            />
            <InitiativeModal
                show={addingInitiative}
                onClose={() => setAddingInitiative(false)}
                objective={objective}
                owners={owners}
                statuses={initiativeStatuses}
            />
            <ConfirmDialog
                show={approving}
                title="Approve onto the scorecard"
                message={`${objective.code} becomes a live board objective. You cannot approve an objective you drafted yourself.`}
                variant="primary"
                onCancel={() => setApproving(false)}
                onConfirm={() =>
                    router.post(route('objectives.approve', objective.id), {}, { onFinish: () => setApproving(false) })
                }
            />
        </AuthenticatedLayout>
    );
}

function Detail({ label, value }) {
    return (
        <div className="flex justify-between gap-3">
            <span className="text-[var(--color-text-secondary)]">{label}</span>
            <span className="text-right font-medium text-[var(--color-text-primary)]">{value}</span>
        </div>
    );
}

function InitiativeRow({ initiative, canProgress }) {
    return (
        <div className="rounded-lg border border-gray-100 p-3">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{initiative.reference}</span>
                    <p className="text-sm font-medium text-[var(--color-text-primary)]">{initiative.title}</p>
                    <p className="text-xs text-gray-400">
                        {initiative.owner?.name ?? 'Unowned'} · {initiative.status}
                        {initiative.end_date ? ` · due ${formatDate(initiative.end_date)}` : ''}
                    </p>
                </div>
                <span className="text-sm font-semibold tabular-nums">{initiative.progress}%</span>
            </div>

            {(initiative.milestones ?? []).length > 0 && (
                <ul className="mt-2 space-y-1">
                    {initiative.milestones.map((milestone) => (
                        <li key={milestone.id} className="flex items-center justify-between text-xs">
                            <span className={milestone.status === 'Complete' ? 'text-gray-400 line-through' : ''}>
                                {milestone.title}
                                {milestone.due_date ? ` · ${formatDate(milestone.due_date)}` : ''}
                            </span>
                            {canProgress && milestone.status !== 'Complete' && (
                                <button
                                    type="button"
                                    className="font-semibold text-[var(--color-primary)]"
                                    onClick={() =>
                                        router.post(route('initiatives.milestones.update', [initiative.id, milestone.id]), {
                                            status: 'Complete',
                                        })
                                    }
                                >
                                    Mark complete
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function ReportModal({ show, onClose, objective, statuses }) {
    const form = useForm({ progress: objective.progress ?? 0, status: objective.status, note: '' , note_rich: null});

    const submit = (event) => {
        event.preventDefault();
        form.post(route('objectives.progress', objective.id), { onSuccess: onClose });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="lg" title="Report progress">
            <form onSubmit={submit} className="space-y-4">
                <p className="text-xs text-[var(--color-text-secondary)]">
                    Reporting a figure switches this objective to owner-reported. The rolled-up number stays visible beside it,
                    and you can return to it at any time.
                </p>
                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel value="Progress %" required />
                        <TextInput
                            type="number"
                            min="0"
                            max="100"
                            value={form.data.progress}
                            onChange={(e) => form.setData('progress', e.target.value)}
                        />
                    </div>
                    <div>
                        <InputLabel value="Status" required />
                        <SelectInput value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                            {statuses.map((status) => (
                                <option key={status} value={status}>
                                    {status}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                </div>
                <div>
                    <InputLabel value="Note" />
                    <RichTextEditor
                        value={form.data.note_rich ?? form.data.note}
                        onChange={(doc, plain) => form.setData((d) => ({ ...d, note: plain, note_rich: doc }))}
                        tools="minimal"
                        minHeight={90}
                    />
                </div>
                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Record</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

function MeasureModal({ show, onClose, objective, metrics }) {
    const form = useForm({ metric_id: '', baseline_value: '', target_value: '', weight: 1, note: '' , note_rich: null});

    const submit = (event) => {
        event.preventDefault();
        form.post(route('objectives.measures.store', objective.id), { onSuccess: onClose });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="lg" title="Add a measure">
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel value="Indicator" required />
                    <SelectInput value={form.data.metric_id} onChange={(e) => form.setData('metric_id', e.target.value)}>
                        <option value="">Select…</option>
                        {metrics.map((metric) => (
                            <option key={metric.id} value={metric.id}>
                                {metric.code} — {metric.name}
                            </option>
                        ))}
                    </SelectInput>
                </div>
                <div className="grid grid-cols-3 gap-4">
                    <div>
                        <InputLabel value="Baseline" />
                        <TextInput
                            type="number"
                            step="any"
                            value={form.data.baseline_value}
                            onChange={(e) => form.setData('baseline_value', e.target.value)}
                        />
                    </div>
                    <div>
                        <InputLabel value="Target" />
                        <TextInput
                            type="number"
                            step="any"
                            value={form.data.target_value}
                            onChange={(e) => form.setData('target_value', e.target.value)}
                        />
                    </div>
                    <div>
                        <InputLabel value="Weight" />
                        <TextInput
                            type="number"
                            min="1"
                            value={form.data.weight}
                            onChange={(e) => form.setData('weight', e.target.value)}
                        />
                    </div>
                </div>
                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Add measure</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

function InitiativeModal({ show, onClose, objective, owners, statuses }) {
    const form = useForm({
        objective_id: objective.id,
        title: '',
        description: '',
        owner_id: '',
        status: 'Proposed',
        start_date: '',
        end_date: '',
        budget_minor: '',
        currency: 'NGN',
        progress_mode: 'auto',
        weight: 1,
        milestones: [],
    
        description_rich: null,
});

    const addMilestone = () =>
        form.setData('milestones', [...form.data.milestones, { title: '', due_date: '', status: 'Pending', weight: 1 }]);

    const setMilestone = (index, field, value) => {
        const next = [...form.data.milestones];
        next[index] = { ...next[index], [field]: value };
        form.setData('milestones', next);
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(route('initiatives.store'), { onSuccess: onClose });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl" title="New initiative">
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel value="Title" required />
                    <TextInput value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                    {form.errors.title && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.title}</p>}
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
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
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
                        <InputLabel value="Status" required />
                        <SelectInput value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                            {statuses.map((status) => (
                                <option key={status} value={status}>
                                    {status}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Start" />
                        <TextInput type="date" value={form.data.start_date} onChange={(e) => form.setData('start_date', e.target.value)} />
                    </div>
                    <div>
                        <InputLabel value="End" />
                        <TextInput type="date" value={form.data.end_date} onChange={(e) => form.setData('end_date', e.target.value)} />
                    </div>
                </div>
                <div className="grid grid-cols-3 gap-4">
                    <div className="col-span-2">
                        <InputLabel value="Budget (major units)" />
                        <TextInput
                            type="number"
                            min="0"
                            step="1"
                            value={form.data.budget_minor === '' ? '' : form.data.budget_minor / 100}
                            onChange={(e) =>
                                form.setData('budget_minor', e.target.value === '' ? '' : Math.round(Number(e.target.value) * 100))
                            }
                        />
                    </div>
                    <div>
                        <InputLabel value="Currency" required />
                        <SelectInput value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value)}>
                            {['NGN', 'GHS', 'KES', 'ZAR', 'USD', 'EUR', 'GBP'].map((code) => (
                                <option key={code} value={code}>
                                    {code}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                </div>

                <div>
                    <div className="mb-2 flex items-center justify-between">
                        <InputLabel value="Milestones" />
                        <button type="button" className="text-xs font-semibold text-[var(--color-primary)]" onClick={addMilestone}>
                            <Plus className="h-4 w-4" strokeWidth={2} /> Add milestone
                        </button>
                    </div>
                    <div className="space-y-2">
                        {form.data.milestones.map((milestone, index) => (
                            <div key={index} className="grid grid-cols-3 gap-2">
                                <TextInput
                                    className="col-span-2"
                                    placeholder="Milestone"
                                    value={milestone.title}
                                    onChange={(e) => setMilestone(index, 'title', e.target.value)}
                                />
                                <TextInput
                                    type="date"
                                    value={milestone.due_date}
                                    onChange={(e) => setMilestone(index, 'due_date', e.target.value)}
                                />
                            </div>
                        ))}
                    </div>
                </div>

                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Create initiative</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
