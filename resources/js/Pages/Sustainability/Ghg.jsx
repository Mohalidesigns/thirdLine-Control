import DataTable from '@/Components/DataTable';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatNumber } from '@/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Plus } from 'lucide-react';

export default function Ghg({
    points,
    inventory = {},
    filters = {},
    entities = [],
    controls = [],
    options = {},
    can = {},
}) {
    const [capturing, setCapturing] = useState(false);
    const [verifying, setVerifying] = useState(null);
    const [deferring, setDeferring] = useState(null);

    const columns = [
        {
            field: 'scope',
            label: 'Scope',
            render: (row) => <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">Scope {row.scope}</span>,
        },
        {
            field: 'category',
            label: 'Category',
            render: (row) => (
                <div className="max-w-xs">
                    <p className="text-sm text-[var(--color-text-primary)]">{row.category}</p>
                    <p className="text-xs text-gray-400">
                        {row.entity?.legal_name ?? 'Group-wide'} · {row.data_quality}
                        {row.methodology !== 'not_applicable' ? ` · ${row.methodology.replace('_', '-')}` : ''}
                    </p>
                </div>
            ),
        },
        {
            field: 'activity_data',
            label: 'Activity data',
            render: (row) =>
                row.activity_data === null ? (
                    <span className="text-xs text-gray-400">—</span>
                ) : (
                    <span className="text-xs tabular-nums">
                        {formatNumber(row.activity_data)} {row.activity_unit}
                    </span>
                ),
        },
        {
            field: 'emission_factor',
            label: 'Factor',
            render: (row) => (
                <div className="text-xs">
                    <p className="tabular-nums">{row.emission_factor ?? '—'}</p>
                    <p className="text-gray-400">{row.emission_factor_source ?? 'No source recorded'}</p>
                </div>
            ),
        },
        {
            field: 'tco2e',
            label: 'tCO2e',
            render: (row) => (
                <span className="font-semibold tabular-nums">{row.tco2e === null ? '—' : formatNumber(row.tco2e)}</span>
            ),
        },
        {
            field: 'lineage_gaps',
            label: 'Lineage',
            render: (row) =>
                row.is_assurable ? (
                    <span className="text-xs text-[var(--color-success)]">Complete</span>
                ) : (
                    <span className="text-xs text-[var(--color-warning)]" title={row.lineage_gaps.join(', ')}>
                        Missing {row.lineage_gaps.join(', ')}
                    </span>
                ),
        },
        {
            field: 'status',
            label: 'Status',
            render: (row) => (
                <div className="space-y-1">
                    <StatusBadge status={row.status} />
                    <p className="text-[10px] text-gray-400">
                        {row.preparer?.name ? `Prepared by ${row.preparer.name}` : 'Not prepared'}
                        {row.verifier?.name ? ` · verified by ${row.verifier.name}` : ''}
                    </p>
                </div>
            ),
        },
        {
            field: 'actions',
            label: '',
            render: (row) => (
                <div className="flex flex-col items-end gap-1 text-xs">
                    {can.verify && row.status === 'Prepared' && (
                        <button
                            type="button"
                            className="font-semibold text-[var(--color-primary)]"
                            onClick={() => setVerifying(row)}
                        >
                            Verify
                        </button>
                    )}
                    {can.manage && row.scope === '3' && !row.is_deferred && (
                        <button type="button" className="text-[var(--color-text-secondary)]" onClick={() => setDeferring(row)}>
                            Defer
                        </button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <AuthenticatedLayout header="GHG Inventory">
            <Head title="GHG Inventory" />

            <PageHeader
                title="Greenhouse gas inventory"
                subtitle={`${filters.period} — every figure carries its activity data, factor, control and evidence`}
                breadcrumbs={[{ label: 'Sustainability', href: route('sustainability.index') }, { label: 'GHG inventory' }]}
                actions={
                    <>
                        <Link href={route('sustainability.index')} className="btn-secondary">
                            Materiality
                        </Link>
                        {can.prepare && <PrimaryButton onClick={() => setCapturing(true)}><Plus className="h-4 w-4" strokeWidth={2} /> Capture figure</PrimaryButton>}
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
                <StatCard
                    title="Verified total"
                    value={`${formatNumber(inventory.verified_total ?? 0)} tCO2e`}
                    subtitle="Reportable"
                    color="success"
                />
                <StatCard
                    title="Unverified"
                    value={`${formatNumber(inventory.unverified_total ?? 0)} tCO2e`}
                    subtitle="Excluded from a filing until verified"
                    color="warning"
                />
                <StatCard
                    title="Assurable figures"
                    value={`${inventory.assurable ?? 0}/${inventory.total_points ?? 0}`}
                    subtitle="Complete lineage"
                    color="info"
                />
                <StatCard
                    title="Scope 3 deferred"
                    value={inventory.scopes?.['3']?.deferred_points ?? 0}
                    subtitle="Under the transition reliefs"
                    color="primary"
                />
            </div>

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                {Object.entries(inventory.scopes ?? {}).map(([scope, figures]) => (
                    <div key={scope} className="card">
                        <div className="card-body">
                            <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                                Scope {scope}
                            </p>
                            <p className="mt-1 text-2xl font-bold text-[var(--color-primary)]">
                                {formatNumber(figures.verified_tco2e)}{' '}
                                <span className="text-sm font-normal text-gray-400">tCO2e verified</span>
                            </p>
                            <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                                {figures.verified_points}/{figures.points} figure(s) verified
                                {figures.lineage_gaps > 0 ? ` · ${figures.lineage_gaps} with a lineage gap` : ''}
                                {figures.deferred_points > 0 ? ` · ${figures.deferred_points} deferred` : ''}
                            </p>
                        </div>
                    </div>
                ))}
            </div>

            <div className="filter-bar mb-6">
                <div className="filter-bar-inner">
                    <div className="filter-bar-group">
                        <label className="filter-bar-label">Period</label>
                        <input
                            className="filter-bar-input"
                            defaultValue={filters.period}
                            onBlur={(e) => router.get(route('sustainability.ghg'), { ...filters, period: e.target.value })}
                        />
                    </div>
                    <div className="filter-bar-group">
                        <label className="filter-bar-label">Scope</label>
                        <select
                            className="filter-bar-select"
                            value={filters.scope ?? ''}
                            onChange={(e) => router.get(route('sustainability.ghg'), { ...filters, scope: e.target.value })}
                        >
                            <option value="">All</option>
                            {(options.scopes ?? []).map((scope) => (
                                <option key={scope} value={scope}>
                                    Scope {scope}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="filter-bar-group">
                        <label className="filter-bar-label">Entity</label>
                        <select
                            className="filter-bar-select"
                            value={filters.entity_id ?? ''}
                            onChange={(e) => router.get(route('sustainability.ghg'), { ...filters, entity_id: e.target.value })}
                        >
                            <option value="">Group-wide</option>
                            {entities.map((entity) => (
                                <option key={entity.id} value={entity.id}>
                                    {entity.legal_name}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>
            </div>

            <div className="card">
                <DataTable columns={columns} data={points.data} emptyMessage="No emissions figures for this period." />
                <Pagination paginator={points} />
            </div>

            <CaptureModal
                show={capturing}
                onClose={() => setCapturing(false)}
                period={filters.period}
                entities={entities}
                controls={controls}
                options={options}
            />
            <VerifyModal point={verifying} onClose={() => setVerifying(null)} />
            <DeferModal point={deferring} onClose={() => setDeferring(null)} />
        </AuthenticatedLayout>
    );
}

function CaptureModal({ show, onClose, period, entities, controls, options }) {
    const form = useForm({
        entity_id: '',
        scope: '1',
        category: '',
        period_label: period ?? '',
        period_start: '',
        period_end: '',
        activity_data: '',
        activity_unit: '',
        emission_factor: '',
        emission_factor_source: '',
        data_quality: 'calculated',
        methodology: 'not_applicable',
        control_id: '',
        evidence_id: '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('sustainability.ghg.store'), { onSuccess: onClose });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="3xl" title="Capture an emissions figure">
            <form onSubmit={submit} className="space-y-4">
                <p className="text-xs text-[var(--color-text-secondary)]">
                    tCO2e is computed from activity data × emission factor. A figure with no factor stays blank rather than
                    being guessed, and a second person has to verify it before it can be reported.
                </p>

                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <InputLabel value="Scope" required />
                        <SelectInput value={form.data.scope} onChange={(e) => form.setData('scope', e.target.value)}>
                            {(options.scopes ?? []).map((scope) => (
                                <option key={scope} value={scope}>
                                    Scope {scope}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div className="sm:col-span-2">
                        <InputLabel value="Category" required />
                        <TextInput
                            value={form.data.category}
                            onChange={(e) => form.setData('category', e.target.value)}
                            placeholder="Stationary combustion — branch generators"
                        />
                        {form.errors.category && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.category}</p>}
                    </div>
                    <div>
                        <InputLabel value="Period" required />
                        <TextInput value={form.data.period_label} onChange={(e) => form.setData('period_label', e.target.value)} />
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <InputLabel value="Activity data" />
                        <TextInput
                            type="number"
                            step="any"
                            value={form.data.activity_data}
                            onChange={(e) => form.setData('activity_data', e.target.value)}
                        />
                    </div>
                    <div>
                        <InputLabel value="Unit" />
                        <TextInput
                            value={form.data.activity_unit}
                            onChange={(e) => form.setData('activity_unit', e.target.value)}
                            placeholder="litres diesel"
                        />
                    </div>
                    <div>
                        <InputLabel value="Emission factor" />
                        <TextInput
                            type="number"
                            step="any"
                            value={form.data.emission_factor}
                            onChange={(e) => form.setData('emission_factor', e.target.value)}
                        />
                    </div>
                    <div>
                        <InputLabel value="Factor source" />
                        <TextInput
                            value={form.data.emission_factor_source}
                            onChange={(e) => form.setData('emission_factor_source', e.target.value)}
                            placeholder="DEFRA 2025"
                        />
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <InputLabel value="Data quality" required />
                        <SelectInput value={form.data.data_quality} onChange={(e) => form.setData('data_quality', e.target.value)}>
                            {(options.data_qualities ?? []).map((quality) => (
                                <option key={quality} value={quality}>
                                    {quality}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Methodology" required />
                        <SelectInput value={form.data.methodology} onChange={(e) => form.setData('methodology', e.target.value)}>
                            {(options.methodologies ?? []).map((methodology) => (
                                <option key={methodology} value={methodology}>
                                    {methodology.replace('_', '-')}
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
                        <InputLabel value="Control over the figure" />
                        <SelectInput value={form.data.control_id} onChange={(e) => form.setData('control_id', e.target.value)}>
                            <option value="">—</option>
                            {controls.map((control) => (
                                <option key={control.id} value={control.id}>
                                    {control.control_ref}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                </div>

                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Prepare figure</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

function VerifyModal({ point, onClose }) {
    const form = useForm({ note: '' });

    if (!point) {
        return null;
    }

    const submit = (event) => {
        event.preventDefault();
        form.post(route('sustainability.ghg.verify', point.id), { onSuccess: onClose });
    };

    return (
        <Modal show onClose={onClose} maxWidth="lg" title="Verify the figure">
            <form onSubmit={submit} className="space-y-4">
                <p className="text-xs text-[var(--color-text-secondary)]">
                    Scope {point.scope} — {point.category}: {formatNumber(point.tco2e ?? 0)} tCO2e, prepared by{' '}
                    {point.preparer?.name ?? 'unknown'}. You cannot verify a figure you prepared yourself.
                </p>
                {!point.is_assurable && (
                    <p className="rounded bg-orange-50 p-2 text-xs text-[var(--color-warning)]">
                        Lineage is incomplete — missing {point.lineage_gaps.join(', ')}. Verifying does not close that gap.
                    </p>
                )}
                <div>
                    <InputLabel value="Verification note" />
                    <TextArea
                        rows={3}
                        value={form.data.note}
                        onChange={(e) => form.setData('note', e.target.value)}
                        placeholder="Traced to the fuel purchase ledger and the meter readings."
                    />
                </div>
                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Verify</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

function DeferModal({ point, onClose }) {
    const form = useForm({ reason: '' });

    if (!point) {
        return null;
    }

    const submit = (event) => {
        event.preventDefault();
        form.post(route('sustainability.ghg.defer', point.id), { onSuccess: onClose });
    };

    return (
        <Modal show onClose={onClose} maxWidth="lg" title="Defer under the transition reliefs">
            <form onSubmit={submit} className="space-y-4">
                <p className="text-xs text-[var(--color-text-secondary)]">
                    Only Scope 3 may be deferred. The deferral and its reason are recorded on the figure, so a reader can
                    tell "not reported yet" from "reported as zero".
                </p>
                <div>
                    <InputLabel value="Reason" required />
                    <TextArea rows={3} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                </div>
                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Defer</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
