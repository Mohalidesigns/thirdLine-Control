import DangerButton from '@/Components/DangerButton';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * The review panel every AI suggestion passes through (§C.9).
 *
 * Four things are always on screen together, because a draft without them is
 * not reviewable: the suggestion, its confidence, the records it cites, and
 * the Accept / Edit / Reject decision. Rejecting requires a reason — that
 * reason is the only quality signal the next prompt version gets.
 *
 * "AI never decides" is enforced in the gateway, not here. But the panel
 * should read that way too, so nothing is pre-selected, no button is styled
 * as the obvious one, and the header says plainly that this is a draft.
 */

const REJECT_CATEGORIES = [
    { value: 'incorrect', label: 'Factually wrong' },
    { value: 'incomplete', label: 'Missed something important' },
    { value: 'irrelevant', label: 'Not relevant here' },
    { value: 'unsafe', label: 'Unsafe or inappropriate' },
    { value: 'formatting', label: 'Wrong shape or format' },
    { value: 'other', label: 'Something else' },
];

function ConfidenceBadge({ value, low }) {
    const percent = Math.round((value ?? 0) * 100);
    const tone = low
        ? 'bg-amber-50 text-amber-800 border-amber-200'
        : percent >= 70
          ? 'bg-emerald-50 text-emerald-800 border-emerald-200'
          : 'bg-gray-50 text-gray-700 border-gray-200';

    return (
        <span className={`inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-semibold ${tone}`}>
            {percent}% confidence
            {low && <span className="font-normal">· review closely</span>}
        </span>
    );
}

function Citations({ citations = [] }) {
    if (!citations.length) {
        return (
            <p className="text-xs text-[var(--color-warning)]">
                This draft cites no records from your data. Treat it as a starting point only.
            </p>
        );
    }

    return (
        <ul className="space-y-1.5">
            {citations.map((citation) => (
                <li key={`${citation.record_type}-${citation.record_id}`} className="text-xs">
                    <span className="mr-1.5 rounded bg-gray-100 px-1.5 py-0.5 font-medium text-gray-600">
                        {citation.label}
                    </span>
                    {citation.route ? (
                        <Link
                            href={route(citation.route, citation.record_id)}
                            className="font-mono text-[var(--color-primary)] hover:underline"
                        >
                            {citation.reference || `#${citation.record_id}`}
                        </Link>
                    ) : (
                        <span className="font-mono text-gray-600">{citation.reference || `#${citation.record_id}`}</span>
                    )}
                    {citation.title && <span className="ml-1.5 text-gray-500">{citation.title}</span>}
                    {citation.claim && <p className="mt-0.5 pl-1 text-gray-400">{citation.claim}</p>}
                </li>
            ))}
        </ul>
    );
}

