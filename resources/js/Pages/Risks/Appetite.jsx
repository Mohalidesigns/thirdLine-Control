import DataTable from '@/Components/DataTable';
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
import { formatDate } from '@/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Activity, AlertTriangle, CalendarClock, Plus } from 'lucide-react';

const POSITION_CLASSES = {
    'Within appetite': 'badge-low',
    'Below appetite': 'badge-status-draft',
    'Outside tolerance': 'badge-high',
    'Beyond capacity': 'badge-critical',
    Unknown: 'badge-status-draft',
};

export default function Appetite({
    appetites,
    positions = [],
    uncovered = [],
    filters = {},
    statuses = [],
    levels = [],
    riskCategories = [],
    entities = [],
    can = {},
}) {
    const [creating, setCreating] = useState(false);

    const breaching = positions.filter((position) => position.breach_count > 0).length;

    const columns = [
        {
            field: 'category',
            label: 'Category',
            render: (row) => (
                <div>
                    <p className="font-medium text-[var(--color-text-primary)]">{row.category?.name ?? 'All categories'}</p>
                    <p className="text-xs text-gray-400">{row.entity?.name ?? 'Group-wide'}</p>
                </div>
            ),
        },
        {
            field: 'statement',
            label: 'Statement',
            render: (row) => <p className="max-w-md text-sm text-[var(--color-text-primary)]">{row.statement}</p>,
        },
        { field: 'appetite_level', label: 'Level', render: (row) => <span className="badge badge-status-draft">{row.appetite_level}</span> },
        {
            field: 'tolerance_upper',
            label: 'Tolerance',
            render: (row) => (
                <span className="text-sm tabular-nums">
                    {row.tolerance_lower ?? '—'} – {row.tolerance_upper ?? '—'}
                    <span className="text-xs text-gray-400"> (cap {row.capacity ?? '—'})</span>
                </span>
            ),
        },
        { field: 'version_no', label: 'Version', render: (row) => <span className="font-mono text-xs">v{row.version_no}</span> },
        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
        {
            field: 'actions',
            label: '',
            render: (row) => <RowActions row={row} can={can} />,
        },
    ];

    return (
        <AuthenticatedLayout header="Risk Appetite">
            <Head title="Risk Appetite" />

            <PageHeader
                title="Risk Appetite"
                subtitle="Board-approved statements, tolerance bands and the current position against each"
                breadcrumbs={[{ label: 'Risk Register', href: route('risks.index') }, { label: 'Appetite' }]}
                actions={
                    <>
                        <Link href={route('risks.heatmap', { breach_only: 1 })} className="btn-secondary">
                            Breaching risks
                        </Link>
                        {can.manage && <PrimaryButton onClick={() => setCreating(true)}><Plus className="h-4 w-4" strokeWidth={2} /> New statement</PrimaryButton>}
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
                <StatCard icon={Activity} title="Active statements" value={positions.length} color="primary" />
                <StatCard icon={AlertTriangle} title="Categories breaching" value={breaching} color="danger" prominent={breaching > 0} />
                <StatCard icon={AlertTriangle} title="Categories with no statement" value={uncovered.length} color="warning" />
                <StatCard icon={CalendarClock} tone="amber"
                    title="Due for review"
                    value={positions.filter((p) => p.review_due_at && new Date(p.review_due_at) <= new Date()).length}
                    color="info"
                />
            </div>

            <div className="card mb-6">
                <div className="card-header">
                    <h3 className="text-sm font-semibold">Board position</h3>
                </div>
                <div className="card-body space-y-4">
                    {positions.length === 0 && (
                        <p className="text-sm text-[var(--color-text-secondary)]">No active appetite statement yet.</p>
                    )}
                    {positions.map((position) => (
                        <PositionRow key={position.id} position={position} />
                    ))}
                </div>
            </div>

            {uncovered.length > 0 && (
                <div className="mb-6 rounded-xl border border-amber-100 bg-amber-50 p-4">
                    <p className="text-sm font-semibold text-[var(--color-warning)]">
                        {uncovered.length} risk categor{uncovered.length === 1 ? 'y has' : 'ies have'} no appetite statement
                    </p>
                    <p className="mt-1 text-sm text-[var(--color-text-primary)]">
                        {uncovered.map((category) => category.name).join(', ')}
                    </p>
                </div>
            )}

            <div className="card">
                <DataTable columns={columns} data={appetites.data} emptyMessage="No appetite statements recorded." />
                <Pagination paginator={appetites} />
            </div>

            <CreateModal
                show={creating}
                onClose={() => setCreating(false)}
                levels={levels}
                riskCategories={riskCategories}
                entities={entities}
            />
        </AuthenticatedLayout>
    );
}

function PositionRow({ position }) {
    const upper = position.capacity ?? position.tolerance_upper ?? 25;
    const current = position.current_position ?? 0;
    const percent = Math.min(100, Math.max(0, (current / (upper || 1)) * 100));
    const tolerancePercent = Math.min(100, ((position.tolerance_upper ?? upper) / (upper || 1)) * 100);

    return (
        <div>
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="text-sm font-medium text-[var(--color-text-primary)]">
                    {position.category} <span className="text-xs text-gray-400">· {position.entity}</span>
                </p>
                <div className="flex items-center gap-2">
                    <span className="text-xs text-[var(--color-text-secondary)]">
                        {position.risk_count} risk{position.risk_count === 1 ? '' : 's'}
                    </span>
                    <span className={`badge ${POSITION_CLASSES[position.status] ?? 'badge-status-draft'}`}>
                        {position.status}
                    </span>
                </div>
            </div>

            <div className="relative mt-2 h-3 w-full overflow-hidden rounded-full bg-gray-100">
                <div
                    className="h-full rounded-full transition-all duration-500"
                    style={{
                        width: `${percent}%`,
                        backgroundColor:
                            position.status === 'Within appetite' || position.status === 'Below appetite'
                                ? 'var(--color-success)'
                                : position.status === 'Outside tolerance'
                                  ? 'var(--color-warning)'
                                  : 'var(--color-error)',
                    }}
                />
                <div
                    className="absolute top-0 h-full border-e-2 border-dashed border-[var(--color-text-secondary)]"
                    style={{ width: `${tolerancePercent}%` }}
                    title={`Tolerance ${position.tolerance_upper ?? '—'}`}
                />
            </div>

            <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                Current {position.current_position ?? '—'} · tolerance ≤ {position.tolerance_upper ?? '—'} · capacity{' '}
                {position.capacity ?? '—'}
                {position.review_due_at ? ` · review due ${formatDate(position.review_due_at)}` : ''}
            </p>
        </div>
    );
}

function RowActions({ row, can }) {
    if (row.status === 'Draft' && can.manage) {
        return (
            <button
                type="button"
                className="btn-primary !px-2 !py-1 text-xs"
                onClick={() => router.post(route('appetite.submit', row.id), {}, { preserveScroll: true })}
            >
                Submit
            </button>
        );
    }

    if (row.status === 'Pending Approval' && can.approve) {
        return (
            <button
                type="button"
                className="btn-success !px-2 !py-1 text-xs"
                onClick={() => router.post(route('appetite.approve', row.id), {}, { preserveScroll: true })}
            >
                Approve
            </button>
        );
    }

    return null;
}

function CreateModal({ show, onClose, levels, riskCategories, entities }) {
    const form = useForm({
        risk_category_id: '',
        entity_id: '',
        statement: '',
        appetite_level: 'Cautious',
        tolerance_lower: '',
        tolerance_upper: '',
        capacity: '',
        metric_definition: '',
        effective_from: '',
        review_due_at: '',
        metric_definition_rich: null,
        statement_rich: null,
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('appetite.store'), {
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl" title="New appetite statement">
            <form onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel value="Risk category" />
                        <SelectInput value={form.data.risk_category_id} onChange={(e) => form.setData('risk_category_id', e.target.value)}>
                            <option value="">All categories</option>
                            {riskCategories.map((category) => (
                                <option key={category.id} value={category.id}>
                                    {category.name}
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
                                    {entity.name}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                </div>

                <div>
                    <InputLabel value="Statement" required />
                    <RichTextEditor
                        value={form.data.statement_rich ?? form.data.statement}
                        onChange={(doc, plain) => form.setData((d) => ({ ...d, statement: plain, statement_rich: doc }))}
                        tools="minimal"
                        minHeight={90}
                    />
                    {form.errors.statement && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.statement}</p>}
                </div>

                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <InputLabel value="Level" required />
                        <SelectInput value={form.data.appetite_level} onChange={(e) => form.setData('appetite_level', e.target.value)}>
                            {levels.map((level) => (
                                <option key={level} value={level}>
                                    {level}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Tolerance from" />
                        <TextInput type="number" step="0.01" value={form.data.tolerance_lower} onChange={(e) => form.setData('tolerance_lower', e.target.value)} />
                    </div>
                    <div>
                        <InputLabel value="Tolerance to" />
                        <TextInput type="number" step="0.01" value={form.data.tolerance_upper} onChange={(e) => form.setData('tolerance_upper', e.target.value)} />
                        {form.errors.tolerance_upper && (
                            <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.tolerance_upper}</p>
                        )}
                    </div>
                    <div>
                        <InputLabel value="Capacity" />
                        <TextInput type="number" step="0.01" value={form.data.capacity} onChange={(e) => form.setData('capacity', e.target.value)} />
                        {form.errors.capacity && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.capacity}</p>}
                    </div>
                </div>

                <div>
                    <InputLabel value="How the position is measured" />
                    <RichTextEditor
                        value={form.data.metric_definition_rich ?? form.data.metric_definition}
                        onChange={(doc, plain) => form.setData((d) => ({ ...d, metric_definition: plain, metric_definition_rich: doc }))}
                        tools="minimal"
                        minHeight={90}
                    />
                </div>

                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel value="Effective from" />
                        <TextInput type="date" value={form.data.effective_from} onChange={(e) => form.setData('effective_from', e.target.value)} />
                    </div>
                    <div>
                        <InputLabel value="Review due" />
                        <TextInput type="date" value={form.data.review_due_at} onChange={(e) => form.setData('review_due_at', e.target.value)} />
                    </div>
                </div>

                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Draft statement</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
