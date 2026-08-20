import DataTable from '@/Components/DataTable';
import PageHeader from '@/Components/PageHeader';
import StatCard from '@/Components/StatCard';
import VerificationBadge from '@/Components/VerificationBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, Gauge, TrendingUp } from 'lucide-react';

const LINK_LABELS = {
    direct: 'Mapped directly',
    equivalent: 'Inherited — equivalent requirement',
    partial: 'Inherited — partial equivalence',
};

export default function CrossFramework({ control, rows = [] }) {
    const frameworks = new Set(rows.map((row) => row.framework));
    const approved = rows.filter((row) => row.approved);

    const columns = [
        {
            field: 'framework',
            label: 'Framework',
            render: (row) => <span className="font-mono text-xs font-semibold">{row.framework}</span>,
        },
        {
            field: 'ref_code',
            label: 'Requirement',
            render: (row) => (
                <div className="min-w-0">
                    <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{row.ref_code}</span>
                    <p className="truncate text-sm">{row.title}</p>
                </div>
            ),
        },
        {
            field: 'link',
            label: 'Link',
            render: (row) => (
                <span className="badge badge-status-draft" title={LINK_LABELS[row.link] ?? row.link}>
                    {row.link}
                </span>
            ),
        },
        {
            field: 'coverage',
            label: 'Coverage',
            render: (row) => <span className="badge badge-status-draft">{row.coverage}</span>,
        },
        {
            field: 'approved',
            label: 'Counts toward coverage',
            render: (row) =>
                row.approved ? (
                    <span className="badge badge-low">Yes</span>
                ) : (
                    <span className="badge badge-medium">Awaiting approval</span>
                ),
        },
        {
            field: 'verification_status',
            label: 'Requirement status',
            render: (row) => <VerificationBadge status={row.verification_status} />,
        },
    ];

    return (
        <AuthenticatedLayout header="Cross-framework coverage">
            <Head title={`${control.control_ref} — frameworks satisfied`} />

            <PageHeader
                breadcrumbs={[
                    { label: 'Control Library', href: route('controls.index') },
                    { label: control.control_ref, href: route('controls.show', control.id) },
                    { label: 'Frameworks' },
                ]}
                title={`${control.control_ref} — test once, satisfy many`}
                subtitle={control.title}
                actions={
                    <Link href={route('controls.show', control.id)} className="btn-secondary">
                        Back to control
                    </Link>
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-3">
                <StatCard icon={CheckCircle2} title="Requirements satisfied" value={rows.length} color="primary" />
                <StatCard icon={Gauge} title="Frameworks touched" value={frameworks.size} color="accent" />
                <StatCard icon={TrendingUp}
                    title="Counting toward coverage"
                    value={approved.length}
                    color={approved.length === rows.length ? 'success' : 'warning'}
                    subtitle={approved.length === rows.length ? 'All approved' : 'Some mappings await approval'}
                />
            </div>

            <div className="card">
                <DataTable
                    columns={columns}
                    data={rows}
                    emptyMessage="This control is not yet mapped to any framework requirement."
                />
            </div>
        </AuthenticatedLayout>
    );
}
