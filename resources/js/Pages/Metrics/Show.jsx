import DataTable from '@/Components/DataTable';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import RelationshipsPanel from '@/Components/RelationshipsPanel';
import RichTextEditor from '@/Components/RichTextEditor';
import SecondaryButton from '@/Components/SecondaryButton';
import StatCard from '@/Components/StatCard';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import TrendChart from '@/Components/TrendChart';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDateTime, formatNumber } from '@/utils';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AlertTriangle, CalendarClock, Info, Target } from 'lucide-react';

const LEVEL_CLASSES = {
    Green: 'badge-low',
    Amber: 'badge-medium',
    Red: 'badge-high',
    Critical: 'badge-critical',
};

export default function Show({ metric, trend = [], period, values, breaches = [], links = [], can = {} }) {
    const { features = [] } = usePage().props;
    const [capturing, setCapturing] = useState(false);

    const latest = trend[trend.length - 1];
    const open = breaches.filter((breach) => !breach.resolved_at);

    const valueColumns = [
        { field: 'period_label', label: 'Period', render: (row) => <span className="font-mono text-xs">{row.period_label}</span> },
        { field: 'value', label: 'Value', render: (row) => <span className="tabular-nums">{formatNumber(row.value)}</span> },
        { field: 'target', label: 'Target', render: (row) => (row.target !== null ? formatNumber(row.target) : '—') },
        {
            field: 'threshold_level_hit',
            label: 'Band',
            render: (row) =>
                row.threshold_level_hit ? (
                    <span className={`badge ${LEVEL_CLASSES[row.threshold_level_hit] ?? 'badge-status-draft'}`}>
                        {row.threshold_level_hit}
                    </span>
                ) : (
                    '—'
                ),
        },
        { field: 'source', label: 'Source' },
        { field: 'captured_by', label: 'Captured by', render: (row) => row.capturer?.name ?? 'System' },
    ];

    return (
        <AuthenticatedLayout header={metric.code}>
            <Head title={metric.code} />

            <PageHeader
                title={metric.name}
                subtitle={
                    <span className="font-mono">
                        {metric.code} · {metric.metric_type} · {metric.frequency}
                    </span>
                }
                breadcrumbs={[{ label: 'Key Indicators', href: route('metrics.index') }, { label: metric.code }]}
                actions={
                    can.capture && <PrimaryButton onClick={() => setCapturing(true)}>Record reading</PrimaryButton>
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
                <StatCard icon={CalendarClock}
                    title="Latest reading"
                    value={latest ? `${formatNumber(latest.value)} ${metric.unit ?? ''}` : '—'}
                    subtitle={latest?.period ?? `Current period ${period}`}
                    color={latest?.breach ? 'danger' : 'primary'}
                    prominent={!!latest?.breach}
                />
                <StatCard icon={Info} title="Band" value={latest?.level ?? '—'} color={latest?.breach ? 'danger' : 'success'} />
                <StatCard icon={Target} title="Target" value={metric.target_value !== null ? formatNumber(metric.target_value) : '—'} color="info" />
                <StatCard icon={AlertTriangle} title="Open breaches" value={open.length} color="warning" />
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <div className="card">
                        <div className="card-header">
                            <h3 className="text-sm font-semibold">Trend</h3>
                            <span className="text-xs text-[var(--color-text-secondary)]">
                                {metric.direction.replace(/_/g, ' ')}
                            </span>
                        </div>
                        <div className="card-body">
                            <TrendChart trend={trend} thresholds={metric.thresholds ?? []} unit={metric.unit ?? ''} />
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">
                            <h3 className="text-sm font-semibold">Breaches</h3>
                        </div>
                        <div className="card-body">
                            {breaches.length === 0 ? (
                                <p className="text-sm text-[var(--color-text-secondary)]">No breach has been raised.</p>
                            ) : (
                                <ul className="divide-y divide-gray-100">
                                    {breaches.map((breach) => (
                                        <BreachRow key={breach.id} breach={breach} metric={metric} can={can} />
                                    ))}
                                </ul>
                            )}
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">
                            <h3 className="text-sm font-semibold">Readings</h3>
                        </div>
                        <DataTable columns={valueColumns} data={values.data} emptyMessage="No readings captured yet." />
                        <Pagination paginator={values} />
                    </div>
                </div>

                <div className="space-y-6">
                    <div className="card">
                        <div className="card-header">
                            <h3 className="text-sm font-semibold">Definition</h3>
                        </div>
                        <div className="card-body space-y-1.5 text-sm">
                            <p>{metric.description || '—'}</p>
                            <p><span className="text-gray-500">Formula:</span> {metric.formula ?? '—'}</p>
                            <p><span className="text-gray-500">Owner:</span> {metric.owner?.name ?? '—'}</p>
                            <p><span className="text-gray-500">Category:</span> {metric.category?.name ?? '—'}</p>
                            <p><span className="text-gray-500">Entity:</span> {metric.entity?.name ?? 'Group-wide'}</p>
                            <p><span className="text-gray-500">Source:</span> {metric.source}</p>
                            {metric.calculation_expression && (
                                <p className="break-all font-mono text-xs text-[var(--color-text-secondary)]">
                                    {metric.calculation_expression}
                                </p>
                            )}
                            <p><span className="text-gray-500">Aggregation:</span> {metric.aggregation}</p>
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">
                            <h3 className="text-sm font-semibold">Threshold bands</h3>
                        </div>
                        <div className="card-body">
                            <ul className="space-y-2 text-sm">
                                {(metric.thresholds ?? []).map((threshold) => (
                                    <li key={threshold.id} className="flex items-start gap-2">
                                        <span
                                            className="mt-1 inline-block h-3 w-3 shrink-0 rounded-sm"
                                            style={{ backgroundColor: threshold.colour_hex }}
                                        />
                                        <div>
                                            <p className="font-medium">
                                                {threshold.level} — {describe(threshold)}
                                            </p>
                                            {threshold.action_required && (
                                                <p className="text-xs text-[var(--color-text-secondary)]">
                                                    {threshold.action_required}
                                                </p>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>

                    {features.includes('linkage') && (
                        <RelationshipsPanel type="metric" id={metric.id} links={links} canLink={can.manage} />
                    )}
                </div>
            </div>

            <CaptureModal show={capturing} onClose={() => setCapturing(false)} metric={metric} period={period} />
        </AuthenticatedLayout>
    );
}

function BreachRow({ breach, metric, can }) {
    const [acknowledging, setAcknowledging] = useState(false);

    return (
        <li className="py-3">
            <div className="flex flex-wrap items-center gap-2">
                <span className={`badge ${LEVEL_CLASSES[breach.level] ?? 'badge-status-draft'}`}>{breach.level}</span>
                <span className="text-xs text-gray-400">{formatDateTime(breach.detected_at)}</span>
                {breach.exception && (
                    <Link
                        href={route('exceptions.show', breach.exception.id)}
                        className="font-mono text-xs font-semibold text-[var(--color-primary)] hover:underline"
                    >
                        {breach.exception.reference}
                    </Link>
                )}
                {breach.resolved_at ? (
                    <span className="badge badge-low">Resolved</span>
                ) : breach.acknowledged_at ? (
                    <span className="badge badge-medium">Acknowledged</span>
                ) : (
                    <span className="badge badge-critical">Unacknowledged</span>
                )}
            </div>

            {breach.root_cause && (
                <p className="mt-1 text-sm text-[var(--color-text-primary)]">
                    <span className="text-gray-500">Root cause:</span> {breach.root_cause}
                </p>
            )}

            {can.acknowledge && !breach.acknowledged_at && (
                <>
                    <button
                        type="button"
                        className="mt-2 btn-secondary !px-2 !py-1 text-xs"
                        onClick={() => setAcknowledging(true)}
                    >
                        Acknowledge
                    </button>
                    <AcknowledgeModal
                        show={acknowledging}
                        onClose={() => setAcknowledging(false)}
                        metric={metric}
                        breach={breach}
                    />
                </>
            )}

            {can.acknowledge && breach.acknowledged_at && !breach.resolved_at && (
                <button
                    type="button"
                    className="mt-2 btn-success !px-2 !py-1 text-xs"
                    onClick={() => {
                        const note = window.prompt('What resolved this breach?');
                        if (note) {
                            router.post(
                                route('metrics.breaches.resolve', [metric.id, breach.id]),
                                { note },
                                { preserveScroll: true },
                            );
                        }
                    }}
                >
                    Resolve
                </button>
            )}
        </li>
    );
}

function AcknowledgeModal({ show, onClose, metric, breach }) {
    const form = useForm({ root_cause: '', action_taken: '' , action_taken_rich: null});

    const submit = (event) => {
        event.preventDefault();
        form.post(route('metrics.breaches.acknowledge', [metric.id, breach.id]), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Modal show={show} onClose={onClose} title="Acknowledge breach">
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel value="Root cause" required />
                    <TextArea rows={3} value={form.data.root_cause} onChange={(e) => form.setData('root_cause', e.target.value)} />
                    {form.errors.root_cause && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.root_cause}</p>}
                </div>
                <div>
                    <InputLabel value="Action taken" />
                    <RichTextEditor
                        value={form.data.action_taken_rich ?? form.data.action_taken}
                        onChange={(doc, plain) => form.setData((d) => ({ ...d, action_taken: plain, action_taken_rich: doc }))}
                        tools="minimal"
                        minHeight={90}
                    />
                </div>
                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Acknowledge</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

function CaptureModal({ show, onClose, metric, period }) {
    const form = useForm({ value: '', target: metric.target_value ?? '', period_label: period, comment: '' , comment_rich: null});

    const submit = (event) => {
        event.preventDefault();
        form.post(route('metrics.capture', metric.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="lg" title={`Record a reading for ${metric.code}`}>
            <form onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel value={`Value (${metric.unit ?? metric.data_type})`} required />
                        <TextInput type="number" step="any" value={form.data.value} onChange={(e) => form.setData('value', e.target.value)} />
                        {form.errors.value && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.value}</p>}
                    </div>
                    <div>
                        <InputLabel value="Period" />
                        <TextInput value={form.data.period_label} onChange={(e) => form.setData('period_label', e.target.value)} />
                    </div>
                </div>
                <div>
                    <InputLabel value="Target" />
                    <TextInput type="number" step="any" value={form.data.target} onChange={(e) => form.setData('target', e.target.value)} />
                </div>
                <div>
                    <InputLabel value="Comment" />
                    <RichTextEditor
                        value={form.data.comment_rich ?? form.data.comment}
                        onChange={(doc, plain) => form.setData((d) => ({ ...d, comment: plain, comment_rich: doc }))}
                        tools="minimal"
                        minHeight={90}
                    />
                </div>
                <p className="text-xs text-[var(--color-text-secondary)]">
                    A reading in a Red or Critical band opens a breach, escalates it, and raises a linked exception.
                </p>
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

function describe(threshold) {
    const from = formatNumber(threshold.value_from);
    const to = formatNumber(threshold.value_to);

    return {
        gt: `above ${from}`,
        gte: `at or above ${from}`,
        lt: `below ${from}`,
        lte: `at or below ${from}`,
        between: `${from} to ${to}`,
        outside: `outside ${from} to ${to}`,
    }[threshold.operator] ?? threshold.operator;
}
