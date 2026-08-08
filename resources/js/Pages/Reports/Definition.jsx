import DataTable from '@/Components/DataTable';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';

export default function Definition({ definition, runs, parameterOptions = {}, can = {} }) {
    const form = useForm({
        output_format: (definition.output_formats ?? ['pdf'])[0],
        parameters: {
            period: definition.parameters?.find((parameter) => parameter.key === 'period')?.default ?? 'current_quarter',
            entity_id: '',
        },
    });

    const generate = (event) => {
        event.preventDefault();
        form.post(route('reports.definitions.run', definition.id), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={definition.name}>
            <Head title={definition.name} />

            <PageHeader
                title={definition.name}
                subtitle={definition.description}
                breadcrumbs={[{ label: 'Report library', href: route('reports.library') }, { label: definition.code }]}
                actions={
                    <>
                        {can.design && (
                            <Link href={route('reports.definitions.edit', definition.id)} className="btn-secondary">
                                Open designer
                            </Link>
                        )}
                        {can.approve && !definition.approved_at && (
                            <button
                                type="button"
                                onClick={() => router.post(route('reports.definitions.approve', definition.id))}
                                className="btn-primary"
                            >
                                Approve version {definition.version_no}
                            </button>
                        )}
                    </>
                }
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <section className="card lg:col-span-1">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">Generate now</h2>
                    </div>
                    <form onSubmit={generate} className="card-body space-y-3">
                        <label className="block">
                            <span className="form-label">Format</span>
                            <select
                                className="form-select"
                                value={form.data.output_format}
                                onChange={(event) => form.setData('output_format', event.target.value)}
                            >
                                {(definition.output_formats ?? []).map((format) => (
                                    <option key={format} value={format}>{format.toUpperCase()}</option>
                                ))}
                            </select>
                        </label>

                        <label className="block">
                            <span className="form-label">Period</span>
                            <select
                                className="form-select"
                                value={form.data.parameters.period}
                                onChange={(event) => form.setData('parameters', {
                                    ...form.data.parameters,
                                    period: event.target.value,
                                })}
                            >
                                {Object.entries(parameterOptions.periods ?? {}).map(([value, label]) => (
                                    <option key={value} value={value}>{label}</option>
                                ))}
                            </select>
                        </label>

                        <label className="block">
                            <span className="form-label">Entity</span>
                            <select
                                className="form-select"
                                value={form.data.parameters.entity_id}
                                onChange={(event) => form.setData('parameters', {
                                    ...form.data.parameters,
                                    entity_id: event.target.value,
                                })}
                            >
                                <option value="">Group-wide</option>
                                {(parameterOptions.entities ?? []).map((entity) => (
                                    <option key={entity.id} value={entity.id}>{entity.name}</option>
                                ))}
                            </select>
                        </label>

                        <button type="submit" className="btn-primary w-full" disabled={form.processing || !can.run}>
                            {form.processing ? 'Generating…' : 'Generate'}
                        </button>

                        <p className="text-xs text-[var(--color-text-secondary)]">
                            Classification: <strong>{definition.confidentiality}</strong>. Distribution is filtered
                            to recipients entitled to material at this level.
                        </p>
                    </form>
                </section>

                <section className="card lg:col-span-2">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">Sections</h2>
                        <span className="text-xs text-[var(--color-text-secondary)]">
                            Version {definition.version_no}
                            {definition.approved_at
                                ? ` · approved ${formatDate(definition.approved_at)}`
                                : ' · not yet approved'}
                        </span>
                    </div>
                    <ol className="card-body space-y-2">
                        {(definition.sections ?? []).map((section, index) => (
                            <li key={index} className="flex items-baseline gap-3 text-sm">
                                <span className="w-6 shrink-0 text-xs tabular-nums text-gray-400">{index + 1}</span>
                                <span className="w-32 shrink-0 text-xs uppercase tracking-wide text-gray-400">
                                    {section.type.replace(/_/g, ' ')}
                                </span>
                                <span className="text-[var(--color-text-primary)]">
                                    {section.title ?? '—'}
                                    {section.source && (
                                        <span className="ms-2 font-mono text-[10px] text-gray-400">{section.source}</span>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ol>
                </section>
            </div>

            <section className="card mt-4">
                <div className="card-header">
                    <h2 className="text-sm font-semibold">Recent runs</h2>
                </div>
                <DataTable
                    columns={[
                        { field: 'run_ref', label: 'Reference' },
                        { field: 'output_format', label: 'Format', render: (run) => run.output_format.toUpperCase() },
                        { field: 'status', label: 'Status', render: (run) => <StatusBadge status={run.status} /> },
                        { field: 'requester', label: 'Requested by', render: (run) => run.requester?.name ?? 'Scheduler' },
                        { field: 'completed_at', label: 'Completed', render: (run) => formatDate(run.completed_at) },
                        {
                            field: 'download',
                            label: '',
                            render: (run) => run.status === 'Completed' && (
                                <a
                                    href={route('reports.runs.download', run.id)}
                                    className="text-sm font-medium text-[var(--color-primary)] hover:underline"
                                >
                                    Download
                                </a>
                            ),
                        },
                    ]}
                    data={runs.data}
                    emptyMessage="This report has not been generated yet."
                />
                <Pagination paginator={runs} />
            </section>
        </AuthenticatedLayout>
    );
}
