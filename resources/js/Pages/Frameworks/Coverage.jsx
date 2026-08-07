import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import ProgressBar from '@/Components/ProgressBar';
import VerificationBadge from '@/Components/VerificationBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

const CELL_STYLES = {
    effective: 'bg-[var(--color-success)]',
    mapped: 'bg-[var(--color-warning)]',
    unmapped: 'bg-gray-200',
};

const CELL_LABELS = {
    effective: 'Approved mapping to an active control with a current effective rating',
    mapped: 'Approved mapping, but no current effective rating',
    unmapped: 'No approved control mapping',
};

export default function Coverage({ framework, matrix, summary = {}, perEntity = [] }) {
    const { requirements = [], entities = [], cells = {} } = matrix ?? {};

    return (
        <AuthenticatedLayout header={`${framework.code} coverage`}>
            <Head title={`${framework.code} coverage`} />

            <PageHeader
                breadcrumbs={[
                    { label: 'Frameworks', href: route('frameworks.index') },
                    { label: framework.code, href: route('frameworks.show', framework.id) },
                    { label: 'Coverage' },
                ]}
                title={`${framework.name} — coverage`}
                subtitle="Requirements against entities. Green means an approved control mapping with a current effective rating."
                actions={
                    <Link href={route('frameworks.show', framework.id)} className="btn-secondary">
                        Back to requirements
                    </Link>
                }
            />

            <div className="mb-6 card">
                <div className="card-body">
                    <div className="mb-1 flex flex-wrap items-center justify-between gap-2 text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                        <span>Group-wide coverage</span>
                        <span>
                            {summary.effective_requirements ?? 0}/{summary.testable_requirements ?? 0} requirements
                            effective ({summary.effective_percentage ?? 0}%)
                        </span>
                    </div>
                    <ProgressBar value={summary.effective_percentage ?? 0} />
                </div>
            </div>

            {perEntity.length > 0 && (
                <div className="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {perEntity.map((row) => (
                        <div key={row.entity.id} className="card">
                            <div className="card-body">
                                <p className="text-sm font-medium">{row.entity.name}</p>
                                <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                                    {row.effective_requirements}/{row.testable_requirements} effective ·{' '}
                                    {row.unmapped_requirements} unmapped
                                </p>
                                <div className="mt-2">
                                    <ProgressBar value={row.effective_percentage} />
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            <div className="card">
                <div className="card-header flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-sm font-semibold">Requirement × entity heatmap</h2>
                    <div className="flex items-center gap-3 text-xs text-gray-400">
                        {Object.entries(CELL_STYLES).map(([state, style]) => (
                            <span key={state} className="flex items-center gap-1">
                                <span className={`h-3 w-3 rounded-sm ${style}`} /> {state}
                            </span>
                        ))}
                    </div>
                </div>

                {requirements.length === 0 || entities.length === 0 ? (
                    <EmptyState
                        title="Nothing to plot yet"
                        description="Add entities with a regulatory profile and map controls to this framework’s requirements."
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="data-table min-w-full">
                            <thead>
                                <tr>
                                    <th className="sticky left-0 z-10 bg-white">Requirement</th>
                                    {entities.map((entity) => (
                                        <th key={entity.id} className="whitespace-nowrap text-xs">
                                            {entity.name}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {requirements.map((requirement) => (
                                    <tr key={requirement.id}>
                                        <td className="sticky left-0 z-10 bg-white">
                                            <div className="flex min-w-[14rem] items-center gap-2">
                                                <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">
                                                    {requirement.ref_code}
                                                </span>
                                                <span className="truncate text-sm">{requirement.title}</span>
                                                {requirement.verification_status !== 'verified' && (
                                                    <VerificationBadge status={requirement.verification_status} />
                                                )}
                                            </div>
                                        </td>
                                        {entities.map((entity) => {
                                            const state = cells?.[requirement.id]?.[entity.id] ?? 'unmapped';

                                            return (
                                                <td key={entity.id} className="text-center">
                                                    <span
                                                        className={`inline-block h-4 w-8 rounded-sm ${CELL_STYLES[state]}`}
                                                        title={`${entity.name} — ${CELL_LABELS[state]}`}
                                                    />
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
