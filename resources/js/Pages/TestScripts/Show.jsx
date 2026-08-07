import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SeverityBadge from '@/Components/SeverityBadge';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

export default function Show({ script, canApprove = false }) {
    return (
        <AuthenticatedLayout header={script.title}>
            <Head title={script.title} />

            <PageHeader
                title={
                    <span className="flex items-center gap-3">
                        {script.title} <StatusBadge status={script.status} />
                    </span>
                }
                subtitle={
                    <>
                        For{' '}
                        <Link href={route('controls.show', script.control_id)} className="font-mono text-[var(--color-primary)] hover:underline">
                            {script.control?.control_ref}
                        </Link>{' '}
                        · v{script.version_no} · by {script.creator?.name}
                    </>
                }
                breadcrumbs={[{ label: 'Test Scripts', href: route('test-scripts.index') }, { label: script.title }]}
                actions={
                    canApprove && (
                        <PrimaryButton onClick={() => router.post(route('test-scripts.approve', script.id))}>
                            Approve script
                        </PrimaryButton>
                    )
                }
            />

            <div className="mx-auto max-w-4xl space-y-6">
                {(script.objective || script.sampling_guidance) && (
                    <div className="card">
                        <div className="card-body space-y-4 text-sm">
                            {script.objective && (
                                <div>
                                    <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Objective</p>
                                    <p className="mt-1">{script.objective}</p>
                                </div>
                            )}
                            {script.sampling_guidance && (
                                <div>
                                    <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Sampling guidance</p>
                                    <p className="mt-1">{script.sampling_guidance}</p>
                                </div>
                            )}
                        </div>
                    </div>
                )}

                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold">Check items ({script.check_items?.length ?? 0})</h3>
                    </div>
                    <div className="card-body space-y-4">
                        {(script.check_items ?? []).map((item) => (
                            <div key={item.id} className="rounded-lg border border-gray-100 bg-gray-50/60 p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <p className="text-sm font-medium text-[var(--color-text-primary)]">
                                        {item.sequence}. {item.question}
                                    </p>
                                    <div className="flex shrink-0 items-center gap-2">
                                        {item.is_mandatory && <span className="badge badge-status-pending">Mandatory</span>}
                                        <SeverityBadge severity={item.default_severity_on_fail} />
                                    </div>
                                </div>
                                {item.guidance && <p className="mt-2 text-xs text-gray-500">Guidance: {item.guidance}</p>}
                                {item.expected_result && <p className="mt-1 text-xs text-gray-500">Expected: {item.expected_result}</p>}
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
