import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import RichTextEditor from '@/Components/RichTextEditor';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import TextInput from '@/Components/TextInput';
import VerificationBadge from '@/Components/VerificationBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CalendarClock, CircleSlash, Gauge } from 'lucide-react';

export default function Index({
    topics = [],
    assessments = [],
    readiness = {},
    filters = {},
    pillars = [],
    bases = [],
    entities = [],
    stats = {},
    can = {},
}) {
    const [assessing, setAssessing] = useState(null);
    const byTopic = Object.fromEntries(assessments.map((a) => [a.topic_id, a]));

    return (
        <AuthenticatedLayout header="Sustainability">
            <Head title="Sustainability" />

            <PageHeader
                title="Sustainability reporting"
                subtitle={`IFRS S1/S2 topic register and materiality determinations for ${filters.period}`}
                actions={
                    <>
                        <Link href={route('sustainability.ghg')} className="btn-secondary">
                            GHG inventory
                        </Link>
                        <Link href={route('sustainability.filings')} className="btn-secondary">
                            Filing tracker
                        </Link>
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
                <StatCard icon={Gauge}
                    title="Topics in register"
                    value={stats.topics ?? 0}
                    subtitle={`${stats.unverified_topics ?? 0} not yet verified against the standard`}
                    color="primary"
                />
                <StatCard icon={Gauge}
                    title="Material this period"
                    value={stats.material_topics ?? 0}
                    subtitle={`${stats.awaiting_approval ?? 0} awaiting a second person`}
                    color="info"
                />
                <StatCard icon={CircleSlash}
                    title="Figures unverified"
                    value={stats.unverified_figures ?? 0}
                    subtitle={`${stats.lineage_gaps ?? 0} with a lineage gap`}
                    color="warning"
                />
                <StatCard icon={CalendarClock}
                    title="Filing stages late"
                    value={stats.stages_late ?? 0}
                    subtitle={`${stats.stages_due_90 ?? 0} due within 90 days`}
                    color="danger"
                    prominent={(stats.stages_late ?? 0) > 0}
                />
            </div>

            <div className="filter-bar mb-6">
                <div className="filter-bar-inner">
                    <div className="filter-bar-group">
                        <label className="filter-bar-label">Period</label>
                        <input
                            className="filter-bar-input"
                            defaultValue={filters.period}
                            onBlur={(e) => router.get(route('sustainability.index'), { ...filters, period: e.target.value })}
                        />
                    </div>
                    <div className="filter-bar-group">
                        <label className="filter-bar-label">Pillar</label>
                        <select
                            className="filter-bar-select"
                            value={filters.pillar ?? ''}
                            onChange={(e) => router.get(route('sustainability.index'), { ...filters, pillar: e.target.value })}
                        >
                            <option value="">All</option>
                            {pillars.map((pillar) => (
                                <option key={pillar.value} value={pillar.value}>
                                    {pillar.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="filter-bar-group">
                        <label className="filter-bar-label">Entity</label>
                        <select
                            className="filter-bar-select"
                            value={filters.entity_id ?? ''}
                            onChange={(e) => router.get(route('sustainability.index'), { ...filters, entity_id: e.target.value })}
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

            <ReadinessPanel readiness={readiness} />

            <section className="card">
                <div className="card-header">
                    <h2 className="text-sm font-semibold text-[var(--color-primary)]">Topic register &amp; materiality</h2>
                </div>
                <div className="card-body overflow-x-auto">
                    <table className="data-table w-full min-w-[44rem]">
                        <thead>
                            <tr>
                                <th>Topic</th>
                                <th>Pillar</th>
                                <th>Standard</th>
                                <th>Materiality</th>
                                <th>Status</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {topics.map((topic) => {
                                const assessment = byTopic[topic.id];

                                return (
                                    <tr key={topic.id}>
                                        <td>
                                            <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">
                                                {topic.code}
                                            </span>
                                            <p className="text-sm text-[var(--color-text-primary)]">{topic.title}</p>
                                            <p className="max-w-lg text-xs text-gray-400">{topic.description}</p>
                                        </td>
                                        <td className="text-xs capitalize">{topic.pillar}</td>
                                        <td>
                                            <p className="text-xs">{topic.standard}</p>
                                            <VerificationBadge status={topic.verification_status} />
                                        </td>
                                        <td className="text-xs">
                                            {assessment ? (
                                                <>
                                                    <span
                                                        className={
                                                            assessment.is_material
                                                                ? 'font-semibold text-[var(--color-primary)]'
                                                                : 'text-gray-400'
                                                        }
                                                    >
                                                        {assessment.is_material ? 'Material' : 'Not material'}
                                                    </span>
                                                    <p className="text-gray-400">
                                                        {assessment.basis === 'double' ? 'Double materiality' : 'Financial'}
                                                        {assessment.financial_materiality_score !== null
                                                            ? ` · fin ${assessment.financial_materiality_score}`
                                                            : ''}
                                                        {assessment.impact_materiality_score !== null
                                                            ? ` · impact ${assessment.impact_materiality_score}`
                                                            : ''}
                                                    </p>
                                                </>
                                            ) : (
                                                <span className="text-gray-400">Not assessed</span>
                                            )}
                                        </td>
                                        <td>{assessment ? <StatusBadge status={assessment.status} /> : '—'}</td>
                                        <td className="text-right text-xs">
                                            {assessment?.status === 'Submitted' && can.approve && (
                                                <button
                                                    type="button"
                                                    className="font-semibold text-[var(--color-primary)]"
                                                    onClick={() =>
                                                        router.post(route('sustainability.materiality.approve', assessment.id))
                                                    }
                                                >
                                                    Approve
                                                </button>
                                            )}
                                            {!assessment && can.manage && (
                                                <button
                                                    type="button"
                                                    className="font-semibold text-[var(--color-primary)]"
                                                    onClick={() => setAssessing(topic)}
                                                >
                                                    Assess
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </section>

            <MaterialityModal
                topic={assessing}
                onClose={() => setAssessing(null)}
                period={filters.period}
                entityId={filters.entity_id}
                bases={bases}
                entities={entities}
            />
        </AuthenticatedLayout>
    );
}

function ReadinessPanel({ readiness }) {
    const excluded = readiness.excluded ?? [];

    return (
        <section className={`card mb-6 ${readiness.ready ? '' : 'border-l-4 border-l-[var(--color-warning)]'}`}>
            <div className="card-header flex items-center justify-between">
                <h2 className="text-sm font-semibold text-[var(--color-primary)]">Submission readiness</h2>
                <span
                    className={`badge ${readiness.ready ? 'badge-status-active' : 'badge-medium'}`}
                >
                    {readiness.ready ? 'Ready to generate' : 'Not ready'}
                </span>
            </div>
            <div className="card-body">
                <p className="text-xs text-[var(--color-text-secondary)]">
                    {readiness.material_topics ?? 0} topic(s) determined material of {readiness.topics_assessed ?? 0} assessed.
                    Verified emissions {readiness.inventory?.verified_total ?? 0} tCO2e; {readiness.inventory?.unverified_total ?? 0}{' '}
                    tCO2e is not yet verified and would not be filed.
                </p>

                {excluded.length > 0 && (
                    <div className="mt-3">
                        <p className="text-xs font-semibold text-[var(--color-warning)]">
                            {excluded.length} item(s) would be excluded from a submission:
                        </p>
                        <ul className="mt-1 space-y-0.5 text-xs text-[var(--color-text-secondary)]">
                            {excluded.slice(0, 12).map((item, index) => (
                                <li key={index}>
                                    {item.code ?? `Scope ${item.scope}`} {item.title ?? item.category} — {item.reason}
                                </li>
                            ))}
                            {excluded.length > 12 && <li>…and {excluded.length - 12} more.</li>}
                        </ul>
                    </div>
                )}
            </div>
        </section>
    );
}

function MaterialityModal({ topic, onClose, period, entityId, bases, entities }) {
    const form = useForm({
        topic_id: '',
        entity_id: entityId ?? '',
        period_label: period ?? '',
        basis: 'financial',
        financial_materiality_score: '',
        impact_materiality_score: '',
        is_material: true,
        rationale: '',
        stakeholders_consulted: [],
    
        rationale_rich: null,
});

    if (!topic) {
        return null;
    }

    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, topic_id: topic.id }));
        form.post(route('sustainability.materiality.store'), { onSuccess: onClose });
    };

    return (
        <Modal show onClose={onClose} maxWidth="2xl" title={`Assess materiality — ${topic.code}`}>
            <form onSubmit={submit} className="space-y-4">
                <p className="text-xs text-[var(--color-text-secondary)]">
                    {topic.title}. A determination is submitted for approval by a second person — deciding a topic is
                    immaterial is deciding not to disclose it.
                </p>

                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <InputLabel value="Period" required />
                        <TextInput value={form.data.period_label} onChange={(e) => form.setData('period_label', e.target.value)} />
                    </div>
                    <div>
                        <InputLabel value="Basis" required />
                        <SelectInput value={form.data.basis} onChange={(e) => form.setData('basis', e.target.value)}>
                            {bases.map((basis) => (
                                <option key={basis} value={basis}>
                                    {basis === 'double' ? 'Double materiality' : 'Financial only'}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Financial score" />
                        <TextInput
                            type="number"
                            min="0"
                            max="100"
                            value={form.data.financial_materiality_score}
                            onChange={(e) => form.setData('financial_materiality_score', e.target.value)}
                        />
                    </div>
                    <div>
                        <InputLabel value="Impact score" />
                        <TextInput
                            type="number"
                            min="0"
                            max="100"
                            disabled={form.data.basis !== 'double'}
                            value={form.data.impact_materiality_score}
                            onChange={(e) => form.setData('impact_materiality_score', e.target.value)}
                        />
                        {form.errors.impact_materiality_score && (
                            <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.impact_materiality_score}</p>
                        )}
                    </div>
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

                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        className="rounded border-gray-300"
                        checked={form.data.is_material}
                        onChange={(e) => form.setData('is_material', e.target.checked)}
                    />
                    This topic is material for the period
                </label>

                <div>
                    <InputLabel value="Rationale" required />
                    <RichTextEditor
                        value={form.data.rationale_rich ?? form.data.rationale}
                        onChange={(doc, plain) => form.setData((d) => ({ ...d, rationale: plain, rationale_rich: doc }))}
                        tools="minimal"
                        minHeight={90}
                    />
                    {form.errors.rationale && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.rationale}</p>}
                </div>

                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Submit for approval</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
