import ActivityTab from '@/Components/ActivityTab';
import EvidencePanel from '@/Components/EvidencePanel';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import SeverityBadge from '@/Components/SeverityBadge';
import StatusBadge from '@/Components/StatusBadge';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate, formatDateTime } from '@/utils';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

const ACTIVITY_ICONS = {
    Comment: '💬',
    'Status Change': '🔄',
    Assignment: '👤',
    'Extension Request': '📅',
    'Extension Decision': '⚖️',
    'Evidence Upload': '📎',
    Escalation: '🚨',
    Verification: '✅',
};

function ActionModal({ show, onClose, title, description, children }) {
    return (
        <Modal show={show} onClose={onClose} title={title} maxWidth="md">
            {description && <p className="mb-4 text-sm text-gray-500">{description}</p>}
            {children}
        </Modal>
    );
}

export default function Show({ exception, evidence = [], users = [], can = {} }) {
    const [modal, setModal] = useState(null);
    const close = () => setModal(null);

    const commentForm = useForm({ note: '' });
    const assignForm = useForm({ user_id: '' });
    const remediateForm = useForm({ note: '' });
    const closeForm = useForm({ verification_method: '', closure_notes: '' });
    const failForm = useForm({ reason: '' });
    const extensionForm = useForm({ new_date: '', reason: '' });
    const decideForm = useForm({ approved: true, new_date: '', reason: '' });
    const riskForm = useForm({ reason: '', expiry_date: '' });
    const compForm = useForm({
        description: '',
        owner_id: '',
        is_temporary: true,
        effective_from: '',
        effective_to: '',
        residual_exposure_note: '',
    });

    const submitTo = (form, routeName, extra = {}) => (e) => {
        e.preventDefault();
        form.post(route(routeName, exception.id), { preserveScroll: true, onSuccess: () => { form.reset(); close(); }, ...extra });
    };

    return (
        <AuthenticatedLayout header={exception.reference}>
            <Head title={exception.reference} />

            <PageHeader
                title={
                    <span className="flex flex-wrap items-center gap-2">
                        {exception.title}
                        <SeverityBadge severity={exception.severity} />
                        <StatusBadge status={exception.status} />
                        {exception.is_overdue && <span className="badge badge-status-overdue">Overdue</span>}
                        {exception.is_recurring && <span className="badge badge-high">Recurring</span>}
                    </span>
                }
                subtitle={
                    <span>
                        <span className="font-mono">{exception.reference}</span> · {exception.source_type} · raised {formatDate(exception.date_raised)} · age {exception.age_days}d
                    </span>
                }
                breadcrumbs={[{ label: 'Exceptions', href: route('exceptions.index') }, { label: exception.reference }]}
                actions={
                    <>
                        {can.update && ['Open', 'Assigned'].includes(exception.status) && (
                            <SecondaryButton onClick={() => setModal('assign')}>Assign</SecondaryButton>
                        )}
                        {can.remediate && ['Assigned', 'In Progress'].includes(exception.status) && (
                            <PrimaryButton onClick={() => setModal('remediate')}>Mark remediated</PrimaryButton>
                        )}
                        {can.close && (
                            <>
                                <PrimaryButton onClick={() => setModal('close')}>Verify &amp; close</PrimaryButton>
                                <SecondaryButton onClick={() => setModal('fail')}>Fail verification</SecondaryButton>
                            </>
                        )}
                        {can.requestExtension && (
                            <SecondaryButton onClick={() => setModal('extension')}>Request extension</SecondaryButton>
                        )}
                        {can.decideExtension && exception.status !== 'Verified-Closed' && (
                            <SecondaryButton onClick={() => setModal('decide')}>Decide extension</SecondaryButton>
                        )}
                        {can.acceptRisk && <SecondaryButton onClick={() => setModal('risk')}>Accept risk</SecondaryButton>}
                    </>
                }
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <div className="card">
                        <div className="card-header"><h3 className="text-sm font-semibold">Details</h3></div>
                        <div className="card-body space-y-4 text-sm">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Description</p>
                                <p className="mt-1">{exception.description || '—'}</p>
                            </div>
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Root cause</p>
                                <p className="mt-1">{exception.root_cause || '—'}</p>
                            </div>
                            {exception.remediation_plan && (
                                <div>
                                    <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Remediation plan</p>
                                    <p className="mt-1">{exception.remediation_plan}</p>
                                </div>
                            )}
                            {exception.status === 'Verified-Closed' && (
                                <div className="rounded-lg border border-green-200 bg-green-50 p-3">
                                    <p className="text-xs font-semibold uppercase text-green-800">Verified closure</p>
                                    <p className="mt-1 text-green-900">
                                        {exception.verification_method} — verified by {exception.verifier?.name} on {formatDateTime(exception.verified_at)}
                                    </p>
                                    {exception.closure_notes && <p className="mt-1 text-xs text-green-800">{exception.closure_notes}</p>}
                                </div>
                            )}
                            {exception.status === 'Risk Accepted' && (
                                <div className="rounded-lg border border-amber-200 bg-amber-50 p-3">
                                    <p className="text-xs font-semibold uppercase text-amber-800">Risk accepted</p>
                                    <p className="mt-1 text-amber-900">{exception.risk_acceptance_reason}</p>
                                    <p className="mt-1 text-xs text-amber-800">
                                        Expires {formatDate(exception.risk_acceptance_expiry)} — re-confirmation required at expiry.
                                    </p>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Compensating controls (FR-6) */}
                    <div className="card">
                        <div className="card-header">
                            <h3 className="text-sm font-semibold">Compensating controls</h3>
                            {['Open', 'Assigned', 'In Progress', 'Remediated'].includes(exception.status) && (
                                <button type="button" className="text-sm text-[var(--color-primary)] hover:underline" onClick={() => setModal('compensating')}>
                                    + Register
                                </button>
                            )}
                        </div>
                        <div className="card-body">
                            {exception.compensating_controls?.length ? (
                                <ul className="divide-y divide-gray-100">
                                    {exception.compensating_controls.map((comp) => (
                                        <li key={comp.id} className="py-2.5">
                                            <div className="flex items-center justify-between gap-3">
                                                <p className="text-sm">{comp.description}</p>
                                                <StatusBadge status={comp.status} />
                                            </div>
                                            <p className="mt-0.5 text-xs text-gray-400">
                                                {comp.is_temporary ? 'Temporary' : 'Permanent'} · {comp.owner?.name ?? '—'}
                                                {comp.effective_to && ` · until ${formatDate(comp.effective_to)}`}
                                            </p>
                                            {comp.residual_exposure_note && (
                                                <p className="mt-0.5 text-xs text-[var(--color-warning)]">Not covered: {comp.residual_exposure_note}</p>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-sm text-gray-400">None registered.</p>
                            )}
                        </div>
                    </div>

                    <EvidencePanel
                        linkedType="exception"
                        linkedId={exception.id}
                        evidence={evidence}
                        canUpload={can.remediate || can.update || can.close}
                    />

                    {/* Activity timeline (FR-5.10) */}
                    <div className="card">
                        <div className="card-header"><h3 className="text-sm font-semibold">Activity</h3></div>
                        <div className="card-body">
                            {can.comment && (
                                <form onSubmit={submitTo(commentForm, 'exceptions.comments')} className="mb-4 flex gap-2">
                                    <TextInput
                                        className="flex-1"
                                        placeholder="Add a comment…"
                                        value={commentForm.data.note}
                                        onChange={(e) => commentForm.setData('note', e.target.value)}
                                    />
                                    <PrimaryButton disabled={!commentForm.data.note.trim() || commentForm.processing}>Post</PrimaryButton>
                                </form>
                            )}
                            <ol className="relative space-y-4 border-s border-gray-200 ps-5">
                                {(exception.activities ?? []).map((activity) => (
                                    <li key={activity.id} className="relative">
                                        <span className="absolute -start-[27px] top-0 flex h-5 w-5 items-center justify-center rounded-full bg-gray-100 text-[10px]">
                                            {ACTIVITY_ICONS[activity.activity_type] ?? '•'}
                                        </span>
                                        <p className="text-sm text-[var(--color-text-primary)]">
                                            <span className="font-medium">{activity.actor?.name ?? 'System'}</span>
                                            <span className="text-gray-500"> · {activity.activity_type}</span>
                                            {activity.from_value && activity.to_value && (
                                                <span className="text-gray-500"> — {activity.from_value} → {activity.to_value}</span>
                                            )}
                                            {!activity.from_value && activity.to_value && (
                                                <span className="text-gray-500"> — {activity.to_value}</span>
                                            )}
                                        </p>
                                        {activity.note && <p className="mt-0.5 text-sm text-gray-600">{activity.note}</p>}
                                        <p className="mt-0.5 text-xs text-gray-400">{formatDateTime(activity.created_at)}</p>
                                    </li>
                                ))}
                            </ol>
                        </div>
                    </div>

                    {/* Audit trail (Phase 7.5) */}
                    <div className="card">
                        <div className="card-header"><h3 className="text-sm font-semibold">Audit trail</h3></div>
                        <div className="card-body">
                            <ActivityTab alias="exception" entityId={exception.id} />
                        </div>
                    </div>
                </div>

                <div className="space-y-6">
                    <div className="card">
                        <div className="card-body space-y-2 text-sm">
                            {exception.control && (
                                <p>
                                    <span className="text-gray-500">Control:</span>{' '}
                                    <Link href={route('controls.show', exception.control.id)} className="font-mono text-xs text-[var(--color-primary)] hover:underline">
                                        {exception.control.control_ref}
                                    </Link>
                                </p>
                            )}
                            {exception.risk && (
                                <p>
                                    <span className="text-gray-500">Risk:</span>{' '}
                                    <Link href={route('risks.show', exception.risk.id)} className="font-mono text-xs text-[var(--color-primary)] hover:underline">
                                        {exception.risk.code}
                                    </Link>
                                </p>
                            )}
                            <p><span className="text-gray-500">Owner:</span> {exception.owner?.name ?? 'Unassigned'}</p>
                            <p><span className="text-gray-500">Responsible:</span> {exception.responsible_party?.name ?? '—'}</p>
                            <p><span className="text-gray-500">Unit:</span> {exception.unit?.name ?? '—'}</p>
                            <p><span className="text-gray-500">Raised by:</span> {exception.raised_by?.name ?? '—'}</p>
                            <p><span className="text-gray-500">Target closure:</span> {formatDate(exception.target_closure_date)}</p>
                            {exception.extension_count > 0 && (
                                <p><span className="text-gray-500">Extensions:</span> {exception.extension_count}</p>
                            )}
                            {exception.recurrence_of && (
                                <p>
                                    <span className="text-gray-500">Recurrence of:</span>{' '}
                                    <Link href={route('exceptions.show', exception.recurrence_of.id)} className="font-mono text-xs text-[var(--color-primary)] hover:underline">
                                        {exception.recurrence_of.reference}
                                    </Link>
                                </p>
                            )}
                        </div>
                    </div>

                    {exception.escalation_events?.length > 0 && (
                        <div className="card">
                            <div className="card-header"><h3 className="text-sm font-semibold">Escalations</h3></div>
                            <div className="card-body space-y-2">
                                {exception.escalation_events.map((event) => (
                                    <div key={event.id} className="flex items-center justify-between text-sm">
                                        <span className="text-gray-600">Tier {event.tier_no} → {event.recipient?.name ?? '—'}</span>
                                        <span className={`badge ${event.delivery_status === 'Sent' ? 'badge-status-active' : event.delivery_status === 'Failed' ? 'badge-status-overdue' : 'badge-status-pending'}`}>
                                            {event.delivery_status}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* ── Action modals ─────────────────────────────────────── */}
            <ActionModal show={modal === 'assign'} onClose={close} title="Assign responsibility">
                <form onSubmit={submitTo(assignForm, 'exceptions.assign')} className="space-y-4">
                    <SelectInput value={assignForm.data.user_id} onChange={(e) => assignForm.setData('user_id', e.target.value)}>
                        <option value="">— Select user —</option>
                        {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                    </SelectInput>
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={close}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={!assignForm.data.user_id}>Assign</PrimaryButton>
                    </div>
                </form>
            </ActionModal>

            <ActionModal
                show={modal === 'remediate'}
                onClose={close}
                title="Mark remediated"
                description="Remediation is the furthest an owner can take an exception — only the control function can verify and close it."
            >
                <form onSubmit={submitTo(remediateForm, 'exceptions.remediated')} className="space-y-4">
                    <InputLabel value="What was done?" required />
                    <TextArea value={remediateForm.data.note} onChange={(e) => remediateForm.setData('note', e.target.value)} />
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={close}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={!remediateForm.data.note.trim()}>Mark remediated</PrimaryButton>
                    </div>
                </form>
            </ActionModal>

            <ActionModal
                show={modal === 'close'}
                onClose={close}
                title="Verify and close"
                description="Closure requires verification of remediation evidence. Record how you verified it."
            >
                <form onSubmit={submitTo(closeForm, 'exceptions.close')} className="space-y-4">
                    <div>
                        <InputLabel value="Verification method" required />
                        <TextInput
                            value={closeForm.data.verification_method}
                            onChange={(e) => closeForm.setData('verification_method', e.target.value)}
                            placeholder="e.g. Re-performed the control on the current period's sample"
                        />
                    </div>
                    <div>
                        <InputLabel value="Closure notes" />
                        <TextArea value={closeForm.data.closure_notes} onChange={(e) => closeForm.setData('closure_notes', e.target.value)} />
                    </div>
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={close}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={!closeForm.data.verification_method.trim()}>Verify &amp; close</PrimaryButton>
                    </div>
                </form>
            </ActionModal>

            <ActionModal show={modal === 'fail'} onClose={close} title="Fail verification" description="The exception returns to In Progress and escalation resumes.">
                <form onSubmit={submitTo(failForm, 'exceptions.fail-verification')} className="space-y-4">
                    <TextArea value={failForm.data.reason} onChange={(e) => failForm.setData('reason', e.target.value)} placeholder="Why did verification fail?" />
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={close}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={!failForm.data.reason.trim()}>Fail verification</PrimaryButton>
                    </div>
                </form>
            </ActionModal>

            <ActionModal show={modal === 'extension'} onClose={close} title="Request due-date extension">
                <form onSubmit={submitTo(extensionForm, 'exceptions.request-extension')} className="space-y-4">
                    <div>
                        <InputLabel value="Proposed new date" required />
                        <TextInput type="date" value={extensionForm.data.new_date} onChange={(e) => extensionForm.setData('new_date', e.target.value)} />
                    </div>
                    <div>
                        <InputLabel value="Reason" required />
                        <TextArea value={extensionForm.data.reason} onChange={(e) => extensionForm.setData('reason', e.target.value)} />
                    </div>
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={close}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={!extensionForm.data.new_date || !extensionForm.data.reason.trim()}>Request</PrimaryButton>
                    </div>
                </form>
            </ActionModal>

            <ActionModal show={modal === 'decide'} onClose={close} title="Decide extension request">
                <form onSubmit={submitTo(decideForm, 'exceptions.decide-extension')} className="space-y-4">
                    <div>
                        <InputLabel value="Decision" required />
                        <SelectInput value={decideForm.data.approved ? '1' : '0'} onChange={(e) => decideForm.setData('approved', e.target.value === '1')}>
                            <option value="1">Approve</option>
                            <option value="0">Decline</option>
                        </SelectInput>
                    </div>
                    {decideForm.data.approved && (
                        <div>
                            <InputLabel value="New target date" required />
                            <TextInput type="date" value={decideForm.data.new_date} onChange={(e) => decideForm.setData('new_date', e.target.value)} />
                        </div>
                    )}
                    <div>
                        <InputLabel value="Reason" required />
                        <TextArea value={decideForm.data.reason} onChange={(e) => decideForm.setData('reason', e.target.value)} />
                    </div>
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={close}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={!decideForm.data.reason.trim()}>Record decision</PrimaryButton>
                    </div>
                </form>
            </ActionModal>

            <ActionModal
                show={modal === 'risk'}
                onClose={close}
                title="Accept risk"
                description="Formal risk acceptance requires an expiry date and is re-confirmed when it lapses."
            >
                <form onSubmit={submitTo(riskForm, 'exceptions.accept-risk')} className="space-y-4">
                    <div>
                        <InputLabel value="Justification" required />
                        <TextArea value={riskForm.data.reason} onChange={(e) => riskForm.setData('reason', e.target.value)} />
                    </div>
                    <div>
                        <InputLabel value="Expiry date" required />
                        <TextInput type="date" value={riskForm.data.expiry_date} onChange={(e) => riskForm.setData('expiry_date', e.target.value)} />
                    </div>
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={close}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={!riskForm.data.reason.trim() || !riskForm.data.expiry_date}>Accept risk</PrimaryButton>
                    </div>
                </form>
            </ActionModal>

            <ActionModal
                show={modal === 'compensating'}
                onClose={close}
                title="Register compensating control"
                description="Recognised in residual risk only after control function approval."
            >
                <form onSubmit={submitTo(compForm, 'compensating-controls.store')} className="space-y-4">
                    <div>
                        <InputLabel value="Description" required />
                        <TextArea value={compForm.data.description} onChange={(e) => compForm.setData('description', e.target.value)} />
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel value="Owner" />
                            <SelectInput value={compForm.data.owner_id} onChange={(e) => compForm.setData('owner_id', e.target.value)}>
                                <option value="">— Select —</option>
                                {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Duration" />
                            <SelectInput value={compForm.data.is_temporary ? '1' : '0'} onChange={(e) => compForm.setData('is_temporary', e.target.value === '1')}>
                                <option value="1">Temporary</option>
                                <option value="0">Permanent</option>
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Effective from" required />
                            <TextInput type="date" value={compForm.data.effective_from} onChange={(e) => compForm.setData('effective_from', e.target.value)} />
                        </div>
                        {compForm.data.is_temporary && (
                            <div>
                                <InputLabel value="Effective to" required />
                                <TextInput type="date" value={compForm.data.effective_to} onChange={(e) => compForm.setData('effective_to', e.target.value)} />
                            </div>
                        )}
                    </div>
                    <div>
                        <InputLabel value="Residual exposure NOT covered" />
                        <TextArea rows={2} value={compForm.data.residual_exposure_note} onChange={(e) => compForm.setData('residual_exposure_note', e.target.value)} />
                    </div>
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={close}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={!compForm.data.description.trim() || !compForm.data.effective_from}>Register</PrimaryButton>
                    </div>
                </form>
            </ActionModal>
        </AuthenticatedLayout>
    );
}
