import CameraCapture from '@/Components/CameraCapture';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import SyncStatusIndicator from '@/Components/SyncStatusIndicator';
import useAutosave from '@/hooks/useAutosave';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { offlineDb } from '@/offline/db';
import { queueAction } from '@/offline/outbox';
import { enablePush, pushSupported } from '@/offline/push';
import { formatDate } from '@/utils';
import { Head } from '@inertiajs/react';
import { BadgeCheck, ClipboardList, FlaskConical, ListChecks, ShieldCheck } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * The task view (15.2): what do I owe, by when — one row per item, one tap
 * to act, everything ≥44px and thumb-reachable. Actions queue through the
 * offline outbox, so the page behaves identically on and offline;
 * approvals are deliberately absent (server-only, 15.1).
 *
 * Laid out in the house section-card language — titled header, divider,
 * rows — which is what every other register in the suite uses and what the
 * page was conspicuously not using. A bare stack of floating cards reads as
 * unfinished next to the rest of the product: on a phone it was a list, and
 * on a desktop it was a list with a lot of grey around it.
 *
 * Grouping by KIND of work is what gives it a spine, and it happens to be
 * the question an officer actually asks — "what tests do I owe" is a
 * different question from "what have I been asked to attest", and they are
 * owed to different people on different clocks.
 *
 * Mobile-first still holds. Every rule behind an `sm:`/`lg:`/`xl:` prefix
 * is an addition for a wider screen; at 375px the rows stack, the controls
 * run full width, and nothing you press is smaller than 44px.
 */
