import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import InputError from '@/Components/InputError';
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
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Plus } from 'lucide-react';

const BAND_CLASSES = {
    Low: 'badge-low',
    Moderate: 'badge-medium',
    High: 'badge-high',
    Critical: 'badge-critical',
};

function ScoreBadge({ score, band }) {
    if (score === null || score === undefined) return <span className="text-gray-400">—</span>;

    const rounded = Math.round(Number(score) * 10) / 10;

    return (
        <span className={`badge ${BAND_CLASSES[band] ?? 'badge-status-draft'}`}>
            {rounded}
            {band ? ` · ${band}` : ''}
        </span>
    );
}

export default function Index({
    risks,
    filters = {},
    categories = [],
    riskCategories = [],
    entities = [],
    owners = [],
    bands = [],
    stats = {},
}) {
    const { auth, features = [] } = usePage().props;
    const canCreate = auth.permissions.includes('create risks');
    const [showCreate, setShowCreate] = useState(false);

    const columns = [
        {
            field: 'code',
            label: 'Code',
            render: (row) => <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{row.code}</span>,
        },
        {
            field: 'title',
            label: 'Risk',
            render: (row) => (
                <div className="max-w-sm">
                    <p className="font-medium text-[var(--color-text-primary)]">{row.title}</p>
                    <p className="text-xs text-gray-400">
                        {row.risk_category?.name ?? row.category ?? '—'}
                        {row.entity ? ` · ${row.entity.name}` : ''}
                        {row.velocity ? ` · ${row.velocity} velocity` : ''}
                        {row.is_emerging ? ' · emerging' : ''}
                    </p>
                </div>
            ),
        },
        { field: 'inherent_rating', label: 'Inherent', render: (row) => <ScoreBadge score={row.inherent_rating} /> },
        {
            field: 'residual_rating',
            label: 'Residual',
            render: (row) => <ScoreBadge score={row.residual_rating} band={row.current_rating_band} />,
        },
        {
            field: 'target_rating',
            label: 'Target',
            render: (row) =>
                row.target_rating === null || row.target_rating === undefined ? (
                    <span className="text-xs text-gray-400">Not set</span>
                ) : (
                    <span className="text-sm tabular-nums">{Math.round(row.target_rating * 10) / 10}</span>
                ),
        },
        {
            field: 'appetite_breached',
            label: 'Appetite',
            render: (row) =>
                row.appetite_breached ? (
                    <span className="badge badge-critical">Outside</span>
                ) : (
                    <span className="badge badge-low">Within</span>
                ),
        },
        {
            field: 'controls',
            label: 'Controls',
            render: (row) =>
                row.controls?.length ? (
                    <span className="text-sm tabular-nums">{row.controls.length}</span>
                ) : (
                    <span className="badge badge-critical">Gap</span>
                ),
        },
        {
            field: 'open_treatments_count',
            label: 'Treatments',
            render: (row) =>
                row.open_treatments_count > 0 ? (
                    <span className="text-sm tabular-nums">{row.open_treatments_count} open</span>
                ) : (
                    <span className="text-xs text-gray-400">—</span>
                ),
        },
        { field: 'owner', label: 'Owner', render: (row) => row.owner?.name ?? '—' },
    ];

    return (
        <AuthenticatedLayout header="Risk Register">
            <Head title="Risk Register" />

            <PageHeader
                title="Risk Register"
                subtitle="Enterprise risks, their current position against appetite, and the controls and plans that move them"
                actions={
                    <>
                        {features.includes('risk-heatmap') && (
                            <Link href={route('risks.heatmap')} className="btn-secondary">
                                Heatmap
                            </Link>
                        )}
                        {features.includes('risk-appetite') && auth.permissions.includes('view appetite') && (
                            <Link href={route('appetite.index')} className="btn-secondary">
                                Appetite
                            </Link>
                        )}
                        <Link href={route('risks.gaps')} className="btn-secondary">
                            Gap analysis
                        </Link>
                        {canCreate && <PrimaryButton onClick={() => setShowCreate(true)}><Plus className="h-4 w-4" strokeWidth={2} /> New risk</PrimaryButton>}
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
                <StatCard title="Active risks" value={stats.total ?? 0} color="primary" />
                <StatCard
                    title="Outside appetite"
                    value={stats.breaching ?? 0}
                    color="danger"
                    prominent={(stats.breaching ?? 0) > 0}
                />
                <StatCard title="Emerging" value={stats.emerging ?? 0} color="warning" />
                <StatCard title="No open treatment" value={stats.untreated ?? 0} color="info" />
            </div>

            <FilterBar
                route="risks.index"
                resource="risks"
                currentFilters={filters}
                searchPlaceholder="Search risks…"
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    {
                        name: 'risk_category_id',
                        type: 'select',
                        label: 'Taxonomy',
                        options: riskCategories.map((c) => ({ value: c.id, label: c.name })),
                    },
                    {
                        name: 'entity_id',
                        type: 'select',
                        label: 'Entity',
                        options: entities.map((e) => ({ value: e.id, label: e.name })),
                    },
                    {
                        name: 'owner_id',
                        type: 'select',
                        label: 'Owner',
                        options: owners.map((o) => ({ value: o.id, label: o.name })),
                    },
                    { name: 'band', type: 'select', label: 'Band', options: bands },
                    { name: 'category', type: 'select', label: 'Legacy category', options: categories },
                    { name: 'breach_only', type: 'checkbox', label: 'Outside appetite' },
                    { name: 'emerging_only', type: 'checkbox', label: 'Emerging only' },
                ]}
            />

            <div className="card">
                <DataTable
                    columns={columns}
                    data={risks.data}
                    emptyMessage="No risks match the filters."
                    onRowClick={(row) => router.visit(route('risks.show', row.id))}
                />
                <Pagination paginator={risks} />
            </div>

            <CreateModal
                show={showCreate}
                onClose={() => setShowCreate(false)}
                riskCategories={riskCategories}
                entities={entities}
                owners={owners}
            />
        </AuthenticatedLayout>
    );
}

