import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/**
 * Atlas — the conversational assistant drawer (§C.6 #9).
 *
 * The conversation lives here in component state and nowhere else. Every
 * individual exchange is already audited in ai_interactions, so persisting a
 * transcript server-side would create a second store of what people asked,
 * with its own retention question and no governance benefit.
 *
 * The state that matters most is the empty one. When retrieval finds nothing
 * the user can read, the answer renders as an explicit "I don't have that",
 * styled as its own thing — never as prose that could be mistaken for an
 * answer.
 */
export default function AtlasChat() {
    const { auth, features } = usePage().props;
    const [open, setOpen] = useState(false);
    const [question, setQuestion] = useState('');
    const [turns, setTurns] = useState([]);
    const [loading, setLoading] = useState(false);
    const endRef = useRef(null);

    const enabled = (features ?? []).includes('ai-atlas') && (auth?.permissions ?? []).includes('use ai');

    useEffect(() => {
        endRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [turns, loading]);

    if (!enabled) return null;

    const ask = async (e) => {
        e.preventDefault();

        const asked = question.trim();
        if (!asked || loading) return;

        const history = turns.map((turn) => ({ role: turn.role, content: turn.content ?? turn.answer ?? '' }));

        setTurns((prev) => [...prev, { role: 'user', content: asked }]);
        setQuestion('');
        setLoading(true);

        try {
            const response = await fetch(route('ai.atlas'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ capability: 'atlas.chat', question: asked, conversation: history }),
            });

            const body = await response.json();

            setTurns((prev) => [
                ...prev,
                response.ok
                    ? { role: 'assistant', ...body }
                    : { role: 'assistant', error: body.message ?? 'Atlas could not answer that.' },
            ]);
        } catch {
            setTurns((prev) => [
                ...prev,
                { role: 'assistant', error: 'Could not reach Atlas. Check your connection and try again.' },
            ]);
        } finally {
            setLoading(false);
        }
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="fixed bottom-5 right-5 z-40 flex h-12 w-12 items-center justify-center rounded-full bg-[var(--color-primary)] text-white shadow-lg transition-all duration-200 hover:bg-[var(--color-primary-light)] hover:shadow-xl"
                aria-label="Ask Atlas"
                title="Ask Atlas about your control environment"
            >
                <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
                    <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"
                    />
                </svg>
            </button>

            {open && (
                <div className="fixed inset-0 z-50 flex justify-end" role="dialog" aria-label="Atlas assistant">
                    <button
                        type="button"
                        className="flex-1 bg-black/20"
                        onClick={() => setOpen(false)}
                        aria-label="Close Atlas"
                    />

                    <div className="flex h-full w-full max-w-md flex-col bg-white shadow-xl sm:w-[26rem]">
                        <header className="flex items-start justify-between border-b border-gray-100 px-5 py-4">
                            <div>
                                <h2 className="text-base font-semibold text-[var(--color-text-primary)]">Atlas</h2>
                                <p className="mt-0.5 text-xs text-[var(--color-text-secondary)]">
                                    Answers from your own records, and only the ones you can open.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setOpen(false)}
                                className="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                                aria-label="Close"
                            >
                                <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </header>

                        <div className="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                            {turns.length === 0 && (
                                <div className="rounded-lg bg-gray-50 p-4 text-sm text-[var(--color-text-secondary)]">
                                    <p className="font-medium text-[var(--color-text-primary)]">
                                        Ask about your controls, risks, policies, obligations or incidents.
                                    </p>
                                    <p className="mt-2 text-xs">
                                        Atlas cites the records it used, and says so plainly when your data
                                        doesn&rsquo;t answer the question rather than guessing.
                                    </p>
                                </div>
                            )}

                            {turns.map((turn, index) =>
                                turn.role === 'user' ? (
                                    <div key={index} className="flex justify-end">
                                        <p className="max-w-[85%] rounded-2xl rounded-br-sm bg-[var(--color-primary)] px-3.5 py-2 text-sm text-white">
                                            {turn.content}
                                        </p>
                                    </div>
                                ) : (
                                    <AtlasAnswer key={index} turn={turn} />
                                ),
                            )}

                            {loading && (
                                <p className="text-xs italic text-[var(--color-text-secondary)]">Atlas is looking…</p>
                            )}

                            <div ref={endRef} />
                        </div>

                        <form onSubmit={ask} className="border-t border-gray-100 p-4">
                            <div className="flex gap-2">
                                <input
                                    type="text"
                                    className="form-input flex-1 text-sm"
                                    placeholder="Which key controls have no test this quarter?"
                                    value={question}
                                    onChange={(e) => setQuestion(e.target.value)}
                                    disabled={loading}
                                />
                                <button
                                    type="submit"
                                    disabled={loading || !question.trim()}
                                    className="btn-primary px-4 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    Ask
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </>
    );
}

function AtlasAnswer({ turn }) {
    if (turn.error) {
        return (
            <div className="rounded-2xl rounded-bl-sm border border-red-100 bg-red-50 px-3.5 py-2.5">
                <p className="text-sm text-red-800">{turn.error}</p>
            </div>
        );
    }

    // The explicit "I don't have that". Its own state, never prose.
    if (turn.insufficient_context) {
        return (
            <div className="rounded-2xl rounded-bl-sm border border-amber-200 bg-amber-50 px-3.5 py-2.5">
                <p className="text-sm font-medium text-amber-900">
                    I don&rsquo;t have enough information in your data to answer that.
                </p>
                <p className="mt-1 text-xs text-amber-800">
                    Nothing you can open covers it. Someone with wider access may be able to, or the records may
                    not exist yet — I won&rsquo;t guess.
                </p>
            </div>
        );
    }

    return (
        <div className="rounded-2xl rounded-bl-sm bg-gray-50 px-3.5 py-2.5">
            <p className="whitespace-pre-wrap text-sm text-[var(--color-text-primary)]">{turn.answer}</p>

            {turn.low_confidence && (
                <p className="mt-2 text-xs text-[var(--color-warning)]">
                    Low confidence — check the cited records before relying on this.
                </p>
            )}

            {!!turn.citations?.length && (
                <ul className="mt-2.5 space-y-1 border-t border-gray-200 pt-2">
                    {turn.citations.map((citation) => (
                        <li key={`${citation.record_type}-${citation.record_id}`} className="text-xs">
                            {citation.route ? (
                                <Link
                                    href={route(citation.route, citation.record_id)}
                                    className="font-mono text-[var(--color-primary)] hover:underline"
                                >
                                    {citation.reference || `${citation.label} #${citation.record_id}`}
                                </Link>
                            ) : (
                                <span className="font-mono text-gray-600">
                                    {citation.reference || `${citation.label} #${citation.record_id}`}
                                </span>
                            )}
                            {citation.title && <span className="ml-1.5 text-gray-500">{citation.title}</span>}
                        </li>
                    ))}
                </ul>
            )}

            {!!turn.suggested_actions?.length && (
                <ul className="mt-2 list-inside list-disc text-xs text-[var(--color-text-secondary)]">
                    {turn.suggested_actions.map((action, i) => (
                        <li key={i}>{action}</li>
                    ))}
                </ul>
            )}
        </div>
    );
}
