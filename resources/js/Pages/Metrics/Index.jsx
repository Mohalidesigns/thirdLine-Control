import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import Sparkline from '@/Components/Sparkline';
import StatCard from '@/Components/StatCard';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatNumber } from '@/utils';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const LEVEL_CLASSES = {
    Green: 'badge-low',
    Amber: 'badge-medium',
    Red: 'badge-high',
    Critical: 'badge-critical',
};

export default function Index({
    metrics,
    sparklines = {},
    filters = {},
    types = [],
    categories = [],
    owners = [],
    entities = [],
    levels = [],
    operators = [],
    options = {},
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
            field: 'name',
            label: 'Metric',
            render: (row) => (
                <div className="max-w-xs">
                    <p className="font-medium text-[var(--color-text-primary)]">{row.name}</p>
                    <p className="text-xs text-gray-400">
                        {row.metric_type} · {row.frequency} · {row.category?.name ?? 'Uncategorised'}
                        {row.source === 'calculated' ? ' · calculated' : ''}
                    </p>
                </div>
            ),
        },
        {
            field: 'latest',
            label: 'Latest',
            render: (row) =>
                row.latest ? (
                    <div>
                        <p className="font-semibold tabular-nums text-[var(--color-text-primary)]">
                            {formatNumber(row.latest.value)} {row.unit}
                        </p>
                        <p className="text-xs text-gray-400">{row.latest.period_label}</p>
                    </div>
                ) : (
                    <span className="text-xs text-gray-400">No reading</span>
                ),
        },
        {
            field: 'level',
            label: 'Band',
            render: (row) =>
                row.latest?.threshold_level_hit ? (
                    <span className={`badge ${LEVEL_CLASSES[row.latest.threshold_level_hit] ?? 'badge-status-draft'}`}>
                        {row.latest.threshold_level_hit}
                    </span>
                ) : (
                    <span className="text-xs text-gray-400">—</span>
                ),
        },
        {
            field: 'trend',
            label: 'Trend',
            render: (row) => (
                <Sparkline series={sparklines[row.id] ?? []} breach={!!row.latest?.breach_flag} />
            ),
        },
        { field: 'owner', label: 'Owner', render: (row) => row.owner?.name ?? '—' },
    ];

    return (
        <AuthenticatedLayout header="Key Indicators">
            <Head title="Key Indicators" />

            <PageHeader
                title="Key Risk & Performance Indicators"
                subtitle="Definitions, threshold bands, readings and breaches — every band is configured, none is coded"
                actions={can.manage && <PrimaryButton onClick={() => setCreating(true)}>+ New metric</PrimaryButton>}
            />

            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-3">
                <StatCard title="Active indicators" value={stats.active ?? 0} color="primary" />
                <StatCard
                    title="In breach"
                    value={stats.breaching ?? 0}
                    color="danger"
                    prominent={(stats.breaching ?? 0) > 0}
                />
                <StatCard title="Unacknowledged breaches" value={stats.unacknowledged ?? 0} color="warning" />
            </div>

            <FilterBar
                route="metrics.index"
                resource="metrics"
                currentFilters={filters}
                searchPlaceholder="Search indicators…"
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    { name: 'metric_type', type: 'select', label: 'Type', options: types },
                    {
                        name: 'category_id',
                        type: 'select',
                        label: 'Category',
                        options: categories.map((c) => ({ value: c.id, label: c.name })),
                    },
                    { name: 'owner_id', type: 'select', label: 'Owner', options: owners.map((o) => ({ value: o.id, label: o.name })) },
                    { name: 'breached_only', type: 'checkbox', label: 'In breach only' },
                ]}
            />

            <div className="card">
                <DataTable
                    columns={columns}
                    data={metrics.data}
                    emptyMessage="No indicators match the filters."
                    onRowClick={(row) => router.visit(route('metrics.show', row.id))}
                />
                <Pagination paginator={metrics} />
            </div>

            <CreateModal
                show={creating}
                onClose={() => setCreating(false)}
                types={types}
                categories={categories}
                owners={owners}
                entities={entities}
                levels={levels}
                operators={operators}
                options={options}
            />
        </AuthenticatedLayout>
    );
}