function CreateModal({ show, onClose, riskCategories, entities, owners }) {
    const form = useForm({
        title: '',
        description: '',
        category: '',
        risk_category_id: '',
        entity_id: '',
        risk_owner_id: '',
        risk_type: 'qualitative',
        risk_source: 'internal',
        velocity: 'Medium',
        is_emerging: false,
        inherent_likelihood: 3,
        inherent_impact: 3,
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('risks.store'), { onSuccess: onClose });
    };

    return (
        <Modal show={show} onClose={onClose} title="New risk">
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel value="Title" required />
                    <TextInput value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                    <InputError message={form.errors.title} className="mt-1" />
                </div>
                <div>
                    <InputLabel value="Description" />
                    <TextArea rows={3} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <InputLabel value="Taxonomy" />
                        <SelectInput value={form.data.risk_category_id} onChange={(e) => form.setData('risk_category_id', e.target.value)}>
                            <option value="">— Select —</option>
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
                    <div>
                        <InputLabel value="Owner" />
                        <SelectInput value={form.data.risk_owner_id} onChange={(e) => form.setData('risk_owner_id', e.target.value)}>
                            <option value="">Unassigned</option>
                            {owners.map((owner) => (
                                <option key={owner.id} value={owner.id}>
                                    {owner.name}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
                    <div>
                        <InputLabel value="Likelihood" required />
                        <SelectInput
                            value={form.data.inherent_likelihood}
                            onChange={(e) => form.setData('inherent_likelihood', Number(e.target.value))}
                        >
                            {[1, 2, 3, 4, 5].map((n) => (
                                <option key={n} value={n}>
                                    {n}
                                </option>
                            ))}
                        </SelectInput>
                        <InputError message={form.errors.inherent_likelihood} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Impact" required />
                        <SelectInput value={form.data.inherent_impact} onChange={(e) => form.setData('inherent_impact', Number(e.target.value))}>
                            {[1, 2, 3, 4, 5].map((n) => (
                                <option key={n} value={n}>
                                    {n}
                                </option>
                            ))}
                        </SelectInput>
                        <InputError message={form.errors.inherent_impact} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Velocity" />
                        <SelectInput value={form.data.velocity} onChange={(e) => form.setData('velocity', e.target.value)}>
                            {['Slow', 'Medium', 'Fast'].map((v) => (
                                <option key={v} value={v}>
                                    {v}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Type" />
                        <SelectInput value={form.data.risk_type} onChange={(e) => form.setData('risk_type', e.target.value)}>
                            {['qualitative', 'quantitative', 'both'].map((t) => (
                                <option key={t} value={t}>
                                    {t}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                </div>

                <label className="flex items-center gap-2 text-sm text-gray-600">
                    <input
                        type="checkbox"
                        className="rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                        checked={form.data.is_emerging}
                        onChange={(e) => form.setData('is_emerging', e.target.checked)}
                    />
                    Emerging risk — not yet fully understood or measured
                </label>

                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Create risk</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
