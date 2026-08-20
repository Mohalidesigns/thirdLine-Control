import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatCard from '@/Components/StatCard';
import VerificationBadge from '@/Components/VerificationBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { BadgeCheck, Gauge, Plus } from 'lucide-react';

const CATEGORY_LABELS = {
    internal_control: 'Internal control',
    risk: 'Risk',
    security: 'Security',
    privacy: 'Privacy',
    governance: 'Governance',
    financial: 'Financial',
    sustainability: 'Sustainability',
    sector: 'Sector',
};

export default function Index({
    frameworks,
    filters = {},
    categories = [],
    verificationStatuses = [],
    jurisdictions = [],
    stats = {},
    can = {},
}) {
    const columns = [
        {
            field: 'code',
            label: 'Code',
            render: (row) => (
                <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{row.code}</span>
            ),
        },
        {
            field: 'name',
            label: 'Framework',
            render: (row) => (
                <div className="max-w-xs">
                    <p className="font-medium text-[var(--color-text-primary)]">{row.name}</p>
                    <p className="truncate text-xs text-gray-400">{row.issuing_body ?? '—'}</p>
                </div>
            ),
        },
        { field: 'jurisdiction', label: 'Jurisdiction' },
        { field: 'version', label: 'Version' },
        {
            field: 'category',
            label: 'Category',
            render: (row) => CATEGORY_LABELS[row.category] ?? row.category,
        },
        { field: 'requirements_count', label: 'Requirements' },
        {
            field: 'verification_status',
            label: 'Verification',
            render: (row) => <VerificationBadge status={row.verification_status} />,
        },
        {
            field: 'source',
            label: 'Source',
            render: (row) => (
                <span className="text-xs text-gray-400">{row.tenant_id ? 'Tenant copy' : 'Shipped pack'}</span>
            ),
        },
    ];

    return (
        <AuthenticatedLayout header="Frameworks">
            <Head title="Frameworks" />

            <PageHeader
                title="Frameworks & Standards"
                subtitle="Every framework the organisation is measured against — international, Nigerian and pan-African"
                actions={
                    can.manage && (
                        <Link href={route('frameworks.create')} className="btn-primary">
                            <Plus className="h-4 w-4" strokeWidth={2} /> Add Framework
                        </Link>
                    )
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-4">
                <StatCard icon={Gauge} title="Frameworks" value={stats.total ?? 0} color="primary" />
                <StatCard icon={BadgeCheck} title="Verified" value={stats.verified ?? 0} color="success" subtitle="Usable in submissions" />
                <StatCard icon={Gauge} title="Nigerian" value={stats.nigerian ?? 0} color="accent" />
                <StatCard icon={Gauge} title="Certifiable" value={stats.certifiable ?? 0} color="info" />
            </div>

            <FilterBar
                route="frameworks.index"
                resource="frameworks"
                currentFilters={filters}
                searchPlaceholder="Search frameworks…"
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    {
                        name: 'jurisdiction',
                        type: 'select',
                        label: 'Jurisdiction',
                        options: jurisdictions.map((j) => ({ value: j, label: j })),
                    },
                    {
                        name: 'category',
                        type: 'select',
                        label: 'Category',
                        options: categories.map((c) => ({ value: c, label: CATEGORY_LABELS[c] ?? c })),
                    },
                    {
                        name: 'verification_status',
                        type: 'select',
                        label: 'Verification',
                        options: verificationStatuses.map((v) => ({ value: v, label: v })),
                    },
                ]}
            />

            <div className="card">
                <DataTable
                    columns={columns}
                    data={frameworks.data}
                    emptyMessage="No frameworks installed yet — install a content pack to get started."
                    onRowClick={(row) => router.visit(route('frameworks.show', row.id))}
                />
                <Pagination paginator={frameworks} />
            </div>
        </AuthenticatedLayout>
    );
}