export default function Tasks({ work, pushPublicKey }) {
    const [feed, setFeed] = useState(work);
    const [done, setDone] = useState({}); // client-side "acted" marks
    const [pushState, setPushState] = useState(() =>
        pushSupported() && pushPublicKey && Notification.permission === 'default' ? 'available' : 'hidden',
    );

    // Cache the fresh feed for offline; offline, fall back to the cache.
    useEffect(() => {
        if (!offlineDb.available) return;

        if (navigator.onLine) {
            Promise.all([
                offlineDb.putWork('test_instances', work.test_instances),
                offlineDb.putWork('csa_responses', work.csa_responses),
                offlineDb.putWork('attestations', work.attestations),
                offlineDb.putWork('controls', work.controls),
            ]);
        } else {
            Promise.all([
                offlineDb.getWork('test_instances'),
                offlineDb.getWork('csa_responses'),
                offlineDb.getWork('attestations'),
                offlineDb.getWork('controls'),
            ]).then(([tests, csa, attestations, controls]) => {
                if (tests || csa || attestations || controls) {
                    setFeed({
                        test_instances: tests ?? [],
                        csa_responses: csa ?? [],
                        attestations: attestations ?? [],
                        controls: controls ?? [],
                    });
                }
            });
        }
    }, [work]);

    const mark = (kind, id) => setDone((d) => ({ ...d, [`${kind}:${id}`]: true }));
    const isDone = (kind, id) => !!done[`${kind}:${id}`];

    const attestations = feed.attestations.filter((a) => !isDone('att', a.campaign_id));
    const total = attestations.length + feed.test_instances.length + feed.csa_responses.length;

    return (
        <AuthenticatedLayout header="My tasks">
            <Head title="My tasks" />

            <div className="mx-auto w-full max-w-lg pb-24 md:max-w-3xl xl:max-w-6xl">
                <PageHeader
                    title="My tasks"
                    subtitle={
                        total === 0
                            ? 'Nothing outstanding. Works offline; changes queue and sync automatically.'
                            : `${total} item${total > 1 ? 's' : ''} need${total === 1 ? 's' : ''} you. Works offline; changes queue and sync automatically.`
                    }
                    icon={ListChecks}
                    actions={
                        pushState === 'available' && (
                            <button
                                type="button"
                                className="btn-secondary min-h-[44px] w-full sm:w-auto"
                                onClick={async () => {
                                    await enablePush(pushPublicKey);
                                    setPushState('hidden');
                                }}
                            >
                                Enable push on this device
                            </button>
                        )
                    }
                />

                <div className="space-y-6">
                    {total === 0 && (
                        <div className="card">
                            <EmptyState
                                icon={BadgeCheck}
                                title="Nothing outstanding — well done."
                                description="Every test, self-assessment and attestation assigned to you is done. New work appears here as the frequency engine generates it."
                            />
                        </div>
                    )}

                    <Section
                        title="Control tests"
                        icon={FlaskConical}
                        count={feed.test_instances.length}
                        caption="Checklists generated for the desks and branches you cover."
                    >
                        {feed.test_instances.map((instance) => (
                            <TestInstanceRow key={instance.id} instance={instance} />
                        ))}
                    </Section>

                    <Section
                        title="Self-assessments"
                        icon={ClipboardList}
                        count={feed.csa_responses.length}
                        caption="Questionnaires awaiting your answers. Drafts autosave on this device."
                    >
                        {feed.csa_responses.map((response) => (
                            <CsaRow key={response.id} response={response} />
                        ))}
                    </Section>

                    <Section
                        title="Attestations"
                        icon={ShieldCheck}
                        count={attestations.length}
                        caption="Policies and codes you have been asked to confirm you have read."
                    >
                        {attestations.map((attestation) => (
                            <AttestationRow
                                key={attestation.campaign_id}
                                attestation={attestation}
                                onDone={() => mark('att', attestation.campaign_id)}
                            />
                        ))}
                    </Section>

                    {feed.controls.length > 0 && (
                        <section className="card">
                            <div className="card-header">
                                <div className="min-w-0">
                                    <h3 className="flex items-center gap-2 text-sm font-semibold text-gray-800">
                                        <ShieldCheck className="h-4 w-4 text-gray-400" strokeWidth={1.8} aria-hidden="true" />
                                        My controls
                                    </h3>
                                    <p className="mt-0.5 hidden text-xs text-gray-400 sm:block">
                                        Controls you own. Nothing is owed on these today — they are here for reference.
                                    </p>
                                </div>
                                <span className="badge badge-status-draft shrink-0">{feed.controls.length}</span>
                            </div>
                            <ul className="divide-y divide-gray-100">
                                {feed.controls.map((control) => (
                                    <li key={control.id} className="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                                        <span className="min-w-0 truncate">
                                            <span className="font-mono text-xs text-gray-400">{control.reference}</span>{' '}
                                            {control.title}
                                        </span>
                                        <StatusBadge status={control.status} />
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                </div>

                <p className="mt-6 text-center text-xs text-gray-400">
                    Approvals, reviews and submissions need a live connection — they are not queueable offline.
                </p>
            </div>

            <SyncStatusIndicator />
        </AuthenticatedLayout>
    );
}

/**
 * A titled card with a divided row list. Renders nothing when it holds no
 * work, so an officer who owes no self-assessments is not shown an empty
 * box telling them so — the page-level empty state covers "nothing at all".
 */
function Section({ title, icon: Icon, count, caption, children }) {
    if (count === 0) return null;

    return (
        <section className="card">
            <div className="card-header">
                <div className="min-w-0">
                    <h3 className="flex items-center gap-2 text-sm font-semibold text-gray-800">
                        <Icon className="h-4 w-4 text-gray-400" strokeWidth={1.8} aria-hidden="true" />
                        {title}
                    </h3>
                    <p className="mt-0.5 hidden text-xs text-gray-400 sm:block">{caption}</p>
                </div>
                <span className="badge badge-status-draft shrink-0">{count}</span>
            </div>
            <ul className="divide-y divide-gray-100">{children}</ul>
        </section>
    );
}

/**
 * The identity line every row opens with: what it is, its reference and
 * dates underneath, its state and its one action to the right. Kept in one
 * place so a test, a questionnaire and an attestation read alike down the
 * page — on a phone the action drops below the title rather than crushing
 * it.
 */
function RowHeading({ title, meta, badge, action }) {
    return (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
            <div className="min-w-0">
                <p className="font-semibold text-[var(--color-text-primary)]">{title}</p>
                <p className="mt-0.5 text-xs text-[var(--color-text-secondary)]">{meta}</p>
            </div>
            <div className="flex shrink-0 items-center gap-2">
                {/* The badge holds its width: on a phone the action button
                    goes full-width beside it, and without this the status
                    gets squeezed into two lines. */}
                {badge && <span className="shrink-0 whitespace-nowrap">{badge}</span>}
                {action}
            </div>
        </div>
    );
}

function AttestationRow({ attestation, onDone }) {
    const [expanded, setExpanded] = useState(false);
    const [busy, setBusy] = useState(false);

    async function attest() {
        setBusy(true);
        await queueAction('attestation.complete', {
            campaign_id: attestation.campaign_id,
            accepted: true,
        });
        onDone();
    }

    return (
        <li className="px-5 py-4">
            <RowHeading
                title={attestation.name}
                meta={`${attestation.subject_label} · due ${formatDate(attestation.closes_at)}`}
                action={
                    <div className="flex flex-1 gap-2 sm:flex-none">
                        <button
                            type="button"
                            className="btn-secondary min-h-[44px] flex-1 px-4 sm:flex-none"
                            onClick={() => setExpanded(!expanded)}
                        >
                            {expanded ? 'Hide text' : 'Read text'}
                        </button>
                        <button
                            type="button"
                            className="btn-primary min-h-[44px] flex-1 px-4 sm:flex-none"
                            disabled={busy}
                            onClick={attest}
                        >
                            {busy ? 'Queued ✓' : 'I attest'}
                        </button>
                    </div>
                }
            />

            {expanded && (
                <div className="mt-3 max-h-64 overflow-y-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-4 text-sm text-gray-700">
                    {attestation.subject_text ?? 'No text available offline.'}
                </div>
            )}
        </li>
    );
}

function TestInstanceRow({ instance }) {
    const [open, setOpen] = useState(false);
    const [recorded, setRecorded] = useState({});
    const [comments, setComments] = useState({});

    async function record(item, result) {
        await queueAction('test.record-result', {
            test_instance_id: instance.id,
            check_item_id: item.id,
            result,
            comment: comments[item.id] ?? null,
            expected_updated_at: instance.updated_at,
        });
        setRecorded((r) => ({ ...r, [item.id]: result }));
    }

    async function captureEvidence(file, extra) {
        await queueAction('evidence.capture', {
            blob: file,
            file_name: file.name,
            meta: {
                linked_type: 'test_instance',
                linked_id: instance.id,
                contains_personal_data: false,
                classification: 'Internal',
                ...(extra.location ? { capture_location: JSON.stringify(extra.location) } : {}),
            },
        });
    }

    // How far through the checklist you are, on the button you press to get
    // back into it — the one number you want before you open anything.
    const answered = instance.check_items.filter(
        (item) => (recorded[item.id] ?? item.result?.result ?? null) !== null,
    ).length;

    return (
        <li className="px-5 py-4">
            <RowHeading
                title={instance.control?.title}
                meta={
                    <>
                        <span className="font-mono">{instance.reference}</span> · {instance.period_label} · due{' '}
                        {formatDate(instance.due_date)}
                        {instance.control_entity?.name && <> · {instance.control_entity.name}</>}
                        {instance.frequency?.label && <> · {instance.frequency.label}</>}
                        {instance.is_overdue && (
                            <span className="ms-1 font-semibold text-[var(--color-error)]">OVERDUE</span>
                        )}
                    </>
                }
                badge={<StatusBadge status={instance.status} />}
                action={
                    <button
                        type="button"
                        className="btn-secondary min-h-[44px] w-full px-4 sm:w-auto"
                        onClick={() => setOpen(!open)}
                    >
                        {open ? 'Hide checks' : `Record checks (${answered}/${instance.check_items.length})`}
                    </button>
                }
            />

            {open && (
                <div className="mt-4 grid gap-3 xl:grid-cols-2 xl:items-start">
                    {instance.check_items.map((item) => {
                        const current = recorded[item.id] ?? item.result?.result ?? null;

                        return (
                            <div key={item.id} className="rounded-lg border border-gray-100 bg-gray-50/60 p-4">
                                <p className="text-sm font-medium text-gray-800">
                                    {item.sequence}. {item.question}
                                </p>
                                {item.guidance && <p className="mt-1 text-xs text-gray-500">{item.guidance}</p>}

                                {/* Capped, not stretched: three choices a thumb
                                    can tell apart, at any width. */}
                                <div className="mt-3 flex gap-2 sm:max-w-sm">
                                    {['Pass', 'Fail', 'NA'].map((result) => (
                                        <button
                                            key={result}
                                            type="button"
                                            onClick={() => record(item, result)}
                                            className={`min-h-[44px] flex-1 rounded-lg border text-sm font-semibold ${
                                                current === result
                                                    ? result === 'Fail'
                                                        ? 'border-[var(--color-error)] bg-[var(--color-error)] text-white'
                                                        : 'border-[var(--color-primary)] bg-[var(--color-primary)] text-white'
                                                    : 'border-gray-200 bg-white text-gray-700'
                                            }`}
                                        >
                                            {result}
                                        </button>
                                    ))}
                                </div>

                                <input
                                    type="text"
                                    placeholder="Comment (required for Fail)"
                                    className="mt-2 w-full rounded-lg border-gray-200 text-sm"
                                    value={comments[item.id] ?? ''}
                                    onChange={(e) => setComments((c) => ({ ...c, [item.id]: e.target.value }))}
                                />
                            </div>
                        );
                    })}

                    <div className="xl:col-span-2">
                        {/* CameraCapture is w-full by design for the thumb, so
                            the measure is capped here, not in the shared
                            component. */}
                        <div className="sm:max-w-sm">
                            <CameraCapture onCapture={captureEvidence} label="Attach photo evidence" />
                        </div>
                        <p className="mt-2 text-xs text-gray-400">
                            Submitting the completed test for review needs a live connection.
                        </p>
                    </div>
                </div>
            )}
        </li>
    );
}

function CsaRow({ response }) {
    const [open, setOpen] = useState(false);
    const [restore, clearDraft] = useAutosave(`csa-${response.id}`, null);
    const [answers, setAnswers] = useState(() => {
        const saved = restore();
        if (saved) return saved;

        const initial = {};
        response.answers.forEach((a) => {
            initial[a.question_id] = a.answer_value?.value ?? a.answer_value ?? '';
        });
        return initial;
    });
    const [, clear] = useAutosave(`csa-${response.id}`, answers);
    const [queued, setQueued] = useState(false);

    async function save(submit) {
        await queueAction('csa.answers', {
            response_id: response.id,
            answers: Object.entries(answers)
                .filter(([, value]) => value !== '' && value !== null)
                .map(([question_id, value]) => ({ question_id: Number(question_id), value })),
            submit,
            expected_updated_at: response.updated_at,
        });
        clear();
        clearDraft();
        setQueued(true);
    }

    const answered = response.questions.filter(
        (q) => answers[q.id] !== '' && answers[q.id] !== null && answers[q.id] !== undefined,
    ).length;

    return (
        <li className="px-5 py-4">
            <RowHeading
                title={response.campaign?.name}
                meta={
                    <>
                        <span className="font-mono">{response.campaign?.reference}</span> · closes{' '}
                        {formatDate(response.campaign?.closes_at)}
                    </>
                }
                action={
                    <button
                        type="button"
                        className="btn-secondary min-h-[44px] w-full px-4 sm:w-auto"
                        onClick={() => setOpen(!open)}
                    >
                        {open ? 'Hide questionnaire' : `Answer (${answered}/${response.questions.length})`}
                    </button>
                }
            />

            {open && (
                <div className="mt-4 grid gap-3 xl:grid-cols-2 xl:items-start">
                    {response.questions.map((question) => (
                        <div key={question.id} className="rounded-lg border border-gray-100 bg-gray-50/60 p-4">
                            <label className="text-sm font-medium text-gray-800">
                                {question.sequence}. {question.question_text}
                                {question.is_required && <span className="text-[var(--color-error)]"> *</span>}
                            </label>
                            {question.help_text && <p className="mt-1 text-xs text-gray-500">{question.help_text}</p>}
                            <QuestionInput
                                question={question}
                                value={answers[question.id] ?? ''}
                                onChange={(value) => setAnswers((a) => ({ ...a, [question.id]: value }))}
                            />
                        </div>
                    ))}

                    <div className="xl:col-span-2">
                        <div className="flex gap-2 sm:max-w-sm">
                            <button type="button" className="btn-secondary min-h-[44px] flex-1" onClick={() => save(false)}>
                                {queued ? 'Draft queued ✓' : 'Save draft'}
                            </button>
                            <button type="button" className="btn-primary min-h-[44px] flex-1" onClick={() => save(true)}>
                                Submit
                            </button>
                        </div>
                        <p className="mt-2 text-xs text-gray-400">Answers autosave on this device as you type.</p>
                    </div>
                </div>
            )}
        </li>
    );
}

function QuestionInput({ question, value, onChange }) {
    const options = question.options ?? [];

    if (question.response_type === 'yes_no' || question.response_type === 'boolean') {
        return (
            <div className="mt-3 flex gap-2 sm:max-w-xs">
                {['Yes', 'No'].map((option) => (
                    <button
                        key={option}
                        type="button"
                        onClick={() => onChange(option)}
                        className={`min-h-[44px] flex-1 rounded-lg border text-sm font-semibold ${
                            value === option
                                ? 'border-[var(--color-primary)] bg-[var(--color-primary)] text-white'
                                : 'border-gray-200 bg-white text-gray-700'
                        }`}
                    >
                        {option}
                    </button>
                ))}
            </div>
        );
    }

    if (options.length > 0) {
        return (
            <select
                className="mt-2 w-full rounded-lg border-gray-200 text-sm sm:max-w-md"
                value={value}
                onChange={(e) => onChange(e.target.value)}
            >
                <option value="">Select…</option>
                {options.map((option) => (
                    <option key={typeof option === 'object' ? option.value : option} value={typeof option === 'object' ? option.value : option}>
                        {typeof option === 'object' ? (option.label ?? option.value) : option}
                    </option>
                ))}
            </select>
        );
    }

    return (
        <textarea
            rows={2}
            className="mt-2 w-full rounded-lg border-gray-200 text-sm"
            value={value}
            onChange={(e) => onChange(e.target.value)}
        />
    );
}
