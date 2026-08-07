import ActivityTab from '@/Components/ActivityTab';
import EvidencePanel from '@/Components/EvidencePanel';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import StatusBadge from '@/Components/StatusBadge';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const RESULT_STYLES = {
    Pass: 'bg-[var(--color-success)] text-white border-[var(--color-success)]',
    Fail: 'bg-[var(--color-error)] text-white border-[var(--color-error)]',
    NA: 'bg-gray-500 text-white border-gray-500',
};

function CheckItemRow({ item, result, editable, instanceId }) {
    const [comment, setComment] = useState(result?.comment ?? '');
    const [pendingResult, setPendingResult] = useState(null);
    const currentResult = result?.result ?? null;

    const record = (value, withComment) => {
        router.post(
            route('test-instances.results', instanceId),
            { check_item_id: item.id, result: value, comment: withComment ?? comment ?? '' },
            {
                preserveScroll: true,
                onSuccess: () => setPendingResult(null),
                onError: () => setPendingResult(value),
            },
        );
    };

    const choose = (value) => {
        if (value === 'Pass') {
            record(value, '');
        } else {
            setPendingResult(value); // Fail / NA need a comment first
        }
    };

    return (
        <div className="rounded-lg border border-gray-100 p-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex-1">
                    <p className="text-sm font-medium text-[var(--color-text-primary)]">
                        {item.sequence}. {item.question}
                        {item.is_mandatory && <span className="ms-1 text-red-500">*</span>}
                    </p>
                    {item.guidance && <p className="mt-1 text-xs text-gray-500">Guidance: {item.guidance}</p>}
                    {item.expected_result && <p className="mt-0.5 text-xs text-gray-500">Expected: {item.expected_result}</p>}
                    {currentResult && result?.comment && (
                        <p className="mt-2 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600">{result.comment}</p>
                    )}
                </div>

                <div className="flex shrink-0 gap-1.5">
                    {['Pass', 'Fail', 'NA'].map((value) => (
                        <button
                            key={value}
                            type="button"
                            disabled={!editable}
                            onClick={() => choose(value)}
                            className={`rounded-lg border px-3.5 py-1.5 text-xs font-semibold transition-colors duration-200 ${
                                currentResult === value
                                    ? RESULT_STYLES[value]
                                    : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50'
                            } ${!editable ? 'cursor-not-allowed opacity-60' : ''}`}
                        >
                            {value === 'NA' ? 'N/A' : value}
                        </button>
                    ))}
                </div>
            </div>

            {pendingResult && (
                <div className="mt-3 space-y-2 border-t border-gray-100 pt-3">
                    <InputLabel value={`Comment (required for ${pendingResult === 'NA' ? 'N/A' : pendingResult})`} required />
                    <TextArea rows={2} value={comment} onChange={(e) => setComment(e.target.value)} autoFocus />
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={() => setPendingResult(null)}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={!comment.trim()} onClick={() => record(pendingResult)}>
                            Record {pendingResult === 'NA' ? 'N/A' : pendingResult}
                        </PrimaryButton>
                    </div>
                </div>
            )}
        </div>
    );
}

