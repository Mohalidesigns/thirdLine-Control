import CameraCapture from '@/Components/CameraCapture';
import StatusBadge from '@/Components/StatusBadge';
import SyncStatusIndicator from '@/Components/SyncStatusIndicator';
import useAutosave from '@/hooks/useAutosave';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { offlineDb } from '@/offline/db';
import { queueAction } from '@/offline/outbox';
import { enablePush, pushSupported } from '@/offline/push';
import { formatDate } from '@/utils';
import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * The mobile task view (15.2): what do I owe, by when — one card per
 * item, one tap to act, everything ≥44px and thumb-reachable. Actions
 * queue through the offline outbox, so the page behaves identically on
 * and offline; approvals are deliberately absent (server-only, 15.1).
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

    const total =
        feed.attestations.filter((a) => !isDone('att', a.campaign_id)).length +
        feed.test_instances.length +
        feed.csa_responses.length;

    return (
        <AuthenticatedLayout header="My tasks">
            <Head title="My tasks" />

            <div className="mx-auto max-w-lg pb-24">
                <div className="mb-4">
                    <h1 className="text-lg font-bold text-gray-900">My tasks</h1>
                    <p className="text-sm text-gray-500">
                        {total === 0 ? 'Nothing outstanding — well done.' : `${total} item${total > 1 ? 's' : ''} need${total === 1 ? 's' : ''} you.`}
                        {' '}Works offline; changes queue and sync automatically.
                    </p>
                </div>

                {pushState === 'available' && (
                    <button
                        type="button"
                        className="btn-secondary mb-3 min-h-[44px] w-full"
                        onClick={async () => {
                            await enablePush(pushPublicKey);
                            setPushState('hidden');
                        }}
                    >
                        Enable push notifications on this device
                    </button>
                )}

                {feed.attestations.filter((a) => !isDone('att', a.campaign_id)).map((attestation) => (
                    <AttestationCard
                        key={attestation.campaign_id}
                        attestation={attestation}
                        onDone={() => mark('att', attestation.campaign_id)}
                    />
                ))}

                {feed.test_instances.map((instance) => (
                    <TestInstanceCard key={instance.id} instance={instance} />
                ))}

                {feed.csa_responses.map((response) => (
                    <CsaCard key={response.id} response={response} />
                ))}

                {feed.controls.length > 0 && (
                    <details className="card mt-4">
                        <summary className="min-h-[44px] cursor-pointer py-2 text-sm font-semibold text-gray-700">
                            My controls ({feed.controls.length})
                        </summary>
                        <ul className="divide-y divide-gray-100">
                            {feed.controls.map((control) => (
                                <li key={control.id} className="flex items-center justify-between py-2 text-sm">
                                    <span>
                                        <span className="font-mono text-xs text-gray-400">{control.reference}</span>{' '}
                                        {control.title}
                                    </span>
                                    <StatusBadge status={control.status} />
                                </li>
                            ))}
                        </ul>
                    </details>
                )}

                <p className="mt-6 text-center text-xs text-gray-400">
                    Approvals, reviews and submissions need a live connection — they are not queueable offline.
                </p>
            </div>

            <SyncStatusIndicator />
        </AuthenticatedLayout>
    );
}

function AttestationCard({ attestation, onDone }) {
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
        <div className="card mb-3">
            <div className="flex items-start justify-between gap-2">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-accent)]">Attestation</p>
                    <p className="font-semibold text-gray-900">{attestation.name}</p>
                    <p className="text-xs text-gray-500">
                        {attestation.subject_label} · due {formatDate(attestation.closes_at)}
                    </p>
                </div>
            </div>

            {expanded && (
                <div className="mt-2 max-h-48 overflow-y-auto whitespace-pre-wrap rounded bg-gray-50 p-3 text-sm text-gray-700">
                    {attestation.subject_text ?? 'No text available offline.'}
                </div>
            )}

            <div className="mt-3 flex gap-2">
                <button type="button" className="btn-secondary min-h-[44px] flex-1" onClick={() => setExpanded(!expanded)}>
                    {expanded ? 'Hide text' : 'Read text'}
                </button>
                <button type="button" className="btn-primary min-h-[44px] flex-1" disabled={busy} onClick={attest}>
                    {busy ? 'Queued ✓' : 'I attest'}
                </button>
            </div>
        </div>
    );
}

