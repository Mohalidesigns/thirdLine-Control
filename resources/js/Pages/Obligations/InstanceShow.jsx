import EvidencePanel from '@/Components/EvidencePanel';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import VerificationBadge from '@/Components/VerificationBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { daysUntil, formatDate, formatDateTime, formatMoney } from '@/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function InstanceShow({ instance, can = {} }) {
    const [submitting, setSubmitting] = useState(false);
    const [waiving, setWaiving] = useState(false);
    const [waiveReason, setWaiveReason] = useState('');

    const obligation = instance.obligation ?? {};
    const remaining = daysUntil(instance.due_at);
    const hasEvidence = (instance.evidence?.length ?? 0) > 0;

    const submitForm = useForm({ submission_reference: '', acknowledgement_ref: '', notes: '' });

    const submit = (event) => {
        event.preventDefault();
        submitForm.post(route('obligations.instances.submit', instance.id), {
            preserveScroll: true,
            onSuccess: () => setSubmitting(false),
        });
    };

    const review = (decision) =>
        router.post(route('obligations.instances.review', instance.id), { decision }, { preserveScroll: true });

    return (
        <AuthenticatedLayout header={obligation.obligation_ref ?? 'Obligation'}>
            <Head title={`${obligation.title ?? 'Obligation'} — ${instance.period_label}`} />

            <PageHeader
                breadcrumbs={[
                    { label: 'Compliance Calendar', href: route('obligations.calendar') },
                    { label: obligation.obligation_ref, href: route('obligations.show', obligation.id) },
                    { label: instance.period_label },
                ]}
                title={obligation.title ?? 'Obligation'}
                subtitle={`${obligation.regulator?.name ?? '—'} · ${instance.assignment?.entity?.name ?? '—'} · ${instance.period_label}`}
                actions={
                    <>
                        {can.update && instance.status === 'Not Started' && (
                            <button
                                type="button"
                                onClick={() => router.post(route('obligations.instances.start', instance.id), {}, { preserveScroll: true })}
                                className="btn-secondary"
                            >
                                Start work
                            </button>
                        )}
                        {can.submit && (
                            <button type="button" onClick={() => setSubmitting(true)} className="btn-primary">
                                Record submission
                            </button>
                        )}
                        {can.review && (
                            <>
                                <button type="button" onClick={() => review('Accepted')} className="btn-success">
                                    Accept
                                </button>
                                <button type="button" onClick={() => review('Rejected')} className="btn-danger">
                                    Reject
                                </button>
                            </>
                        )}
                        {can.waive && (
                            <button type="button" onClick={() => setWaiving(true)} className="btn-secondary">
                                Waive
                            </button>
                        )}
                    </>
                }
            />

            {obligation.verification_status !== 'verified' && (
                <div className="mb-4 rounded-lg border-l-4 border-l-[var(--color-warning)] bg-amber-50 px-4 py-3 text-sm">
                    <VerificationBadge status={obligation.verification_status} />{' '}
                    <span className="ml-1">
                        This obligation’s detail has not been confirmed against the regulator’s primary document. It is
                        excluded from generated submissions — file from the source document, not from this record.
                    </span>
                </div>
            )}

            <div className="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-4">
                <StatCard title="Status" value={<StatusBadge status={instance.status} />} color="primary" />
                <StatCard
                    title="Due"
                    value={formatDate(instance.due_at)}
                    color={remaining !== null && remaining < 0 ? 'danger' : 'info'}
                    subtitle={
                        remaining === null
                            ? null
                            : remaining < 0
                              ? `${Math.abs(remaining)} day(s) overdue`
                              : `${remaining} day(s) remaining`
                    }
                />
                <StatCard title="Evidence" value={instance.evidence?.length ?? 0} color={hasEvidence ? 'success' : 'warning'} />
                <StatCard
                    title="Penalty exposure"
                    value={instance.penalty_exposure ? formatMoney(instance.penalty_exposure) : '—'}
                    color="danger"
                    prominent={(instance.penalty_exposure_minor ?? 0) > 0}
                />
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <div className="card">
                        <div className="card-header">
                            <h2 className="text-sm font-semibold">Filing detail</h2>
                        </div>
                        <div className="card-body">
                            <dl className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <dt className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Period</dt>
                                    <dd className="mt-1 text-sm">
                                        {instance.period_label} ({formatDate(instance.period_start)} — {formatDate(instance.period_end)})
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Owner</dt>
                                    <dd className="mt-1 text-sm">{instance.assignment?.owner?.name ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                                        Submission reference
                                    </dt>
                                    <dd className="mt-1 font-mono text-sm">{instance.submission_reference ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                                        Acknowledgement
                                    </dt>
                                    <dd className="mt-1 font-mono text-sm">{instance.acknowledgement_ref ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Submitted</dt>
                                    <dd className="mt-1 text-sm">
                                        {instance.submitted_at ? `${formatDateTime(instance.submitted_at)} by ${instance.submitter?.name ?? '—'}` : '—'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Reviewed</dt>
                                    <dd className="mt-1 text-sm">
                                        {instance.reviewed_at ? `${formatDateTime(instance.reviewed_at)} by ${instance.reviewer?.name ?? '—'}` : '—'}
                                    </dd>
                                </div>
                            </dl>

                            {instance.notes && (
                                <div className="mt-4 rounded-lg bg-gray-50 p-3 text-sm">{instance.notes}</div>
                            )}

                            {obligation.requirement && (
                                <p className="mt-4 text-xs text-gray-400">
                                    Satisfies{' '}
                                    <Link href={route('frameworks.show', obligation.requirement.framework_id)} className="underline">
                                        {obligation.requirement.ref_code} — {obligation.requirement.title}
                                    </Link>
                                </p>
                            )}
                        </div>
                    </div>

                    <EvidencePanel
                        linkedType="obligation_instance"
                        linkedId={instance.id}
                        evidence={instance.evidence ?? []}
                        canUpload={can.update}
                    />
                </div>

                <div className="card h-fit">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">What the regulator requires</h2>
                    </div>
                    <div className="card-body space-y-3 text-sm">
                        {obligation.description && <p>{obligation.description}</p>}
                        <p className="text-xs text-gray-400">{obligation.legal_reference ?? 'No legal reference recorded.'}</p>
                        {obligation.penalty_description && (
                            <div className="rounded-lg border-l-4 border-l-[var(--color-error)] bg-red-50 p-3 text-xs">
                                {obligation.penalty_description}
                            </div>
                        )}
                        {obligation.source_url && (
                            <a
                                href={obligation.source_url}
                                target="_blank"
                                rel="noreferrer noopener"
                                className="block text-xs text-[var(--color-primary)] underline"
                            >
                                Open the source document
                            </a>
                        )}
                    </div>
                </div>
            </div>

            <Modal show={submitting} maxWidth="md" title="Record the submission" onClose={() => setSubmitting(false)}>
                <form onSubmit={submit} className="space-y-4">
                    {!hasEvidence && (
                        <div className="rounded-lg border-l-4 border-l-[var(--color-warning)] bg-amber-50 px-3 py-2 text-sm">
                            Attach at least one evidence item before recording the submission — a filing has to be
                            provable after the fact.
                        </div>
                    )}

                    <div>
                        <InputLabel htmlFor="submission_reference" value="Submission reference" />
                        <TextInput
                            id="submission_reference"
                            value={submitForm.data.submission_reference}
                            onChange={(e) => submitForm.setData('submission_reference', e.target.value)}
                            className="mt-1 block w-full"
                            placeholder="The reference the regulator issued"
                            required
                        />
                        <InputError message={submitForm.errors.submission_reference} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="acknowledgement_ref" value="Acknowledgement reference (optional)" />
                        <TextInput
                            id="acknowledgement_ref"
                            value={submitForm.data.acknowledgement_ref}
                            onChange={(e) => submitForm.setData('acknowledgement_ref', e.target.value)}
                            className="mt-1 block w-full"
                        />
                    </div>

                    <div>
                        <InputLabel htmlFor="notes" value="Notes" />
                        <TextArea
                            id="notes"
                            value={submitForm.data.notes}
                            onChange={(e) => submitForm.setData('notes', e.target.value)}
                            className="mt-1 block w-full"
                            rows={3}
                        />
                    </div>

                    <InputError message={submitForm.errors.evidence} />

                    <div className="flex justify-end gap-2">
                        <SecondaryButton type="button" onClick={() => setSubmitting(false)}>
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton disabled={submitForm.processing || !hasEvidence}>Mark submitted</PrimaryButton>
                    </div>
                </form>
            </Modal>

            <Modal show={waiving} maxWidth="md" title="Waive this filing" onClose={() => setWaiving(false)}>
                <p className="text-sm text-[var(--color-text-secondary)]">
                    Waiving closes the entry and zeroes its penalty exposure. The reason is written to the audit trail.
                </p>
                <TextArea
                    value={waiveReason}
                    onChange={(e) => setWaiveReason(e.target.value)}
                    rows={4}
                    className="mt-3 block w-full"
                    placeholder="Why is this filing being waived? (minimum 20 characters)"
                />
                <div className="mt-6 flex justify-end gap-2">
                    <SecondaryButton onClick={() => setWaiving(false)}>Cancel</SecondaryButton>
                    <PrimaryButton
                        disabled={waiveReason.trim().length < 20}
                        onClick={() =>
                            router.post(
                                route('obligations.instances.waive', instance.id),
                                { reason: waiveReason },
                                { preserveScroll: true, onFinish: () => setWaiving(false) },
                            )
                        }
                    >
                        Waive
                    </PrimaryButton>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
