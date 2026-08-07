import DataTable from '@/Components/DataTable';
import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';

export default function Gaps({ controlGaps = [], orphanControls = [] }) {
    return (
        <AuthenticatedLayout header="Gap Analysis">
            <Head title="Gap Analysis" />

            <PageHeader
                title="Risk–control gap analysis"
                subtitle="Risks without controls, and controls not linked to any risk"
                breadcrumbs={[{ label: 'Risk Register', href: route('risks.index') }, { label: 'Gap analysis' }]}
            />

            <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold text-[var(--color-error)]">
                            Control gaps — risks with no active control ({controlGaps.length})
                        </h3>
                    </div>
                    <DataTable
                        columns={[
                            { field: 'code', label: 'Code', render: (row) => <span className="font-mono text-xs">{row.code}</span> },
                            { field: 'title', label: 'Risk' },
                            { field: 'inherent_rating', label: 'Inherent', render: (row) => <span className="badge badge-critical">{row.inherent_rating}</span> },
                            { field: 'owner', label: 'Owner', render: (row) => row.owner?.name ?? '—' },
                        ]}
                        data={controlGaps}
                        emptyMessage="No gaps — every active risk has at least one active control."
                        onRowClick={(row) => router.visit(route('risks.show', row.id))}
                    />
                </div>

                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold text-[var(--color-warning)]">
                            Orphan controls — no mapped risk ({orphanControls.length})
                        </h3>
                    </div>
                    <DataTable
                        columns={[
                            { field: 'control_ref', label: 'Ref', render: (row) => <span className="font-mono text-xs">{row.control_ref}</span> },
                            { field: 'title', label: 'Control' },
                            { field: 'status', label: 'Status' },
                            { field: 'owner', label: 'Owner', render: (row) => row.owner?.name ?? '—' },
                        ]}
                        data={orphanControls}
                        emptyMessage="No orphans — every control maps to a risk."
                        onRowClick={(row) => router.visit(route('controls.show', row.id))}
                    />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
