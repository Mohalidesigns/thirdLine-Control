import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import ProgressBar from '@/Components/ProgressBar';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate, formatNumber } from '@/utils';
import { Head, Link } from '@inertiajs/react';

/** The balanced scorecard: every objective with the measures behind it. */
export default function Scorecard({ scorecard = [], filters = {}, periods = [], entities = [] }) {
    return (
        <AuthenticatedLayout header="Balanced Scorecard">
            <Head title="Balanced Scorecard" />

            <PageHeader
                title="Balanced scorecard"
                subtitle="Weighted position per perspective, and the indicator behind every number"
                actions={
                    <Link href={route('strategy.map')} className="btn-secondary">
                        Strategy map
                    </Link>
                }
            />

            <FilterBar
                route="strategy.scorecard"
                currentFilters={filters}
                filters={[
                    { name: 'period', type: 'select', label: 'Period', options: periods.map((p) => ({ value: p, label: p })) },
                    {
                        name: 'entity_id',
                        type: 'select',
                        label: 'Entity',
                        options: entities.map((e) => ({ value: e.id, label: e.legal_name })),
                    },
                ]}
            />

            <div className="space-y-4">
                {scorecard.map((perspective) => (
                    <section key={perspective.key} className="card">
                        <div className="card-header flex flex-wrap items-center justify-between gap-3">
                            <h2 className="text-sm font-semibold uppercase tracking-wide text-[var(--color-primary)]">
                                {perspective.label}
                            </h2>
                            {perspective.progress === null ? (
                                <span className="text-xs text-gray-400">
                                    Not measured
                                    {perspective.unmeasured > 0 ? ` (${perspective.unmeasured} objective(s))` : ''}
                                </span>
                            ) : (
                                <div className="flex items-center gap-3">
                                    <span className="w-32">
                                        <ProgressBar value={perspective.progress} color={perspective.band.colour} />
                                    </span>
                                    <span className="text-sm font-semibold tabular-nums" style={{ color: perspective.band.colour }}>
                                        {perspective.progress}%
                                    </span>
                                    {perspective.unmeasured > 0 && (
                                        <span className="text-xs text-gray-400">
                                            {perspective.unmeasured} not measured
                                        </span>
                                    )}
                                </div>
                            )}
                        </div>

                        <div className="card-body space-y-4">
                            {perspective.objectives.length === 0 ? (
                                <p className="text-xs text-gray-400">Nothing set for this perspective.</p>
                            ) : (
                                perspective.objectives.map((objective) => (
                                    <ObjectiveRow key={objective.id} objective={objective} />
                                ))
                            )}
                        </div>
                    </section>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

function ObjectiveRow({ objective }) {
    return (
        <div className="rounded-lg border border-gray-100 p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <Link
                        href={route('objectives.show', objective.id)}
                        className="font-mono text-xs font-semibold text-[var(--color-primary)] hover:underline"
                    >
                        {objective.code}
                    </Link>
                    <p className="text-sm font-medium text-[var(--color-text-primary)]">{objective.title}</p>
                    <p className="mt-0.5 text-xs text-[var(--color-text-secondary)]">
                        {objective.owner ?? 'Unowned'}
                        {objective.target_date ? ` · target ${formatDate(objective.target_date)}` : ''}
                        {` · weight ${objective.weight}`}
                    </p>
                </div>
                <div className="flex items-center gap-3">
                    <StatusBadge status={objective.status} />
                    <span className="w-28">
                        <ProgressBar value={objective.progress ?? 0} color={objective.band.colour} />
                    </span>
                    <span
                        className="w-20 text-right text-sm font-semibold tabular-nums"
                        style={{ color: objective.band.colour }}
                    >
                        {objective.progress === null ? 'Not measured' : `${objective.progress}%`}
                    </span>
                </div>
            </div>

            {objective.measures.length > 0 && (
                <div className="mt-3 overflow-x-auto">
                    <table className="data-table w-full min-w-[36rem]">
                        <thead>
                            <tr>
                                <th>Measure</th>
                                <th className="text-right">Baseline</th>
                                <th className="text-right">Latest</th>
                                <th className="text-right">Target</th>
                                <th className="text-right">Achieved</th>
                            </tr>
                        </thead>
                        <tbody>
                            {objective.measures.map((measure) => (
                                <tr key={measure.code}>
                                    <td>
                                        <span className="font-mono text-xs text-[var(--color-primary)]">{measure.code}</span>
                                        <span className="ml-2 text-xs text-[var(--color-text-secondary)]">{measure.name}</span>
                                    </td>
                                    <td className="text-right tabular-nums">
                                        {measure.baseline === null ? '—' : formatNumber(measure.baseline)}
                                    </td>
                                    <td className="text-right tabular-nums">
                                        {measure.latest === null || measure.latest === undefined
                                            ? '—'
                                            : `${formatNumber(measure.latest)} ${measure.unit ?? ''}`}
                                    </td>
                                    <td className="text-right tabular-nums">
                                        {measure.target === null ? '—' : formatNumber(measure.target)}
                                    </td>
                                    <td className="text-right font-semibold tabular-nums">
                                        {measure.achievement === null ? (
                                            <span className="text-xs font-normal text-gray-400">Not measured</span>
                                        ) : (
                                            `${measure.achievement}%`
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
