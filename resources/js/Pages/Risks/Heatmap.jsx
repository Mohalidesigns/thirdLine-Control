import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import RiskHeatmap from '@/Components/RiskHeatmap';
import StatCard from '@/Components/StatCard';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Gauge, Info } from 'lucide-react';

const VIEW_LABELS = {
    inherent: 'Inherent',
    residual: 'Residual',
    target: 'Target',
    movement: 'Residual → target',
};

export default function Heatmap({
    heatmap,
    filters = {},
    views = [],
    riskCategories = [],
    entities = [],
    processes = [],
    owners = [],
    frameworks = [],
}) {
    const setView = (view) =>
        router.get(route('risks.heatmap'), { ...filters, view }, { preserveState: true, replace: true });

    const openCell = (cell) =>
        router.get(route('risks.heatmap.cell'), {
            ...filters,
            view: filters.view ?? heatmap.view,
            likelihood: cell.likelihood,
            impact: cell.impact,
        });

    const breaching = heatmap.cells?.reduce((total, cell) => total + (cell.breach_count ?? 0), 0) ?? 0;

    return (
        <AuthenticatedLayout header="Risk Heatmap">
            <Head title="Risk Heatmap" />

            <PageHeader
                title="Risk Heatmap"
                subtitle="The grid, its levels and every cell colour come from the tenant's own assessment scales"
                breadcrumbs={[{ label: 'Risk Register', href: route('risks.index') }, { label: 'Heatmap' }]}
                actions={
                    <Link href={route('risks.index')} className="btn-secondary">
                        Back to register
                    </Link>
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
                <StatCard icon={Gauge} title="Risks in scope" value={heatmap.total ?? 0} color="primary" />
                <StatCard icon={Gauge} title="Plotted" value={heatmap.plotted ?? 0} subtitle="With a position on this view" color="info" />
                <StatCard icon={AlertTriangle} title="Outside appetite" value={breaching} color="danger" prominent={breaching > 0} />
                <StatCard icon={Info} title="Grid" value={`${heatmap.likelihood?.length ?? 0} × ${heatmap.impact?.length ?? 0}`} color="accent" />
            </div>

            <div className="mb-4 flex flex-wrap gap-2">
                {views.map((view) => (
                    <button
                        key={view}
                        type="button"
                        onClick={() => setView(view)}
                        className={
                            (filters.view ?? heatmap.view) === view
                                ? 'btn-primary !py-1.5 !text-xs'
                                : 'btn-secondary !py-1.5 !text-xs'
                        }
                    >
                        {VIEW_LABELS[view] ?? view}
                    </button>
                ))}
            </div>

            <FilterBar
                route="risks.heatmap"
                resource="risk-heatmap"
                currentFilters={filters}
                filters={[
                    {
                        name: 'category_id',
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
                        name: 'process_id',
                        type: 'select',
                        label: 'Process',
                        options: processes.map((p) => ({ value: p.id, label: p.name })),
                    },
                    {
                        name: 'owner_id',
                        type: 'select',
                        label: 'Owner',
                        options: owners.map((o) => ({ value: o.id, label: o.name })),
                    },
                    {
                        name: 'framework_id',
                        type: 'select',
                        label: 'Framework',
                        options: frameworks.map((f) => ({ value: f.id, label: f.name })),
                    },
                    { name: 'breach_only', type: 'checkbox', label: 'Outside appetite' },
                ]}
            />

            <div className="card">
                <div className="card-body">
                    <RiskHeatmap heatmap={heatmap} onCellClick={openCell} />
                </div>
            </div>

            <p className="mt-3 text-xs text-[var(--color-text-secondary)]">
                Bubble size reflects the total financial impact in a cell; the number is the risk count. Select a cell to
                list the risks behind it.
            </p>
        </AuthenticatedLayout>
    );
}