/** Readable rendering of an arbitrary draft payload, whatever its schema. */
function DraftBody({ content }) {
    const entries = Object.entries(content ?? {}).filter(
        ([key]) => !['confidence', 'citations', 'insufficient_context'].includes(key),
    );

    if (!entries.length) {
        return <p className="text-sm text-gray-500">The assistant returned nothing to review.</p>;
    }

    return (
        <dl className="space-y-3">
            {entries.map(([key, value]) => (
                <div key={key}>
                    <dt className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                        {key.replace(/_/g, ' ')}
                    </dt>
                    <dd className="mt-1 whitespace-pre-wrap break-words text-sm text-[var(--color-text-primary)]">
                        {typeof value === 'object' && value !== null ? (
                            <pre className="overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs leading-relaxed">
                                {JSON.stringify(value, null, 2)}
                            </pre>
                        ) : typeof value === 'boolean' ? (
                            value ? 'Yes' : 'No'
                        ) : (
                            String(value ?? '—')
                        )}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

export default function AiReviewPanel({ draft, show, onClose, onDecided = null }) {
    const [rejecting, setRejecting] = useState(false);
    const [reason, setReason] = useState('');
    const [category, setCategory] = useState('incorrect');
    const [processing, setProcessing] = useState(false);

    if (!draft) return null;

    const decided = draft.decision && draft.decision !== 'Pending';

    const submit = (decision) => {
        setProcessing(true);

        router.post(
            route('ai.interactions.decide', draft.interaction_id),
            { decision, reason: decision === 'reject' ? reason : undefined, category },
            {
                preserveScroll: true,
                onFinish: () => {
                    setProcessing(false);
                    setRejecting(false);
                    onDecided?.(decision);
                    onClose?.();
                },
            },
        );
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <div className="p-6">
                <div className="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 pb-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <span className="rounded-full bg-[var(--color-primary)] px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-white">
                                AI draft
                            </span>
                            <h2 className="text-lg font-semibold text-[var(--color-text-primary)]">
                                Suggestion for your review
                            </h2>
                        </div>
                        <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                            Nothing is saved until you accept it. {draft.model} · prompt v{draft.prompt_version}
                        </p>
                    </div>
                    <ConfidenceBadge value={draft.confidence} low={draft.low_confidence} />
                </div>

                {draft.insufficient_context ? (
                    <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4">
                        <p className="text-sm font-semibold text-amber-900">
                            I don&rsquo;t have enough information in your data to answer that.
                        </p>
                        <p className="mt-1 text-xs text-amber-800">
                            Nothing you can open covers this. Adding the relevant records, or asking someone with
                            wider access, will get you a grounded answer — this assistant will not guess.
                        </p>
                    </div>
                ) : (
                    <div className="mt-4 max-h-[45vh] overflow-y-auto pr-1">
                        <DraftBody content={draft.content} />
                    </div>
                )}

                <div className="mt-5 rounded-lg bg-gray-50 p-3">
                    <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                        Grounded in
                    </p>
                    <Citations citations={draft.citations} />
                </div>

                {rejecting && (
                    <div className="mt-5 space-y-3 rounded-lg border border-gray-200 p-4">
                        <div>
                            <label className="form-label" htmlFor="ai-reject-category">
                                What was wrong with it?
                            </label>
                            <select
                                id="ai-reject-category"
                                className="form-select"
                                value={category}
                                onChange={(e) => setCategory(e.target.value)}
                            >
                                {REJECT_CATEGORIES.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="form-label" htmlFor="ai-reject-reason">
                                Tell us more <span className="text-red-500">*</span>
                            </label>
                            <textarea
                                id="ai-reject-reason"
                                className="form-input"
                                rows={3}
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                                placeholder="A sentence is enough. This is what improves the next version."
                            />
                        </div>
                    </div>
                )}

                <div className="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-4">
                    <p className="text-xs text-[var(--color-text-secondary)]">
                        {draft.tokens?.toLocaleString()} tokens · {draft.latency_ms}ms
                    </p>

                    <div className="flex flex-wrap gap-2">
                        <SecondaryButton onClick={onClose} disabled={processing}>
                            Close
                        </SecondaryButton>

                        {!decided && !draft.insufficient_context && (
                            <>
                                {rejecting ? (
                                    <DangerButton
                                        onClick={() => submit('reject')}
                                        disabled={processing || reason.trim().length < 5}
                                    >
                                        Confirm rejection
                                    </DangerButton>
                                ) : (
                                    <SecondaryButton onClick={() => setRejecting(true)} disabled={processing}>
                                        Reject
                                    </SecondaryButton>
                                )}

                                {!rejecting && (
                                    <PrimaryButton onClick={() => submit('accept')} disabled={processing}>
                                        Accept as draft
                                    </PrimaryButton>
                                )}
                            </>
                        )}

                        {decided && (
                            <span className="self-center text-sm font-medium text-[var(--color-text-secondary)]">
                                Already {draft.decision.toLowerCase()}
                            </span>
                        )}
                    </div>
                </div>
            </div>
        </Modal>
    );
}
