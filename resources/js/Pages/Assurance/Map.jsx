import EmptyState from '@/Components/EmptyState';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import SeverityBadge from '@/Components/SeverityBadge';
import StatCard from '@/Components/StatCard';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Plus } from 'lucide-react';

const COVERAGE_MARK = {
    full: { label: '●', title: 'Full coverage' },
    partial: { label: '◐', title: 'Partial coverage' },
    none: { label: '○', title: 'No coverage' },
};

const CONCLUSION_COLOUR = {
    satisfactory: 'var(--color-success)',
    needs_improvement: 'var(--color-warning)',
    unsatisfactory: 'var(--color-error)',
    not_concluded: 'var(--color-text-secondary)',
};

export default function Map({ map = {}, stats = {}, filters = {}, periods = [], entities = [], options = {}, can = {} }) {
    const [addingProvider, setAddingProvider] = useState(false);
    const [addingActivity, setAddingActivity] = useState(false);
    const [reliance, setReliance] = useState(null);

    const providers = map.providers ?? [];
    const rows = map.rows ?? [];

    return (
        <AuthenticatedLayout header="Combined Assurance">
            <Head title="Combined Assurance Map" />

            <PageHeader
                title="Combined assurance map"
                subtitle="Risk × assurance provider × coverage — where nobody independent is looking, and where three people are looking at the same thing"
                actions={
                    can.manage && (
                        <>
                            <SecondaryButton onClick={() => setAddingProvider(true)}><Plus className="h-4 w-4" strokeWidth={2} /> Provider</SecondaryButton>
                            <PrimaryButton onClick={() => setAddingActivity(true)}><Plus className="h-4 w-4" strokeWidth={2} /> Record coverage</PrimaryButton>
                        </>
                    )
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
                <StatCard title="Assurance providers" value={stats.providers ?? 0} color="primary" />
                <StatCard title="Subjects covered" value={stats.subjects_covered ?? 0} color="info" />
                <StatCard
                    title="Assurance gaps"
                    value={stats.gaps ?? 0}
                    subtitle="Significant risks with no independent assurance"
                    color="danger"
                    prominent={(stats.gaps ?? 0) > 0}
                />
                <StatCard
                    title="Duplicated coverage"
                    value={stats.duplication ?? 0}
                    subtitle={`${stats.stale ?? 0} out-of-date activit${(stats.stale ?? 0) === 1 ? 'y' : 'ies'}`}
                    color="warning"
                />
            </div>

            <div className="filter-bar mb-6">
                <div className="filter-bar-inner">
                    <div className="filter-bar-group">
                        <label className="filter-bar-label">Period</label>
                        <select
                            className="filter-bar-select"
                            value={filters.period ?? ''}
                            onChange={(e) => router.get(route('assurance.map'), { ...filters, period: e.target.value })}
                        >
                            <option value="">All periods</option>
                            {periods.map((period) => (
                                <option key={period} value={period}>
                                    {period}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="filter-bar-group">
                        <label className="filter-bar-label">Entity</label>
                        <select
                            className="filter-bar-select"
                            value={filters.entity_id ?? ''}
                            onChange={(e) => router.get(route('assurance.map'), { ...filters, entity_id: e.target.value })}
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

            {(map.gaps ?? []).length > 0 && (
                <section className="card mb-6 border-l-4 border-l-[var(--color-error)]">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold text-[var(--color-error)]">
                            {map.gaps.length} assurance gap{map.gaps.length === 1 ? '' : 's'}
                        </h2>
                    </div>
                    <div className="card-body space-y-2">
                        {map.gaps.map((gap) => (
                            <div key={gap.risk_id} className="flex flex-wrap items-start justify-between gap-2 text-xs">
                                <div>
                                    <Link
                                        href={route('risks.show', gap.risk_id)}
                                        className="font-mono font-semibold text-[var(--color-primary)] hover:underline"
                                    >
                                        {gap.code}
                                    </Link>
                                    <span className="ml-2 text-sm text-[var(--color-text-primary)]">{gap.title}</span>
                                    <p className="text-gray-400">{gap.reason}</p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <SeverityBadge severity={gap.band} />
                                    <span className="text-gray-400">{gap.owner ?? 'Unowned'}</span>
                                </div>
                            </div>
                        ))}
                    </div>
                </section>
            )}

            {(map.duplication ?? []).length > 0 && (
                <section className="card mb-6 border-l-4 border-l-[var(--color-warning)]">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold text-[var(--color-warning)]">
                            {map.duplication.length} subject{map.duplication.length === 1 ? '' : 's'} covered by{' '}
                            {map.config?.duplication_threshold ?? 3}+ providers
                        </h2>
                    </div>
                    <div className="card-body space-y-2">
                        {map.duplication.map((item) => (
                            <div
                                key={`${item.subject_type}-${item.subject_id}`}
                                className="flex flex-wrap items-start justify-between gap-2 text-xs"
                            >
                                <div>
                                    <span className="font-mono font-semibold text-[var(--color-primary)]">{item.ref}</span>
                                    <span className="ml-2 text-sm text-[var(--color-text-primary)]">{item.title}</span>
                                    <p className="text-gray-400">
                                        {item.subject_label} · {item.providers.join(', ')}
                                    </p>
                                </div>
                                <span className="font-semibold text-[var(--color-warning)]">
                                    {item.provider_count} providers
                                </span>
                            </div>
                        ))}
                    </div>
                </section>
            )}

            {rows.length === 0 || providers.length === 0 ? (
                <EmptyState
                    title="The map has nothing to draw yet"
                    description="Register the assurance providers — management, second line, internal audit, external audit, the regulator — then record what each of them covers."
                />
            ) : (
                <section className="card">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold text-[var(--color-primary)]">Risk × provider</h2>
                    </div>
                    <div className="card-body overflow-x-auto">
                        <table className="data-table w-full min-w-[48rem]">
                            <thead>
                                <tr>
                                    <th className="sticky left-0 bg-white">Risk</th>
                                    <th>Band</th>
                                    {providers.map((provider) => (
                                        <th key={provider.id} className="text-center">
                                            <span title={provider.line_label}>{provider.name}</span>
                                            <p className="text-[10px] font-normal text-gray-400">
                                                {provider.independent ? 'independent' : 'not independent'}
                                            </p>
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr key={row.id} className={row.is_significant && row.independent_count === 0 ? 'bg-red-50' : ''}>
                                        <td className="sticky left-0 bg-inherit">
                                            <Link
                                                href={route('risks.show', row.id)}
                                                className="font-mono text-xs font-semibold text-[var(--color-primary)] hover:underline"
                                            >
                                                {row.code}
                                            </Link>
                                            <p className="max-w-xs text-xs text-[var(--color-text-primary)]">{row.title}</p>
                                        </td>
                                        <td>
                                            <SeverityBadge severity={row.band} />
                                        </td>
                                        {providers.map((provider) => {
                                            const cell = row.cells[provider.id];

                                            return (
                                                <td key={provider.id} className="text-center">
                                                    {cell ? (
                                                        <button
                                                            type="button"
                                                            className="text-lg leading-none"
                                                            style={{
                                                                color: cell.is_stale
                                                                    ? 'var(--color-text-secondary)'
                                                                    : CONCLUSION_COLOUR[cell.conclusion],
                                                            }}
                                                            title={`${COVERAGE_MARK[cell.coverage_level]?.title} · ${
                                                                cell.assurance_type
                                                            } · ${cell.conclusion.replace('_', ' ')} · reliance ${
                                                                cell.reliance_level
                                                            }${
                                                                cell.last_assurance_date
                                                                    ? ` · last ${formatDate(cell.last_assurance_date)}`
                                                                    : ''
                                                            }${cell.is_stale ? ' · OUT OF DATE' : ''}`}
                                                            onClick={() => can.reliance && setReliance({ cell, row, provider })}
                                                        >
                                                            {COVERAGE_MARK[cell.coverage_level]?.label ?? '·'}
                                                        </button>
                                                    ) : (
                                                        <span className="text-gray-200">·</span>
                                                    )}
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        <p className="mt-3 text-xs text-[var(--color-text-secondary)]">
                            ● full coverage · ◐ partial · colour shows the conclusion, grey means the assurance is out of date.
                            A row highlighted red is a significant risk with no live independent assurance.
                        </p>
                    </div>
                </section>
            )}

            <ProviderModal show={addingProvider} onClose={() => setAddingProvider(false)} options={options} />
            <ActivityModal
                show={addingActivity}
                onClose={() => setAddingActivity(false)}
                providers={providers}
                rows={rows}
                entities={entities}
                options={options}
            />
            <RelianceModal target={reliance} onClose={() => setReliance(null)} options={options} />
        </AuthenticatedLayout>
    );
}

function ProviderModal({ show, onClose, options }) {
    const form = useForm({
        code: '',
        name: '',
        line: 'internal_audit',
        description: '',
        contact_name: '',
        contact_email: '',
        is_independent: false,
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('assurance.providers.store'), { onSuccess: onClose });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="xl" title="Register an assurance provider">
            <form onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-3 gap-4">
                    <div>
                        <InputLabel value="Code" required />
                        <TextInput value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} placeholder="IA" />
                        {form.errors.code && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.code}</p>}
                    </div>
                    <div className="col-span-2">
                        <InputLabel value="Name" required />
                        <TextInput value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    </div>
                </div>
                <div>
                    <InputLabel value="Line" required />
                    <SelectInput value={form.data.line} onChange={(e) => form.setData('line', e.target.value)}>
                        {(options.lines ?? []).map((line) => (
                            <option key={line.value} value={line.value}>
                                {line.label}
                            </option>
                        ))}
                    </SelectInput>
                </div>
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        className="rounded border-gray-300"
                        checked={form.data.is_independent}
                        onChange={(e) => form.setData('is_independent', e.target.checked)}
                    />
                    Independent of the activities it assures
                </label>
                <p className="text-xs text-[var(--color-text-secondary)]">
                    Independence is recorded, not inferred: a second-line function assuring its own process is coverage, but
                    it is not independent assurance, and the map keeps the two apart.
                </p>
                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel value="Contact" />
                        <TextInput value={form.data.contact_name} onChange={(e) => form.setData('contact_name', e.target.value)} />
                    </div>
                    <div>
                        <InputLabel value="Email" />
                        <TextInput
                            type="email"
                            value={form.data.contact_email}
                            onChange={(e) => form.setData('contact_email', e.target.value)}
                        />
                    </div>
                </div>
                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Register</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

function ActivityModal({ show, onClose, providers, rows, entities, options }) {
    const form = useForm({
        provider_id: '',
        entity_id: '',
        subject_type: 'risk',
        subject_id: '',
        activity_name: '',
        coverage_level: 'partial',
        assurance_type: 'limited',
        last_assurance_date: '',
        next_assurance_date: '',
        period_label: '',
        conclusion: 'not_concluded',
        scope_note: '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('assurance.activities.store'), { onSuccess: onClose });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl" title="Record assurance coverage">
            <form onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel value="Provider" required />
                        <SelectInput value={form.data.provider_id} onChange={(e) => form.setData('provider_id', e.target.value)}>
                            <option value="">Select…</option>
                            {providers.map((provider) => (
                                <option key={provider.id} value={provider.id}>
                                    {provider.name} ({provider.line_label})
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Subject type" required />
                        <SelectInput value={form.data.subject_type} onChange={(e) => form.setData('subject_type', e.target.value)}>
                            {(options.subject_types ?? []).map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                </div>

                {form.data.subject_type === 'risk' ? (
                    <div>
                        <InputLabel value="Risk" required />
                        <SelectInput value={form.data.subject_id} onChange={(e) => form.setData('subject_id', e.target.value)}>
                            <option value="">Select…</option>
                            {rows.map((row) => (
                                <option key={row.id} value={row.id}>
                                    {row.code} — {row.title}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                ) : (
                    <div>
                        <InputLabel value="Subject id" required />
                        <TextInput
                            type="number"
                            min="1"
                            value={form.data.subject_id}
                            onChange={(e) => form.setData('subject_id', e.target.value)}
                        />
                    </div>
                )}

                <div>
                    <InputLabel value="Activity" />
                    <TextInput
                        value={form.data.activity_name}
                        onChange={(e) => form.setData('activity_name', e.target.value)}
                        placeholder="FY2026 payments process audit"
                    />
                </div>

                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <InputLabel value="Coverage" required />
                        <SelectInput
                            value={form.data.coverage_level}
                            onChange={(e) => form.setData('coverage_level', e.target.value)}
                        >
                            {(options.coverage_levels ?? []).map((level) => (
                                <option key={level} value={level}>
                                    {level}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Type" required />
                        <SelectInput
                            value={form.data.assurance_type}
                            onChange={(e) => form.setData('assurance_type', e.target.value)}
                        >
                            {(options.assurance_types ?? []).map((type) => (
                                <option key={type} value={type}>
                                    {type.replace(/_/g, ' ')}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Conclusion" required />
                        <SelectInput value={form.data.conclusion} onChange={(e) => form.setData('conclusion', e.target.value)}>
                            {(options.conclusions ?? []).map((conclusion) => (
                                <option key={conclusion} value={conclusion}>
                                    {conclusion.replace(/_/g, ' ')}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Period" />
                        <TextInput
                            value={form.data.period_label}
                            onChange={(e) => form.setData('period_label', e.target.value)}
                            placeholder="FY2026"
                        />
                    </div>
                </div>

                <div className="grid grid-cols-3 gap-4">
                    <div>
                        <InputLabel value="Last performed" />
                        <TextInput
                            type="date"
                            value={form.data.last_assurance_date}
                            onChange={(e) => form.setData('last_assurance_date', e.target.value)}
                        />
                    </div>
                    <div>
                        <InputLabel value="Next due" />
                        <TextInput
                            type="date"
                            value={form.data.next_assurance_date}
                            onChange={(e) => form.setData('next_assurance_date', e.target.value)}
                        />
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
                </div>

                <div>
                    <InputLabel value="Scope note" />
                    <TextArea rows={2} value={form.data.scope_note} onChange={(e) => form.setData('scope_note', e.target.value)} />
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

function RelianceModal({ target, onClose, options }) {
    const form = useForm({ reliance_level: 'limited', reliance_rationale: '' });

    if (!target) {
        return null;
    }

    const submit = (event) => {
        event.preventDefault();
        form.post(route('assurance.activities.reliance', target.cell.id), { onSuccess: onClose });
    };

    return (
        <Modal show onClose={onClose} maxWidth="lg" title="Record a reliance decision">
            <form onSubmit={submit} className="space-y-4">
                <p className="text-xs text-[var(--color-text-secondary)]">
                    {target.provider.name} on {target.row.code} — {target.row.title}. Conclusion:{' '}
                    {target.cell.conclusion.replace('_', ' ')}. Reliance cannot be placed on work that has not concluded.
                </p>
                <div>
                    <InputLabel value="Reliance" required />
                    <SelectInput
                        value={form.data.reliance_level}
                        onChange={(e) => form.setData('reliance_level', e.target.value)}
                    >
                        {(options.reliance_levels ?? []).map((level) => (
                            <option key={level} value={level}>
                                {level}
                            </option>
                        ))}
                    </SelectInput>
                    {form.errors.reliance_level && (
                        <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.reliance_level}</p>
                    )}
                </div>
                <div>
                    <InputLabel value="Rationale" />
                    <TextArea
                        rows={3}
                        value={form.data.reliance_rationale}
                        onChange={(e) => form.setData('reliance_rationale', e.target.value)}
                    />
                </div>
                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Record decision</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
