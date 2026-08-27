import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import RichTextEditor from '@/Components/RichTextEditor';
import SelectInput from '@/Components/SelectInput';
import StatusBadge from '@/Components/StatusBadge';
import TextInput from '@/Components/TextInput';
import { formatDate, formatDateTime } from '@/utils';
import { Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Check, Gavel, MessageSquare, Plus, Search } from 'lucide-react';
import { useState } from 'react';

/**
 * Spec §5.4 — the Speak Up follow-up surface.
 *
 * A concern has to be workable, chaseable and reportable-back-on WITHOUT
 * opening the investigation it may have produced. Four things live here:
 *
 *   1. the screening decision and its reasoning;
 *   2. the acknowledgement to the reporter;
 *   3. the follow-up log — actions with an owner and a date;
 *   4. the linked investigation, READ-ONLY.
 *
 * The fourth is the one to be careful with. It shows a reference, a status
 * and who leads — never the contents. An officer handling the submission is
 * not thereby on the investigation team, and the server sends null here
 * when they are not, so a concern can be followed without the case file
 * being widened to whoever happens to be handling intake.
 */

const DECISION_LABELS = {
    refer_to_investigation: 'Refer to investigation',
    handle_within_speak_up: 'Handle within Speak Up',
    refer_externally: 'Refer externally',
    close_unsubstantiated: 'Close — unsubstantiated',
    close_resolved: 'Close — resolved',
    monitor: 'Monitor',
};

const humanise = (v) => (v ? String(v).replace(/_/g, ' ') : '—');

