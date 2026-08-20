import DataTable from '@/Components/DataTable';
import PageHeader from '@/Components/PageHeader';
import ProgressBar from '@/Components/ProgressBar';
import StatCard from '@/Components/StatCard';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, CircleSlash, Gauge } from 'lucide-react';

export default function Gaps({ analysis = {}, frameworks = [], filters = {} }) {
    const columns = [
        {
            field: 'ref_code',
            label: 'Requirement',
            render: (row) => <span className="font-mono text-xs font-semibold">{row.ref_code}</span>,
        },
        { field: 'title', label: 'Title' },
        {
            field: 'framework',
            label: 'Framework',
            render: (row) => (row.framework ? `${row.framework.code} — ${row.framework.name}` : '—'),
        },
    ];

    return (
        <AuthenticatedLayout header="Policy gap analysis">
            <Head title="Policy gap analysis" />

            <PageHeader
                title="Policy gap analysis"
                subtitle="Framework requirements that no published policy claims to govern"
                breadcrumbs={[{ label: 'Policies', href: route('policies.index') }, { label: 'Gap analysis' }]}
                actions={
                    <Link href={route('policies.index')} className="btn-secondary">
                        Policy library
                    </Link>
                }
            />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard icon={Gauge} title="Requirements" value={analysis.total_requirements ?? 0} color="primary" />
                <StatCard icon={CheckCircle2} title="Governed by a policy" value={analysis.governed ?? 0} color="success" />
                <StatCard icon={CircleSlash}
                    title="Ungoverned"
                    value={(analysis.gaps ?? []).length}
                    color="danger"
                    prominent={(analysis.gaps ?? []).length > 0}
                />
            </div>

            <div className="card mb-6">
                <div className="card-body">
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                                Policy coverage
                            </p>
                            <p className="mt-1 text-2xl font-bold text-[var(--color-primary)]">
                                {analysis.coverage_percent ?? 0}%
                            </p>
                        </div>
                        <div className="w-full sm:w-64">
                            <label className="form-label">Framework</label>
                            <select
                                className="form-select"
                                value={filters.framework_id ?? ''}
                                onChange={(e) =>
                                    router.get(
                                        route('policies.gaps'),
                                        e.target.value ? { framework_id: e.target.value } : {},
                                        { preserveState: true },
                                    )
                                }
                            >
                                <option value="">All frameworks</option>
                                {frameworks.map((framework) => (
                                    <option key={framework.id} value={framework.id}>
                                        {framework.code} — {framework.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <ProgressBar value={analysis.coverage_percent ?? 0} />
                    <p className="mt-2 text-xs text-gray-400">
                        A requirement can be fully controlled and still ungoverned — a regulator asks for the policy,
                        not the control.
                    </p>
                </div>
            </div>

            <div className="card">
                <div className="card-header">Ungoverned requirements</div>
                <DataTable
                    columns={columns}
                    data={analysis.gaps ?? []}
                    emptyMessage="Every requirement is governed by a published policy."
                />
            </div>
        </AuthenticatedLayout>
    );
}