export default function Show({ instance, evidence = [], can = {} }) {
    const items = instance.test_script?.check_items ?? [];
    const resultsByItem = Object.fromEntries((instance.check_results ?? []).map((r) => [r.check_item_id, r]));
    const answered = items.filter((item) => resultsByItem[item.id]?.result).length;
    const editable = can.execute && ['Scheduled', 'In Progress', 'Reopened'].includes(instance.status);

    const [showSubmit, setShowSubmit] = useState(false);
    const [showReturn, setShowReturn] = useState(false);
    const [showReopen, setShowReopen] = useState(false);
    const [showRate, setShowRate] = useState(false);

    const submitForm = useForm({
        population_size: instance.population_size ?? '',
        sample_size: instance.sample_size ?? '',
        sampling_method: instance.sampling_method ?? '',
        sample_items: instance.sample_items ?? '',
    });

    const returnForm = useForm({ approved: false, notes: '' });
    const reopenForm = useForm({ reason: '' });
    const rateForm = useForm({
        design_effectiveness: 'Effective',
        design_rationale: '',
        operating_effectiveness: 'Effective',
        operating_rationale: '',
    });

    const rating = instance.effectiveness_rating;

    return (
        <AuthenticatedLayout header={instance.reference}>
            <Head title={instance.reference} />

            <PageHeader
                title={
                    <span className="flex items-center gap-3">
                        {instance.control?.title} <StatusBadge status={instance.status} />
                    </span>
                }
                subtitle={
                    <span>
                        <span className="font-mono">{instance.reference}</span> · {instance.period_label} · due {formatDate(instance.due_date)}
                        {instance.is_overdue && <span className="ms-2 badge badge-status-overdue">Overdue</span>}
                    </span>
                }
                breadcrumbs={[{ label: 'Control Testing', href: route('test-instances.index') }, { label: instance.reference }]}
                actions={
                    <>
                        {can.execute && instance.status === 'Scheduled' && (
                            <PrimaryButton onClick={() => router.post(route('test-instances.start', instance.id))}>
                                Start test
                            </PrimaryButton>
                        )}
                        {editable && instance.status !== 'Scheduled' && (
                            <PrimaryButton onClick={() => setShowSubmit(true)} disabled={answered < items.length}>
                                Submit for review
                            </PrimaryButton>
                        )}
                        {can.review && (
                            <>
                                <PrimaryButton
                                    onClick={() => router.post(route('test-instances.review', instance.id), { approved: true })}
                                >
                                    Sign off
                                </PrimaryButton>
                                <SecondaryButton onClick={() => setShowReturn(true)}>Return to tester</SecondaryButton>
                            </>
                        )}
                        {can.rate && instance.status === 'Submitted' && !rating && (
                            <SecondaryButton onClick={() => setShowRate(true)}>Rate effectiveness</SecondaryButton>
                        )}
                        {can.approveRating && rating?.status === 'Pending Approval' && (
                            <PrimaryButton onClick={() => router.post(route('test-instances.approve-rating', instance.id))}>
                                Approve rating
                            </PrimaryButton>
                        )}
                        {can.reopen && (
                            <SecondaryButton onClick={() => setShowReopen(true)}>Reopen</SecondaryButton>
                        )}
                    </>
                }
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <div className="card">
                        <div className="card-header">
                            <h3 className="text-sm font-semibold">Checklist</h3>
                            <span className="text-sm text-gray-500">{answered}/{items.length} answered</span>
                        </div>
                        <div className="card-body space-y-3">
                            {items.length === 0 && (
                                <p className="text-sm text-[var(--color-warning)]">
                                    No approved test script for this control.{' '}
                                    <Link href={route('test-scripts.create', { control_id: instance.control_id })} className="underline">
                                        Build one
                                    </Link>
                                    .
                                </p>
                            )}
                            {items.map((item) => (
                                <CheckItemRow
                                    key={item.id}
                                    item={item}
                                    result={resultsByItem[item.id]}
                                    editable={editable}
                                    instanceId={instance.id}
                                />
                            ))}
                        </div>
                    </div>

                    <EvidencePanel
                        linkedType="test_instance"
                        linkedId={instance.id}
                        evidence={evidence}
                        canUpload={can.execute || can.review}
                    />

                    {instance.review_notes && (
                        <div className="card border-l-4 border-l-[var(--color-info)]">
                            <div className="card-body">
                                <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Review notes</p>
                                <p className="mt-1 text-sm">{instance.review_notes}</p>
                            </div>
                        </div>
                    )}
                </div>

                <div className="space-y-6">
                    <div className="card">
                        <div className="card-body space-y-2 text-sm">
                            <p><span className="text-gray-500">Control:</span>{' '}
                                <Link href={route('controls.show', instance.control_id)} className="font-mono text-xs text-[var(--color-primary)] hover:underline">
                                    {instance.control?.control_ref}
                                </Link>
                            </p>
                            <p><span className="text-gray-500">Tester:</span> {instance.tester?.name ?? 'Unassigned'}</p>
                            <p><span className="text-gray-500">Reviewer:</span> {instance.reviewer?.name ?? '—'}</p>
                            <p><span className="text-gray-500">Period:</span> {formatDate(instance.period_start)} → {formatDate(instance.period_end)}</p>
                        </div>
                    </div>

                    {(instance.population_size || instance.sample_size) && (
                        <div className="card">
                            <div className="card-header"><h3 className="text-sm font-semibold">Sampling</h3></div>
                            <div className="card-body space-y-1.5 text-sm">
                                <p><span className="text-gray-500">Population:</span> {instance.population_size ?? '—'}</p>
                                <p><span className="text-gray-500">Sample:</span> {instance.sample_size ?? '—'}</p>
                                <p><span className="text-gray-500">Method:</span> {instance.sampling_method ?? '—'}</p>
                                {instance.sample_items && <p className="text-xs text-gray-500">{instance.sample_items}</p>}
                            </div>
                        </div>
                    )}

                    {rating && (
                        <div className="card">
                            <div className="card-header">
                                <h3 className="text-sm font-semibold">Effectiveness rating</h3>
                                <StatusBadge status={rating.status} />
                            </div>
                            <div className="card-body space-y-2 text-sm">
                                <p className="flex items-center justify-between">
                                    <span className="text-gray-500">Design</span>
                                    <StatusBadge status={rating.design_effectiveness} />
                                </p>
                                <p className="flex items-center justify-between">
                                    <span className="text-gray-500">Operating</span>
                                    <StatusBadge status={rating.operating_effectiveness} />
                                </p>
                                <p className="flex items-center justify-between border-t border-gray-100 pt-2">
                                    <span className="font-medium">Overall</span>
                                    <StatusBadge status={rating.overall_rating} />
                                </p>
                            </div>
                        </div>
                    )}

                    {instance.reopen_reason && (
                        <div className="card border-l-4 border-l-[var(--color-warning)]">
                            <div className="card-body text-sm">
                                <p className="text-xs font-semibold uppercase text-[var(--color-text-secondary)]">Reopen reason</p>
                                <p className="mt-1">{instance.reopen_reason}</p>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* Submit modal — sampling capture (FR-3.5) */}
            <Modal show={showSubmit} onClose={() => setShowSubmit(false)} title="Submit for review" maxWidth="lg">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        submitForm.post(route('test-instances.submit', instance.id), { onSuccess: () => setShowSubmit(false) });
                    }}
                    className="space-y-4"
                >
                    <p className="text-sm text-gray-500">
                        Any Failed check item will automatically raise an exception on submission.
                    </p>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel value="Population size" />
                            <TextInput type="number" value={submitForm.data.population_size} onChange={(e) => submitForm.setData('population_size', e.target.value)} />
                        </div>
                        <div>
                            <InputLabel value="Sample size" />
                            <TextInput type="number" value={submitForm.data.sample_size} onChange={(e) => submitForm.setData('sample_size', e.target.value)} />
                        </div>
                    </div>
                    <div>
                        <InputLabel value="Sampling method" />
                        <TextInput value={submitForm.data.sampling_method} onChange={(e) => submitForm.setData('sampling_method', e.target.value)} placeholder="e.g. Random sampling" />
                    </div>
                    <div>
                        <InputLabel value="Items selected" />
                        <TextArea rows={2} value={submitForm.data.sample_items} onChange={(e) => submitForm.setData('sample_items', e.target.value)} placeholder="References of the sampled items" />
                    </div>
                    <InputError message={submitForm.errors.results} />
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={() => setShowSubmit(false)}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={submitForm.processing}>Submit</PrimaryButton>
                    </div>
                </form>
            </Modal>

            {/* Return-to-tester modal */}
            <Modal show={showReturn} onClose={() => setShowReturn(false)} title="Return to tester" maxWidth="md">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.post(
                            route('test-instances.review', instance.id),
                            { approved: false, notes: returnForm.data.notes },
                            { onSuccess: () => setShowReturn(false) },
                        );
                    }}
                    className="space-y-4"
                >
                    <InputLabel value="Review notes" required />
                    <TextArea value={returnForm.data.notes} onChange={(e) => returnForm.setData('notes', e.target.value)} />
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={() => setShowReturn(false)}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={!returnForm.data.notes.trim()}>Return</PrimaryButton>
                    </div>
                </form>
            </Modal>

            {/* Reopen modal */}
            <Modal show={showReopen} onClose={() => setShowReopen(false)} title="Reopen locked test" maxWidth="md">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        reopenForm.post(route('test-instances.reopen', instance.id), { onSuccess: () => setShowReopen(false) });
                    }}
                    className="space-y-4"
                >
                    <p className="text-sm text-gray-500">Reopening is formal — the reason is recorded on the audit trail.</p>
                    <TextArea value={reopenForm.data.reason} onChange={(e) => reopenForm.setData('reason', e.target.value)} placeholder="Reason for reopening" />
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={() => setShowReopen(false)}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={!reopenForm.data.reason.trim() || reopenForm.processing}>Reopen</PrimaryButton>
                    </div>
                </form>
            </Modal>

            {/* Rating modal (FR-7.1—7.5) */}
            <Modal show={showRate} onClose={() => setShowRate(false)} title="Rate control effectiveness" maxWidth="lg">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        rateForm.post(route('test-instances.rate', instance.id), { onSuccess: () => setShowRate(false) });
                    }}
                    className="space-y-4"
                >
                    {['design', 'operating'].map((dimension) => (
                        <div key={dimension} className="rounded-lg border border-gray-100 p-4">
                            <InputLabel
                                value={dimension === 'design' ? 'Design effectiveness — is the control capable, as designed?' : 'Operating effectiveness — did it operate as designed all period?'}
                                required
                            />
                            <SelectInput
                                className="mt-1"
                                value={rateForm.data[`${dimension}_effectiveness`]}
                                onChange={(e) => rateForm.setData(`${dimension}_effectiveness`, e.target.value)}
                            >
                                {['Effective', 'Partially Effective', 'Ineffective', 'Not Tested'].map((s) => (
                                    <option key={s} value={s}>{s}</option>
                                ))}
                            </SelectInput>
                            {rateForm.data[`${dimension}_effectiveness`] !== 'Effective' && (
                                <div className="mt-2">
                                    <InputLabel value="Rationale" required />
                                    <TextArea
                                        rows={2}
                                        value={rateForm.data[`${dimension}_rationale`]}
                                        onChange={(e) => rateForm.setData(`${dimension}_rationale`, e.target.value)}
                                    />
                                    <InputError message={rateForm.errors[`${dimension}_rationale`]} className="mt-1" />
                                </div>
                            )}
                        </div>
                    ))}
                    <p className="text-xs text-gray-500">
                        The overall rating derives from the configurable matrix — a design-ineffective control cannot rate better than its design.
                    </p>
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={() => setShowRate(false)}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={rateForm.processing}>Record rating</PrimaryButton>
                    </div>
                </form>
            </Modal>

            {/* Audit trail (Phase 7.5) */}
            <div className="card mt-6">
                <div className="card-header"><h3 className="text-sm font-semibold">Audit trail</h3></div>
                <div className="card-body">
                    <ActivityTab alias="test-instance" entityId={instance.id} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