export default function SpeakUpFollowUp({
    speakUpCase,
    followUps = [],
    triagedBy = null,
    acknowledgedBy = null,
    triageDecisions = [],
    linkedInvestigation = null,
    users = [],
    can = {},
}) {
    const [dialog, setDialog] = useState(null);
    const close = () => setDialog(null);

    const triage = useForm({
        triage_decision: triageDecisions[0] ?? 'handle_within_speak_up',
        triage_note: speakUpCase.triage_note ?? '',
        triage_note_rich: speakUpCase.triage_note_rich ?? null,
    });

    const ack = useForm({ message: '' });

    const followUp = useForm({
        action: '',
        detail: '',
        detail_rich: null,
        owner_id: '',
        due_date: '',
    });

    const submit = (form, routeName, onDone) => (event) => {
        event.preventDefault();
        form.post(route(routeName, speakUpCase.id), {
            preserveScroll: true,
            onSuccess: () => {
                onDone?.();
                close();
            },
        });
    };

    const outstanding = followUps.filter((f) => !f.completed_at);
    const overdue = outstanding.filter((f) => f.due_date && new Date(f.due_date) < new Date());

    return (
        <div className="space-y-6">
            {/* ── Screening ──────────────────────────────────────────── */}
            <div className="card">
                <div className="card-header flex items-center justify-between">
                    <h3 className="text-sm font-semibold">
                        <Search className="me-1.5 inline h-4 w-4" aria-hidden="true" />
                        Screening
                    </h3>
                    {can.follow_up && (
                        <button type="button" className="text-sm text-[var(--color-primary)] hover:underline" onClick={() => setDialog('triage')}>
                            {speakUpCase.triaged_at ? 'Revise decision' : 'Record decision'}
                        </button>
                    )}
                </div>
                <div className="card-body space-y-3 text-sm">
                    {speakUpCase.triaged_at ? (
                        <>
                            <div className="flex flex-wrap items-center gap-3">
                                <StatusBadge status={DECISION_LABELS[speakUpCase.triage_decision] ?? humanise(speakUpCase.triage_decision)} />
                                <span className="text-xs text-[var(--color-text-secondary)]">
                                    Screened {formatDateTime(speakUpCase.triaged_at)}
                                    {triagedBy ? ` by ${triagedBy.name}` : ''}
                                </span>
                            </div>
                            <p className="whitespace-pre-line text-[var(--color-text-secondary)]">{speakUpCase.triage_note}</p>
                        </>
                    ) : (
                        <p className="text-[var(--color-text-secondary)]">
                            Not screened yet. The clock from receipt to screening is the number a Speak Up policy is
                            judged on, so record the decision as soon as it is taken.
                        </p>
                    )}
                </div>
            </div>

            {/* ── Acknowledgement to the reporter ────────────────────── */}
            <div className="card">
                <div className="card-header flex items-center justify-between">
                    <h3 className="text-sm font-semibold">
                        <MessageSquare className="me-1.5 inline h-4 w-4" aria-hidden="true" />
                        Reporter acknowledgement
                    </h3>
                    {can.follow_up && (
                        <button type="button" className="text-sm text-[var(--color-primary)] hover:underline" onClick={() => setDialog('ack')}>
                            {speakUpCase.acknowledged_at ? 'Send an update' : 'Acknowledge'}
                        </button>
                    )}
                </div>
                <div className="card-body text-sm">
                    {speakUpCase.acknowledged_at ? (
                        <p className="text-[var(--color-text-secondary)]">
                            Acknowledged {formatDateTime(speakUpCase.acknowledged_at)}
                            {acknowledgedBy ? ` by ${acknowledgedBy.name}` : ''}. Anything written here is visible to
                            the reporter when they check their case.
                        </p>
                    ) : (
                        <p className="text-[var(--color-text-secondary)]">
                            Not yet acknowledged. This is the first thing a reporter asks and the first thing a
                            regulator checks.
                        </p>
                    )}
                </div>
            </div>

            {/* ── Follow-up log ──────────────────────────────────────── */}
            <div className="card">
                <div className="card-header flex items-center justify-between">
                    <h3 className="text-sm font-semibold">
                        Follow-up log
                        {outstanding.length > 0 && (
                            <span className="ms-2 text-xs font-normal text-[var(--color-text-secondary)]">
                                {outstanding.length} outstanding
                                {overdue.length > 0 && <span className="text-[var(--color-error)]"> · {overdue.length} past due</span>}
                            </span>
                        )}
                    </h3>
                    {can.follow_up && (
                        <button type="button" className="text-sm text-[var(--color-primary)] hover:underline" onClick={() => setDialog('followUp')}>
                            <Plus className="me-1 inline h-3.5 w-3.5" aria-hidden="true" />
                            Add action
                        </button>
                    )}
                </div>
                <div className="card-body">
                    {followUps.length === 0 ? (
                        <p className="text-sm text-[var(--color-text-secondary)]">
                            Nothing recorded yet. A concern followed up in someone&apos;s memory cannot be chased,
                            counted or reported.
                        </p>
                    ) : (
                        <ul className="divide-y divide-gray-100">
                            {followUps.map((f) => {
                                const isOverdue = !f.completed_at && f.due_date && new Date(f.due_date) < new Date();

                                return (
                                    <li key={f.id} className="flex items-start gap-3 py-3">
                                        <span className="mt-0.5">
                                            {f.completed_at ? (
                                                <Check className="h-4 w-4 text-emerald-600" aria-hidden="true" />
                                            ) : isOverdue ? (
                                                <AlertTriangle className="h-4 w-4 text-[var(--color-error)]" aria-hidden="true" />
                                            ) : (
                                                <span className="block h-4 w-4 rounded-full border border-gray-300" aria-hidden="true" />
                                            )}
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <p className={`text-sm font-medium ${f.completed_at ? 'text-gray-400 line-through' : ''}`}>
                                                {f.action}
                                            </p>
                                            {f.detail && (
                                                <p className="mt-0.5 whitespace-pre-line text-xs text-[var(--color-text-secondary)]">{f.detail}</p>
                                            )}
                                            <p className="mt-1 text-xs text-gray-400">
                                                {f.owner?.name ?? 'Unassigned'}
                                                {f.due_date && ` · due ${formatDate(f.due_date)}`}
                                                {f.completed_at && ` · done ${formatDate(f.completed_at)}${f.completer ? ` by ${f.completer.name}` : ''}`}
                                            </p>
                                        </div>
                                        {can.follow_up && !f.completed_at && (
                                            <button
                                                type="button"
                                                className="shrink-0 text-xs text-[var(--color-primary)] hover:underline"
                                                onClick={() =>
                                                    router.post(
                                                        route('cases.follow-ups.complete', [speakUpCase.id, f.id]),
                                                        {},
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                Mark done
                                            </button>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            </div>

            {/* ── The linked investigation, read-only ────────────────── */}
            <div className="card">
                <div className="card-header">
                    <h3 className="text-sm font-semibold">
                        <Gavel className="me-1.5 inline h-4 w-4" aria-hidden="true" />
                        Linked investigation
                    </h3>
                </div>
                <div className="card-body text-sm">
                    {linkedInvestigation ? (
                        <div className="space-y-2">
                            <div className="flex flex-wrap items-center gap-3">
                                <Link
                                    href={route('investigations.show', linkedInvestigation.id)}
                                    className="font-mono text-xs font-semibold text-[var(--color-primary)] hover:underline"
                                >
                                    {linkedInvestigation.reference}
                                </Link>
                                <StatusBadge status={humanise(linkedInvestigation.status)} />
                                {linkedInvestigation.risk_rating && <StatusBadge status={linkedInvestigation.risk_rating} />}
                            </div>
                            <p className="text-xs text-[var(--color-text-secondary)]">
                                Led by {linkedInvestigation.lead ?? '—'}
                                {linkedInvestigation.opened_on && ` · opened ${formatDate(linkedInvestigation.opened_on)}`}
                            </p>
                            <p className="text-xs text-gray-400">
                                Status only. Opening the case file needs a seat on the investigation team — being on
                                this submission does not grant one.
                            </p>
                        </div>
                    ) : (
                        <p className="text-[var(--color-text-secondary)]">
                            No investigation has been raised from this concern. Most never need one.
                        </p>
                    )}
                </div>
            </div>

            {/* ── Dialogs ────────────────────────────────────────────── */}

            <Modal show={dialog === 'triage'} onClose={close} maxWidth="2xl">
                <form onSubmit={submit(triage, 'cases.triage')} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold">Record the screening decision</h2>
                    <div>
                        <InputLabel value="Decision" required />
                        <SelectInput
                            className="mt-1 block w-full"
                            value={triage.data.triage_decision}
                            onChange={(e) => triage.setData('triage_decision', e.target.value)}
                        >
                            {triageDecisions.map((d) => (
                                <option key={d} value={d}>
                                    {DECISION_LABELS[d] ?? humanise(d)}
                                </option>
                            ))}
                        </SelectInput>
                        <InputError message={triage.errors.triage_decision} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Reasoning" required />
                        <RichTextEditor
                            value={triage.data.triage_note_rich ?? triage.data.triage_note}
                            onChange={(doc, plain) => triage.setData((d) => ({ ...d, triage_note: plain, triage_note_rich: doc }))}
                            tools="minimal"
                            minHeight={140}
                        />
                        <InputError message={triage.errors.triage_note} className="mt-1" />
                    </div>
                    <div className="flex justify-end gap-2">
                        <button type="button" className="btn-secondary" onClick={close}>
                            Cancel
                        </button>
                        <button type="submit" className="btn-primary" disabled={triage.processing}>
                            Record decision
                        </button>
                    </div>
                </form>
            </Modal>

            <Modal show={dialog === 'ack'} onClose={close} maxWidth="lg">
                <form onSubmit={submit(ack, 'cases.acknowledge', () => ack.reset())} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold">Acknowledge to the reporter</h2>
                    <p className="text-sm text-[var(--color-text-secondary)]">
                        Anything written here is visible to the reporter when they check their case with their token.
                        Leave it blank to record the acknowledgement without a message.
                    </p>
                    <div>
                        <InputLabel value="Message to the reporter" />
                        <RichTextEditor
                            value={ack.data.message}
                            onChange={(doc, plain) => ack.setData('message', plain)}
                            tools="minimal"
                            minHeight={110}
                        />
                        <InputError message={ack.errors.message} className="mt-1" />
                    </div>
                    <div className="flex justify-end gap-2">
                        <button type="button" className="btn-secondary" onClick={close}>
                            Cancel
                        </button>
                        <button type="submit" className="btn-primary" disabled={ack.processing}>
                            Send
                        </button>
                    </div>
                </form>
            </Modal>

            <Modal show={dialog === 'followUp'} onClose={close} maxWidth="2xl">
                <form onSubmit={submit(followUp, 'cases.follow-ups.store', () => followUp.reset())} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold">Add a follow-up action</h2>
                    <div>
                        <InputLabel value="Action" required />
                        <TextInput
                            className="mt-1 block w-full"
                            value={followUp.data.action}
                            onChange={(e) => followUp.setData('action', e.target.value)}
                        />
                        <InputError message={followUp.errors.action} className="mt-1" />
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Owner" />
                            <SelectInput
                                className="mt-1 block w-full"
                                value={followUp.data.owner_id}
                                onChange={(e) => followUp.setData('owner_id', e.target.value)}
                            >
                                <option value="">Unassigned</option>
                                {users.map((u) => (
                                    <option key={u.id} value={u.id}>
                                        {u.name}
                                    </option>
                                ))}
                            </SelectInput>
                            <InputError message={followUp.errors.owner_id} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Due date" />
                            <TextInput
                                type="date"
                                className="mt-1 block w-full"
                                value={followUp.data.due_date}
                                onChange={(e) => followUp.setData('due_date', e.target.value)}
                            />
                            <InputError message={followUp.errors.due_date} className="mt-1" />
                        </div>
                    </div>
                    <div>
                        <InputLabel value="Detail" />
                        <RichTextEditor
                            value={followUp.data.detail_rich ?? followUp.data.detail}
                            onChange={(doc, plain) => followUp.setData((d) => ({ ...d, detail: plain, detail_rich: doc }))}
                            tools="minimal"
                            minHeight={110}
                        />
                    </div>
                    <div className="flex justify-end gap-2">
                        <button type="button" className="btn-secondary" onClick={close}>
                            Cancel
                        </button>
                        <button type="submit" className="btn-primary" disabled={followUp.processing}>
                            Add action
                        </button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
