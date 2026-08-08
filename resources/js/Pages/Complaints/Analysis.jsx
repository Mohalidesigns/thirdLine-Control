import DataTable from '@/Components/DataTable';
import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

export default function Analysis({ analysis = [], filters = {}, entities = [], exposure = {} }) {
    const total = analysis.reduce((sum, row) => sum + row.total, 0);

    const columns = [
        { field: 'root_cause', label: 'Root cause' },
        {
            field: 'total',
            label: 'Complaints',
            render: (row) => (
                <div className="flex items-center gap-2">
                    <span className="w-8 tabular-nums font-semibold">{row.total}</span>
                    <div className="h-2 w-32 overflow-hidden rounded-full bg-gray-100">
                        <div
                            className="h-full rounded-full bg-[var(--color-primary)]"
                            style={{ width: `${total > 0 ? (row.total / total) * 100 : 0}%` }}
                        />
                    </div>
                </div>
            ),
        },
        {
            field: 'sla_breached',
            label: 'SLA breached',
            render: (row) =>
                row.sla_breached > 0 ? (
                    <span className="font-semibold text-[var(--color-error)]">{row.sla_breached}</span>
                ) : (
                    <span className="text-gray-400">0</span>
                ),
        },
        {
            field: 'controls',
            label: 'Controls implicated',
            render: (row) =>
                row.controls.length > 0 ? (
                    <div className="flex flex-wrap gap-1">
                        {row.controls.map((control) => (
                            <Link
                                key={control.id}
                                href={route('controls.show', control.id)}
                                className="badge badge-status-draft font-mono text-[10px] hover:underline"
                            >
                                {control.control_ref}
                            </Link>
                        ))}
                    </div>
                ) : (
                    <span className="text-gray-400">none named</span>
                ),
        },
    ];

    return (
        <AuthenticatedLayout header="Complaint root-cause analysis">
            <Head title="Complaint root-cause analysis" />

            <PageHeader
                title="Complaints by root cause"
                subtitle="What complaints are telling you about the control environment"
                breadcrumbs={[{ label: 'Complaints', href: route('complaints.index') }, { label: 'Analysis' }]}
                actions={
                    <Link href={route('complaints.index')} className="btn-secondary">
                        Complaint register
                    </Link>
                }
            />

            <div className="card mb-6">
                <div className="card-body">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
                        <div>
                            <label className="form-label">From</label>
                            <input
                                type="date"
                                className="form-input"
                                defaultValue={filters.from ?? ''}
                                onChange={(e) =>
                                    router.get(route('complaints.analysis'), { ...filters, from: e.target.value }, { preserveState: true })
                                }
                            />
                        </div>
                        <div>
                            <label className="form-label">To</label>
                            <input
                                type="date"
                                className="form-input"
                                defaultValue={filters.to ?? ''}
                                onChange={(e) =>
                                    router.get(route('complaints.analysis'), { ...filters, to: e.target.value }, { preserveState: true })
                                }
                            />
                        </div>
                        <div>
                            <label className="form-label">Entity</label>
                            <select
                                className="form-select"
                                defaultValue={filters.entity_id ?? ''}
                                onChange={(e) =>
                                    router.get(
                                        route('complaints.analysis'),
                                        { ...filters, entity_id: e.target.value },
                                        { preserveState: true },
                                    )
                                }
                            >
                                <option value="">All entities</option>
                                {entities.map((entity) => (
                                    <option key={entity.id} value={entity.id}>
                                        {entity.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="flex items-end">
                            <a
                                href={route('complaints.cpd-return', {
                                    from: filters.from ?? new Date(new Date().getFullYear(), 0, 1).toISOString().slice(0, 10),
                                    to: filters.to ?? new Date().toISOString().slice(0, 10),
                                })}
                                className="btn-secondary w-full text-center"
                            >
                                Export CBN CPD return
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            {Object.keys(exposure).length > 0 && (
                <div className="card mb-6">
                    <div className="card-body">
                        <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                            Live penalty exposure
                        </p>
                        <div className="mt-2 flex flex-wrap gap-6">
                            {Object.entries(exposure).map(([currency, money]) => (
                                <p key={currency} className="text-2xl font-bold text-[var(--color-error)]">
                                    {money.formatted}
                                </p>
                            ))}
                        </div>
                    </div>
                </div>
            )}

            <div className="card">
                <DataTable
                    columns={columns}
                    data={analysis}
                    emptyMessage="No resolved complaints carry a root cause in this period."
                />
            </div>
        </AuthenticatedLayout>
    );
}
