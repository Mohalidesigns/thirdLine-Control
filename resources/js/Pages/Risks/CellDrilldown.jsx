import DataTable from '@/Components/DataTable';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

const BAND_CLASSES = {
    Low: 'badge-low',
    Moderate: 'badge-medium',
    High: 'badge-high',
    Critical: 'badge-critical',
};

export default function CellDrilldown({ risks, cell }) {
    const columns = [
        {
            field: 'code',
            label: 'Code',
            render: (row) => <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{row.code}</span>,
        },
        {
            field: 'title',
            label: 'Risk',
            render: (row) => (
                <div>
                    <p className="font-medium text-[var(--color-text-primary)]">{row.title}</p>
                    <p className="text-xs text-gray-400">
                        {row.risk_category?.name ?? '—'}
                        {row.entity ? ` · ${row.entity.name}` : ''}
                    </p>
                </div>
            ),
        },
        {
            field: 'residual_rating',
            label: 'Residual',
            render: (row) => (
                <span className={`badge ${BAND_CLASSES[row.current_rating_band] ?? 'badge-status-draft'}`}>
                    {row.residual_rating ?? row.inherent_rating} · {row.current_rating_band ?? '—'}
                </span>
            ),
        },
        {
            field: 'appetite_breached',
            label: 'Appetite',
            render: (row) =>
                row.appetite_breached ? <span className="badge badge-critical">Outside</span> : <span className="badge badge-low">Within</span>,
        },
        { field: 'owner', label: 'Owner', render: (row) => row.owner?.name ?? '—' },
    ];

    return (
        <AuthenticatedLayout header="Heatmap cell">
            <Head title="Heatmap cell" />

            <PageHeader
                title={`Likelihood ${cell.likelihood} × Impact ${cell.impact}`}
                subtitle={`Risks in this cell on the ${cell.view} view`}
                breadcrumbs={[
                    { label: 'Risk Register', href: route('risks.index') },
                    { label: 'Heatmap', href: route('risks.heatmap', { view: cell.view }) },
                    { label: `L${cell.likelihood} / I${cell.impact}` },
                ]}
                actions={
                    <Link href={route('risks.heatmap', { view: cell.view })} className="btn-secondary">
                        Back to heatmap
                    </Link>
                }
            />

            <div className="card">
                <DataTable
                    columns={columns}
                    data={risks.data}
                    emptyMessage="No risks sit in this cell."
                    onRowClick={(row) => router.visit(route('risks.show', row.id))}
                />
                <Pagination paginator={risks} />
            </div>
        </AuthenticatedLayout>
    );
}