function CreateModal({ show, onClose, types, categories, owners, entities, levels, operators, options }) {
    const form = useForm({
        metric_type: 'KRI',
        code: '',
        name: '',
        description: '',
        category_id: '',
        formula: '',
        unit: '',
        data_type: 'number',
        currency: '',
        direction: 'lower_is_better',
        frequency: 'Monthly',
        owner_id: '',
        source: 'manual',
        calculation_expression: '',
        entity_id: '',
        is_active: true,
        aggregation: 'last',
        target_value: '',
        thresholds: [
            { level: 'Critical', operator: 'gt', value_from: '', value_to: '', colour_hex: '#d03b3b' },
            { level: 'Red', operator: 'gt', value_from: '', value_to: '', colour_hex: '#ec835a' },
            { level: 'Amber', operator: 'between', value_from: '', value_to: '', colour_hex: '#fab219' },
            { level: 'Green', operator: 'lte', value_from: '', value_to: '', colour_hex: '#0ca30c' },
        ],
    });

    const setThreshold = (index, field, value) => {
        const next = [...form.data.thresholds];
        next[index] = { ...next[index], [field]: value };
        form.setData('thresholds', next);
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(route('metrics.store'), { onSuccess: onClose });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="4xl" title="New indicator">
            <form onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
                    <div>
                        <InputLabel value="Type" required />
                        <SelectInput value={form.data.metric_type} onChange={(e) => form.setData('metric_type', e.target.value)}>
                            {types.map((type) => (
                                <option key={type} value={type}>
                                    {type}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Code" required />
                        <TextInput value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} placeholder="KRI-FRAUD" />
                        {form.errors.code && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.code}</p>}
                    </div>
                    <div className="sm:col-span-2">
                        <InputLabel value="Name" required />
                        <TextInput value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        {form.errors.name && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.name}</p>}
                    </div>
                </div>

                <div>
                    <InputLabel value="Description" />
                    <TextArea rows={2} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                </div>

                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <InputLabel value="Data type" required />
                        <SelectInput value={form.data.data_type} onChange={(e) => form.setData('data_type', e.target.value)}>
                            {(options.data_types ?? []).map((type) => (
                                <option key={type} value={type}>
                                    {type}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Unit" />
                        <TextInput value={form.data.unit} onChange={(e) => form.setData('unit', e.target.value)} placeholder="cases" />
                    </div>
                    <div>
                        <InputLabel value="Direction" required />
                        <SelectInput value={form.data.direction} onChange={(e) => form.setData('direction', e.target.value)}>
                            {(options.directions ?? []).map((direction) => (
                                <option key={direction} value={direction}>
                                    {direction.replace(/_/g, ' ')}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Frequency" required />
                        <SelectInput value={form.data.frequency} onChange={(e) => form.setData('frequency', e.target.value)}>
                            {(options.frequencies ?? []).map((frequency) => (
                                <option key={frequency} value={frequency}>
                                    {frequency}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
                    <div>
                        <InputLabel value="Category" />
                        <SelectInput value={form.data.category_id} onChange={(e) => form.setData('category_id', e.target.value)}>
                            <option value="">—</option>
                            {categories.map((category) => (
                                <option key={category.id} value={category.id}>
                                    {category.name}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
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
                                    {entity.name}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Aggregation" required />
                        <SelectInput value={form.data.aggregation} onChange={(e) => form.setData('aggregation', e.target.value)}>
                            {(options.aggregations ?? []).map((aggregation) => (
                                <option key={aggregation} value={aggregation}>
                                    {aggregation}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <InputLabel value="Source" required />
                        <SelectInput value={form.data.source} onChange={(e) => form.setData('source', e.target.value)}>
                            {(options.sources ?? []).map((source) => (
                                <option key={source} value={source}>
                                    {source}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div className="sm:col-span-2">
                        <InputLabel value="Calculation expression" />
                        <TextInput
                            value={form.data.calculation_expression}
                            onChange={(e) => form.setData('calculation_expression', e.target.value)}
                            placeholder="[metric.KRI-OPS-LOSS] / [metric.KRI-TXN-VOL] * 10000"
                        />
                        <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                            Arithmetic over other metric codes. Only min, max, abs, round, floor, ceil, sqrt, avg, sum and
                            if are allowed.
                        </p>
                        {form.errors.calculation_expression && (
                            <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.calculation_expression}</p>
                        )}
                    </div>
                </div>

                <div>
                    <InputLabel value="Threshold bands" />
                    <p className="mb-2 text-xs text-[var(--color-text-secondary)]">
                        Evaluated top to bottom — the first band a reading matches is the one it hits. Leave a row's values
                        blank to drop that band.
                    </p>
                    <div className="space-y-2">
                        {form.data.thresholds.map((threshold, index) => (
                            <div key={threshold.level} className="grid grid-cols-2 gap-2 sm:grid-cols-5">
                                <div className="flex items-center gap-2">
                                    <span
                                        className="inline-block h-3 w-3 rounded-sm"
                                        style={{ backgroundColor: threshold.colour_hex }}
                                    />
                                    <span className="text-sm font-medium">{threshold.level}</span>
                                </div>
                                <SelectInput value={threshold.operator} onChange={(e) => setThreshold(index, 'operator', e.target.value)}>
                                    {operators.map((operator) => (
                                        <option key={operator} value={operator}>
                                            {operator}
                                        </option>
                                    ))}
                                </SelectInput>
                                <TextInput
                                    type="number"
                                    step="any"
                                    placeholder="from"
                                    value={threshold.value_from}
                                    onChange={(e) => setThreshold(index, 'value_from', e.target.value)}
                                />
                                <TextInput
                                    type="number"
                                    step="any"
                                    placeholder="to"
                                    value={threshold.value_to}
                                    onChange={(e) => setThreshold(index, 'value_to', e.target.value)}
                                    disabled={!['between', 'outside'].includes(threshold.operator)}
                                />
                                <TextInput
                                    placeholder="Action required"
                                    value={threshold.action_required ?? ''}
                                    onChange={(e) => setThreshold(index, 'action_required', e.target.value)}
                                />
                            </div>
                        ))}
                    </div>
                </div>

                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Create indicator</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
