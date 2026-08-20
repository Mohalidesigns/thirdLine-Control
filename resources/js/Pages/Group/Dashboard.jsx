import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatCard from '@/Components/StatCard';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

const KPI_LABELS = {
    active_controls: 'Active controls',
    effective_pct: 'Effective %',
    open_exceptions: 'Open exceptions',
    overdue_obligations: 'Overdue obligations',
    open_incidents: 'Open incidents',
    active_risks: 'Active risks',
};

function Delta({ value, invert = false }) {
    if (value === null || value === undefined) return <span className="text-gray-400">—</span>;

    const good = invert ? value < 0 : value > 0;
    const colour = value === 0 ? 'text-gray-500' : good ? 'text-green-600' : 'text-red-600';

    return <span className={`font-mono text-xs ${colour}`}>{value > 0 ? `+${value}` : value}</span>;
}

export default function Dashboard({ rollup }) {
    const { entities = [], group } = rollup;

    const columns = [
        {
            field: 'legal_name',
            label: 'Entity',
            render: (row) => (
                <div>
                    <span className="font-medium text-gray-900">{row.legal_name}</span>
                    <div className="text-xs text-gray-500">
                        {row.entity_type?.replaceAll('_', ' ')} · {row.jurisdiction} · residency {row.data_residency_country}
                    </div>
                </div>
            ),
        },
        {
            field: 'consolidation_method',
            label: 'Consolidation',
            render: (row) => (
                <span className="text-sm">
                    {row.consolidation_method}
                    {row.consolidation_method !== 'full' && ` (${Number(row.ownership_percentage)}%)`}
                </span>
            ),
        },
        { field: 'active_controls', label: 'Controls', render: (r) => r.kpis.active_controls },
        {
            field: 'effective_pct',
            label: 'Effective',
            render: (r) => (r.kpis.effective_pct === null ? '—' : `${r.kpis.effective_pct}%`),
        },
        { field: 'open_exceptions', label: 'Exceptions', render: (r) => r.kpis.open_exceptions },
        { field: 'overdue_obligations', label: 'Overdue obligations', render: (r) => r.kpis.overdue_obligations },
        { field: 'open_incidents', label: 'Incidents', render: (r) => r.kpis.open_incidents },
        { field: 'active_risks', label: 'Risks', render: (r) => r.kpis.active_risks },
        {
            field: 'benchmark',
            label: 'vs group avg (controls / exceptions)',
            render: (r) => (
                <span className="space-x-2">
                    <Delta value={r.benchmark?.active_controls} />
                    <Delta value={r.benchmark?.open_exceptions} invert />
                </span>
            ),
        },
    ];

    return (
        <AuthenticatedLayout header="Group Dashboard">
            <Head title="Group Dashboard" />

            <PageHeader
                title="Consolidated group position"
                subtitle={`${group.entity_count} entities visible to you · ${group.consolidated_count} consolidated — numbers weighted by consolidation method`}
            />

            {entities.length === 0 ? (
                <EmptyState
                    title="No entities are visible to you"
                    description="Group visibility is granted per entity by a named person. Ask your Control Function Head for a grant."
                />
            ) : (
                <>
                    <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                        {Object.entries(KPI_LABELS).map(([kpi, label]) => (
                            <StatCard
                                key={kpi}
                                title={label}
                                value={
                                    group.kpis[kpi] === null || group.kpis[kpi] === undefined
                                        ? '—'
                                        : kpi === 'effective_pct'
                                            ? `${group.kpis[kpi]}%`
                                            : group.kpis[kpi]
                                }
                                subtitle={kpi === 'effective_pct' ? 'weighted' : `avg ${group.averages[kpi] ?? '—'} / entity`}
                            />
                        ))}
                    </div>

                    <div className="card">
                        <div className="card-header">
                            <h3 className="text-sm font-semibold">Entity comparison — standalone numbers, no double counting</h3>
                        </div>
                        <div className="card-body p-0">
                            <DataTable columns={columns} data={entities} />
                        </div>
                    </div>

                    <p className="mt-3 text-xs text-gray-500">
                        Full consolidation carries 100% of an entity's numbers; proportional and equity carry the ownership
                        share; entities marked 'none' are listed but excluded from group totals. You only see entities you
                        hold an explicit grant for — group totals are computed over exactly this set.
                    </p>
                </>
            )}
        </AuthenticatedLayout>
    );
}