function TestInstanceCard({ instance }) {
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

    return (
        <div className="card mb-3">
            <div className="flex items-start justify-between gap-2">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-accent)]">Control test</p>
                    <p className="font-semibold text-gray-900">{instance.control?.title}</p>
                    <p className="text-xs text-gray-500">
                        {instance.reference} · {instance.period_label} · due {formatDate(instance.due_date)}
                        {instance.is_overdue && <span className="ml-1 font-semibold text-[var(--color-error)]">OVERDUE</span>}
                    </p>
                </div>
                <StatusBadge status={instance.status} />
            </div>

            <button type="button" className="btn-secondary mt-3 min-h-[44px] w-full" onClick={() => setOpen(!open)}>
                {open ? 'Hide checks' : `Record checks (${instance.check_items.length})`}
            </button>

            {open && (
                <div className="mt-3 space-y-4">
                    {instance.check_items.map((item) => {
                        const current = recorded[item.id] ?? item.result?.result ?? null;

                        return (
                            <div key={item.id} className="rounded-lg border border-gray-100 p-3">
                                <p className="text-sm font-medium text-gray-800">
                                    {item.sequence}. {item.question}
                                </p>
                                {item.guidance && <p className="mt-0.5 text-xs text-gray-500">{item.guidance}</p>}

                                <div className="mt-2 flex gap-2">
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

                    <CameraCapture onCapture={captureEvidence} label="Attach photo evidence" />
                    <p className="text-xs text-gray-400">
                        Submitting the completed test for review needs a live connection.
                    </p>
                </div>
            )}
        </div>
    );
}

function CsaCard({ response }) {
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

    return (
        <div className="card mb-3">
            <div>
                <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-accent)]">Self-assessment</p>
                <p className="font-semibold text-gray-900">{response.campaign?.name}</p>
                <p className="text-xs text-gray-500">
                    {response.campaign?.reference} · closes {formatDate(response.campaign?.closes_at)}
                </p>
            </div>

            <button type="button" className="btn-secondary mt-3 min-h-[44px] w-full" onClick={() => setOpen(!open)}>
                {open ? 'Hide questionnaire' : `Answer (${response.questions.length} questions)`}
            </button>

            {open && (
                <div className="mt-3 space-y-4">
                    {response.questions.map((question) => (
                        <div key={question.id}>
                            <label className="text-sm font-medium text-gray-800">
                                {question.sequence}. {question.question_text}
                                {question.is_required && <span className="text-[var(--color-error)]"> *</span>}
                            </label>
                            {question.help_text && <p className="text-xs text-gray-500">{question.help_text}</p>}
                            <QuestionInput
                                question={question}
                                value={answers[question.id] ?? ''}
                                onChange={(value) => setAnswers((a) => ({ ...a, [question.id]: value }))}
                            />
                        </div>
                    ))}

                    <div className="flex gap-2">
                        <button type="button" className="btn-secondary min-h-[44px] flex-1" onClick={() => save(false)}>
                            {queued ? 'Draft queued ✓' : 'Save draft'}
                        </button>
                        <button type="button" className="btn-primary min-h-[44px] flex-1" onClick={() => save(true)}>
                            Submit
                        </button>
                    </div>
                    <p className="text-xs text-gray-400">Answers autosave on this device as you type.</p>
                </div>
            )}
        </div>
    );
}

function QuestionInput({ question, value, onChange }) {
    const options = question.options ?? [];

    if (question.response_type === 'yes_no' || question.response_type === 'boolean') {
        return (
            <div className="mt-1 flex gap-2">
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
                className="mt-1 w-full rounded-lg border-gray-200 text-sm"
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
            className="mt-1 w-full rounded-lg border-gray-200 text-sm"
            value={value}
            onChange={(e) => onChange(e.target.value)}
        />
    );
}
