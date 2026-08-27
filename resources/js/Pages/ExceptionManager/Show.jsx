import EvidencePanel from '@/Components/EvidencePanel';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import RichTextEditor from '@/Components/RichTextEditor';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import SeverityBadge from '@/Components/SeverityBadge';
import StatusBadge from '@/Components/StatusBadge';
import RichTextViewer from '@/Components/RichTextViewer';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate, formatDateTime } from '@/utils';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const POSITION_COLORS = {
    Accepted: 'badge-status-active',
    'Partially Accepted': 'badge-status-pending',
    Disputed: 'badge-status-overdue',
    'Already Remediated': 'badge-status-completed',
    'More Information Required': 'badge-status-draft',
};

function Round({ escalation, response }) {
    return (
        <div className="rounded-lg border border-gray-200">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 bg-gray-50 px-4 py-2.5">
                <p className="text-sm font-semibold">
                    Round {response.round_no}
                    <span className={`ms-2 badge ${POSITION_COLORS[response.position] ?? 'badge-status-draft'}`}>{response.position}</span>
                    {response.is_late && <span className="ms-1.5 badge badge-status-overdue">Late</span>}
                    {response.channel === 'secure_link' && <span className="ms-1.5 badge badge-status-draft">Secure link</span>}
                </p>
                <p className="text-xs text-gray-400">
                    {response.responder?.name ?? response.responder_name} · {formatDateTime(response.submitted_at)}
                </p>
            </div>
            <div className="space-y-3 px-4 py-3 text-sm">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Management comment</p>
                    <RichTextViewer className="mt-0.5" value={response.management_comment_rich} fallback={response.management_comment} />
                </div>
                {response.root_cause && (
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                            Root cause {response.root_cause_category && <span className="normal-case">({response.root_cause_category})</span>}
                        </p>
                        <RichTextViewer className="mt-0.5" value={response.root_cause_rich} fallback={response.root_cause} />
                    </div>
                )}
                {response.agreed_action_plan && (
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                            Agreed action plan {response.proposed_target_date && <span className="normal-case">— target {formatDate(response.proposed_target_date)}</span>}
                        </p>
                        <RichTextViewer className="mt-0.5" value={response.agreed_action_plan_rich} fallback={response.agreed_action_plan} />
                    </div>
                )}
                {response.review_status !== 'Pending Review' && (
                    <div
                        className={`rounded-lg border p-3 ${
                            response.review_status === 'Accepted' ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50'
                        }`}
                    >
                        <p className={`text-xs font-semibold uppercase ${response.review_status === 'Accepted' ? 'text-green-800' : 'text-red-800'}`}>
                            {response.review_status} by {response.reviewer?.name} · {formatDateTime(response.reviewed_at)}
                        </p>
                        {(response.review_note || response.rejection_reason) && (
                            <p className={`mt-1 text-sm ${response.review_status === 'Accepted' ? 'text-green-900' : 'text-red-900'}`}>
                                {response.review_note ?? response.rejection_reason}
                            </p>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}

export default function Show({ escalation, evidence = [], can = {} }) {
    const { secureLinkUrl } = usePage().props;
    const [modal, setModal] = useState(null);
    const close = () => setModal(null);

    const pendingResponse = (escalation.responses ?? []).find((r) => r.review_status === 'Pending Review' && r.submitted_at);

    const reviewForm = useForm({
        decision: 'accept',
        review_note: '',
        rejection_reason: '',
        action_title: '',
        action_type: 'corrective',
        action_target_date: '',
    });
    const closeForm = useForm({ validation_status: 'Effective', closure_note: '' , closure_note_rich: null});
    const withdrawForm = useForm({ reason: '' });
    const linkForm = useForm({ expires_at: '' });
    const progressForms = useForm({ progress_percentage: '', progress_note: '' });
    const completeForm = useForm({ note: '' });
    const [actionId, setActionId] = useState(null);

    const submitTo = (form, routeName, params, extra = {}) => (e) => {
        e.preventDefault();
        form.post(route(routeName, params), { preserveScroll: true, onSuccess: () => { form.reset(); close(); }, ...extra });
    };

    return (
        <AuthenticatedLayout header={escalation.reference}>
            <Head title={escalation.reference} />

            <PageHeader
                title={
                    <span className="flex flex-wrap items-center gap-2">
                        Escalation to {escalation.unit?.name ?? escalation.process?.name ?? escalation.external_organisation ?? escalation.respondent?.name}
                        <SeverityBadge severity={escalation.severity} />
                        <StatusBadge status={escalation.status} />
                        {escalation.round_no > 1 && <span className="badge badge-high">Round {escalation.round_no}</span>}
                        {escalation.is_response_late && <span className="badge badge-status-overdue">Overdue</span>}
                    </span>
                }
                subtitle={
                    <span>
                        <span className="font-mono">{escalation.reference}</span> · issued by {escalation.issuer?.name} on {formatDate(escalation.issued_at)} · exception{' '}
                        <Link href={route('exceptions.show', escalation.exception?.id)} className="font-mono text-[var(--color-primary)] hover:underline">
                            {escalation.exception?.reference}
                        </Link>
                    </span>
                }
                breadcrumbs={[
                    { label: 'Exception Manager', href: route('exception-manager.index') },
                    { label: escalation.reference },
                ]}
                actions={
                    <>
                        {can.respond && (
                            <Link href={route('exception-manager.respond', escalation.id)} className="btn-primary">
                                Respond
                            </Link>
                        )}
                        {(can.review || can.accept) && pendingResponse && (
                            <PrimaryButton onClick={() => setModal('review')}>Review response</PrimaryButton>
                        )}
                        {can.close && <PrimaryButton onClick={() => setModal('close')}>Validate &amp; close</PrimaryButton>}
                        {can.manageLinks && escalation.target_type === 'external_party' && (
                            <SecondaryButton onClick={() => setModal('link')}>Secure link</SecondaryButton>
                        )}
                        {can.withdraw && <SecondaryButton onClick={() => setModal('withdraw')}>Withdraw</SecondaryButton>}
                    </>
                }
            />

            {secureLinkUrl && (
                <div className="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4">
                    <p className="text-sm font-semibold text-amber-900">One-time secure link — copy it now. It is not stored and cannot be shown again.</p>
                    <p className="mt-1 break-all font-mono text-xs text-amber-800">{secureLinkUrl}</p>
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <div className="card">
                        <div className="card-header"><h3 className="text-sm font-semibold">What the control function is asking for</h3></div>
                        <div className="card-body space-y-3 text-sm">
                            <p className="whitespace-pre-line">{escalation.issue_note}</p>
                            <p className="text-xs text-gray-400">
                                Required: {String(escalation.required_response).replace('_', ' ')} · respond by{' '}
                                <span className={escalation.is_response_late ? 'font-semibold text-[var(--color-error)]' : ''}>
                                    {formatDateTime(escalation.response_due_at)}
                                </span>
                            </p>
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header"><h3 className="text-sm font-semibold">Responses — round by round</h3></div>
                        <div className="card-body space-y-4">
                            {(escalation.responses ?? []).filter((r) => r.submitted_at).length === 0 && (
                                <p className="text-sm text-gray-400">No response yet.</p>
                            )}
                            {(escalation.responses ?? [])
                                .filter((r) => r.submitted_at)
                                .map((response) => (
                                    <Round key={response.id} escalation={escalation} response={response} />
                                ))}
                        </div>
                    </div>

                    {(escalation.actions ?? []).length > 0 && (
                        <div className="card">
                            <div className="card-header"><h3 className="text-sm font-semibold">Committed actions</h3></div>
                            <div className="card-body">
                                <ul className="divide-y divide-gray-100">
                                    {escalation.actions.map((action) => (
                                        <li key={action.id} className="py-3">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <p className="text-sm font-medium">
                                                    <span className="font-mono text-xs text-[var(--color-primary)]">{action.reference}</span> {action.title}
                                                </p>
                                                <span className="flex items-center gap-1.5">
                                                    <StatusBadge status={action.status} />
                                                    {action.validation_status && (
                                                        <span className={`badge ${action.validation_status === 'Effective' ? 'badge-status-active' : 'badge-status-overdue'}`}>
                                                            {action.validation_status}
                                                        </span>
                                                    )}
                                                </span>
                                            </div>
                                            <p className="mt-0.5 text-xs text-gray-400">
                                                {action.owner?.name} · target {formatDate(action.target_date)} · {Number(action.progress_percentage)}%
                                                {action.progress_note && ` — ${action.progress_note}`}
                                            </p>
                                            {can.progressActions && !['Completed', 'Cancelled'].includes(action.status) && (
                                                <div className="mt-2 flex gap-2">
                                                    <SecondaryButton onClick={() => { setActionId(action.id); setModal('progress'); }}>
                                                        Record progress
                                                    </SecondaryButton>
                                                    <PrimaryButton onClick={() => { setActionId(action.id); setModal('complete'); }}>
                                                        Mark completed
                                                    </PrimaryButton>
                                                </div>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </div>
                    )}

                    <EvidencePanel
                        linkedType="exception-escalation"
                        linkedId={escalation.id}
                        evidence={evidence}
                        canUpload={can.respond || can.review || can.close}
                    />
                </div>

                <div className="space-y-6">
                    <div className="card">
                        <div className="card-body space-y-2 text-sm">
                            <p><span className="text-gray-500">Respondent:</span> {escalation.respondent?.name ?? escalation.external_name ?? '—'}</p>
                            {escalation.external_email && <p><span className="text-gray-500">External email:</span> {escalation.external_email}</p>}
                            <p><span className="text-gray-500">Issued:</span> {formatDateTime(escalation.issued_at)}</p>
                            <p>
                                <span className="text-gray-500">Acknowledged:</span>{' '}
                                {escalation.acknowledged_at ? (
                                    <>
                                        {formatDateTime(escalation.acknowledged_at)}
                                        {escalation.is_acknowledgement_late && <span className="ms-1 badge badge-status-overdue">Late</span>}
                                    </>
                                ) : 'Not yet'}
                            </p>
                            <p><span className="text-gray-500">Acknowledge by:</span> {formatDateTime(escalation.acknowledge_due_at)}</p>
                            <p><span className="text-gray-500">Respond by:</span> {formatDateTime(escalation.response_due_at)}</p>
                            <p><span className="text-gray-500">SLA policy:</span> {escalation.sla_policy?.name ?? '—'}</p>
                            <p><span className="text-gray-500">Reminders sent:</span> {escalation.reminder_count}</p>
                            {escalation.matrix_escalated_at && (
                                <p className="text-[var(--color-error)]">
                                    <span className="text-gray-500">Escalated to matrix:</span> {formatDateTime(escalation.matrix_escalated_at)}
                                </p>
                            )}
                            {escalation.status === 'Withdrawn' && (
                                <div className="rounded-lg border border-gray-200 bg-gray-50 p-3">
                                    <p className="text-xs font-semibold uppercase text-gray-600">Withdrawn</p>
                                    <p className="mt-1">{escalation.withdrawal_reason}</p>
                                    <p className="mt-1 text-xs text-gray-400">{escalation.withdrawer?.name} · {formatDateTime(escalation.withdrawn_at)}</p>
                                </div>
                            )}
                            {escalation.status === 'Closed' && (
                                <div className="rounded-lg border border-green-200 bg-green-50 p-3">
                                    <p className="text-xs font-semibold uppercase text-green-800">Closed</p>
                                    {escalation.closure_note && <p className="mt-1 text-green-900">{escalation.closure_note}</p>}
                                    <p className="mt-1 text-xs text-green-800">{escalation.closer?.name} · {formatDateTime(escalation.closed_at)}</p>
                                </div>
                            )}
                        </div>
                    </div>

                    {(escalation.response_links ?? []).length > 0 && can.manageLinks && (
                        <div className="card">
                            <div className="card-header"><h3 className="text-sm font-semibold">Secure links</h3></div>
                            <div className="card-body space-y-2 text-sm">
                                {escalation.response_links.map((link) => (
                                    <div key={link.id} className="flex items-center justify-between gap-2">
                                        <span className="font-mono text-xs">{link.token_prefix}… · {link.access_count} access(es)</span>
                                        <span className="flex items-center gap-1.5">
                                            <StatusBadge status={link.status} />
                                            {link.status === 'active' && (
                                                <Link
                                                    href={route('exception-manager.links.revoke', { escalation: escalation.id, link: link.id })}
                                                    method="post"
                                                    as="button"
                                                    className="text-xs text-[var(--color-error)] hover:underline"
                                                >
                                                    Revoke
                                                </Link>
                                            )}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* ── Review modal (CR1.4) ─────────────────────────────── */}
            <Modal show={modal === 'review'} onClose={close} title="Review the departmental response" maxWidth="lg">
                {pendingResponse && (
                    <form
                        onSubmit={submitTo(reviewForm, 'exception-manager.review', { escalation: escalation.id, response: pendingResponse.id })}
                        className="space-y-4"
                    >
                        <div>
                            <InputLabel value="Decision" required />
                            <SelectInput value={reviewForm.data.decision} onChange={(e) => reviewForm.setData('decision', e.target.value)}>
                                {can.accept && <option value="accept">Accept — the response stands</option>}
                                <option value="reject">Reject — re-issue for a new round</option>
                            </SelectInput>
                            {!can.accept && (
                                <p className="mt-1 text-xs text-gray-400">
                                    Accepting a response is a closure act and needs closure authority — you can reject only.
                                </p>
                            )}
                            <InputError message={reviewForm.errors.review} className="mt-1" />
                        </div>
                        {reviewForm.data.decision === 'accept' ? (
                            <>
                                <div>
                                    <InputLabel value="Review note" />
                                    <TextArea value={reviewForm.data.review_note} onChange={(e) => reviewForm.setData('review_note', e.target.value)} />
                                </div>
                                {pendingResponse.agreed_action_plan && !pendingResponse.proposed_target_date && (
                                    <div>
                                        <InputLabel value="Target date for the committed action" required />
                                        <TextInput
                                            type="date"
                                            value={reviewForm.data.action_target_date}
                                            onChange={(e) => reviewForm.setData('action_target_date', e.target.value)}
                                        />
                                        <InputError message={reviewForm.errors.action_target_date} className="mt-1" />
                                    </div>
                                )}
                            </>
                        ) : (
                            <div>
                                <InputLabel value="Why is the response rejected?" required />
                                <TextArea
                                    value={reviewForm.data.rejection_reason}
                                    onChange={(e) => reviewForm.setData('rejection_reason', e.target.value)}
                                    placeholder="The department will see this verbatim on the re-issued round."
                                />
                                <InputError message={reviewForm.errors.rejection_reason} className="mt-1" />
                            </div>
                        )}
                        <div className="flex justify-end gap-2">
                            <SecondaryButton onClick={close}>Cancel</SecondaryButton>
                            <PrimaryButton disabled={reviewForm.processing || (reviewForm.data.decision === 'reject' && !reviewForm.data.rejection_reason.trim())}>
                                {reviewForm.data.decision === 'accept' ? 'Accept response' : 'Reject & re-issue'}
                            </PrimaryButton>
                        </div>
                    </form>
                )}
            </Modal>

            {/* ── Close modal (CR1.5) ──────────────────────────────── */}
            <Modal show={modal === 'close'} onClose={close} title="Validate the remediation and close" maxWidth="md">
                <p className="mb-4 text-sm text-gray-500">
                    Closure is recorded against evidence. Partially Effective or Ineffective re-issues the escalation rather than closing it.
                </p>
                <form onSubmit={submitTo(closeForm, 'exception-manager.close', escalation.id)} className="space-y-4">
                    <div>
                        <InputLabel value="Validation" required />
                        <SelectInput value={closeForm.data.validation_status} onChange={(e) => closeForm.setData('validation_status', e.target.value)}>
                            <option value="Effective">Effective — close the escalation</option>
                            <option value="Partially Effective">Partially Effective — re-issue</option>
                            <option value="Ineffective">Ineffective — re-issue</option>
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Validation / closure note" />
                        <RichTextEditor
                            value={closeForm.data.closure_note_rich ?? closeForm.data.closure_note}
                            onChange={(doc, plain) => closeForm.setData((d) => ({ ...d, closure_note: plain, closure_note_rich: doc }))}
                            tools="minimal"
                            minHeight={90}
                        />
                        <InputError message={closeForm.errors.closure} className="mt-1" />
                    </div>
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={close}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={closeForm.processing}>Record validation</PrimaryButton>
                    </div>
                </form>
            </Modal>

            {/* ── Withdraw modal ───────────────────────────────────── */}
            <Modal show={modal === 'withdraw'} onClose={close} title="Withdraw this escalation" maxWidth="md">
                <p className="mb-4 text-sm text-gray-500">Withdrawal ends an escalation issued in error. It is audited and the department is notified.</p>
                <form onSubmit={submitTo(withdrawForm, 'exception-manager.withdraw', escalation.id)} className="space-y-4">
                    <div>
                        <InputLabel value="Reason" required />
                        <TextArea value={withdrawForm.data.reason} onChange={(e) => withdrawForm.setData('reason', e.target.value)} />
                        <InputError message={withdrawForm.errors.reason} className="mt-1" />
                    </div>
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={close}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={!withdrawForm.data.reason.trim() || withdrawForm.processing}>Withdraw</PrimaryButton>
                    </div>
                </form>
            </Modal>

            {/* ── Secure link modal (R-H) ──────────────────────────── */}
            <Modal show={modal === 'link'} onClose={close} title="Generate a secure answer link" maxWidth="md">
                <p className="mb-4 text-sm text-gray-500">
                    The link grants sight of this one escalation and the right to answer it — nothing else. The URL is shown once and never stored.
                </p>
                <form onSubmit={submitTo(linkForm, 'exception-manager.links.store', escalation.id)} className="space-y-4">
                    <div>
                        <InputLabel value="Expires" />
                        <TextInput type="datetime-local" value={linkForm.data.expires_at} onChange={(e) => linkForm.setData('expires_at', e.target.value)} />
                        <p className="mt-1 text-xs text-gray-400">Leave empty to expire with the response deadline.</p>
                    </div>
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={close}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={linkForm.processing}>Generate link</PrimaryButton>
                    </div>
                </form>
            </Modal>

            {/* ── Action progress / completion (CR1.5) ─────────────── */}
            <Modal show={modal === 'progress'} onClose={close} title="Record progress" maxWidth="md">
                <form onSubmit={submitTo(progressForms, 'exception-actions.progress', actionId)} className="space-y-4">
                    <div>
                        <InputLabel value="Progress %" required />
                        <TextInput
                            type="number" min="0" max="100"
                            value={progressForms.data.progress_percentage}
                            onChange={(e) => progressForms.setData('progress_percentage', e.target.value)}
                        />
                    </div>
                    <div>
                        <InputLabel value="Note" />
                        <TextArea value={progressForms.data.progress_note} onChange={(e) => progressForms.setData('progress_note', e.target.value)} />
                    </div>
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={close}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={progressForms.processing}>Save</PrimaryButton>
                    </div>
                </form>
            </Modal>

            <Modal show={modal === 'complete'} onClose={close} title="Mark action completed" maxWidth="md">
                <p className="mb-4 text-sm text-gray-500">Completion is the department's statement; the control function still validates against evidence.</p>
                <form onSubmit={submitTo(completeForm, 'exception-actions.complete', actionId)} className="space-y-4">
                    <div>
                        <InputLabel value="What was done?" />
                        <TextArea value={completeForm.data.note} onChange={(e) => completeForm.setData('note', e.target.value)} />
                    </div>
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={close}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={completeForm.processing}>Mark completed</PrimaryButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
