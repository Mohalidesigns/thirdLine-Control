import EmptyState from '@/Components/EmptyState';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import ProgressBar from '@/Components/ProgressBar';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

/**
 * Perspectives as rows, objectives as cards. Each card carries the count
 * of risks linked to that objective and how many of the controls over
 * those risks are rated Weak — which is the board's actual question:
 * "what threatens this, and is the cover holding?"
 */
export default function Map({ map = { rows: [], edges: [] }, filters = {}, periods = [], entities = [], bands = [] }) {
    const hasObjectives = map.rows.some((row) => row.objectives.length > 0);

    return (
        <AuthenticatedLayout header="Strategy Map">
            <Head title="Strategy Map" />

            <PageHeader
                title="Strategy map"
                subtitle="Objectives by perspective, with the risks that threaten them and the state of the controls over those risks"
                actions={
                    <Link href={route('strategy.scorecard')} className="btn-secondary">
                        Balanced scorecard
                    </Link>
                }
            />

            <FilterBar
                route="strategy.map"
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

            {!hasObjectives ? (
                <EmptyState
                    title="No objectives in this view"
                    description="Define strategic objectives to see how risk and control coverage lines up behind them."
                    actionLabel="Go to objectives"
                    actionHref={route('objectives.index')}
                />
            ) : (
                <div className="space-y-4">
                    {map.rows.map((row) => (
                        <section key={row.key} className="card">
                            <div className="card-header flex items-center justify-between">
                                <h2 className="text-sm font-semibold uppercase tracking-wide text-[var(--color-primary)]">
                                    {row.label}
                                </h2>
                                <span className="text-xs text-[var(--color-text-secondary)]">
                                    {row.objectives.length} objective{row.objectives.length === 1 ? '' : 's'}
                                </span>
                            </div>
                            <div className="card-body">
                                {row.objectives.length === 0 ? (
                                    <p className="text-xs text-gray-400">Nothing set for this perspective.</p>
                                ) : (
                                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                        {row.objectives.map((objective) => (
                                            <ObjectiveCard key={objective.id} objective={objective} />
                                        ))}
                                    </div>
                                )}
                            </div>
                        </section>
                    ))}
                </div>
            )}

            <div className="mt-4 flex flex-wrap items-center gap-4 text-xs text-[var(--color-text-secondary)]">
                {bands.map((band) => (
                    <span key={band.label} className="flex items-center gap-1.5">
                        <span className="inline-block h-2.5 w-2.5 rounded-sm" style={{ backgroundColor: band.colour }} />
                        {band.label} ({band.from}%+)
                    </span>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

function ObjectiveCard({ objective }) {
    return (
        <Link
            href={route('objectives.show', objective.id)}
            className="block rounded-xl border border-gray-100 bg-white p-4 shadow-sm transition-all duration-200 hover:shadow-md"
        >
            <div className="flex items-start justify-between gap-2">
                <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{objective.code}</span>
                <span
                    className="rounded-full px-2 py-0.5 text-[10px] font-semibold text-white"
                    style={{ backgroundColor: objective.band.colour }}
                    title={objective.band.label}
                >
                    {objective.progress === null ? 'n/m' : `${objective.progress}%`}
                </span>
            </div>

            <p className="mt-1 text-sm font-medium text-[var(--color-text-primary)]">{objective.title}</p>

            <div className="mt-3">
                <ProgressBar value={objective.progress ?? 0} color={objective.band.colour} />
            </div>

            <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-[var(--color-text-secondary)]">
                <span>{objective.owner ?? 'Unowned'}</span>
                {objective.entity && <span>· {objective.entity}</span>}
            </div>

            <div className="mt-2 flex flex-wrap gap-2 text-[11px]">
                <span className="rounded bg-gray-100 px-1.5 py-0.5">
                    {objective.threats.risks} risk{objective.threats.risks === 1 ? '' : 's'}
                </span>
                {objective.threats.ineffective_controls > 0 && (
                    <span className="rounded bg-red-50 px-1.5 py-0.5 font-semibold text-[var(--color-error)]">
                        {objective.threats.ineffective_controls} weak control
                        {objective.threats.ineffective_controls === 1 ? '' : 's'}
                    </span>
                )}
            </div>
        </Link>
    );
}
