import AiReviewPanel from '@/Components/AiReviewPanel';
import { usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * The Assist affordance (§C.9).
 *
 * Sits next to the field or record it helps with rather than in a separate
 * AI area users have to remember to visit. It renders nothing at all when
 * the capability is off for the tenant or the user lacks the underlying
 * domain permission — the button and the gate read from the same source, so
 * an affordance never appears for something the request would refuse.
 *
 * Posts with fetch rather than Inertia on purpose: the user is usually
 * mid-edit on a form, and a full page visit would throw their work away.
 */
export default function AiAssistButton({
    capability,
    subjectType = null,
    subjectId = null,
    payload = {},
    label = 'Assist',
    className = '',
    onAccepted = null,
}) {
    const { auth, features } = usePage().props;
    const [draft, setDraft] = useState(null);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    const enabled =
        (features ?? []).includes('ai-assist') && (auth?.permissions ?? []).includes('use ai');

    if (!enabled) return null;

    const run = async () => {
        setLoading(true);
        setError(null);

        try {
            const response = await fetch(route('ai.assist'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    capability,
                    subject_type: subjectType,
                    subject_id: subjectId,
                    ...payload,
                }),
            });

            const body = await response.json();

            if (!response.ok) {
                // The server sends fixed, safe text — never a provider
                // response body. Render it as-is.
                setError(body.message ?? 'The assistant could not complete that request.');
                return;
            }

            setDraft(body.draft);
            setOpen(true);
        } catch {
            setError('Could not reach the assistant. Check your connection and try again.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <>
            <div className={`inline-flex flex-col items-start gap-1 ${className}`}>
                <button
                    type="button"
                    onClick={run}
                    disabled={loading}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-[var(--color-accent)] bg-white px-3 py-1.5 text-xs font-semibold text-[var(--color-primary)] transition-colors duration-200 hover:bg-[var(--color-accent)]/10 disabled:cursor-not-allowed disabled:opacity-60"
                    title="Draft a suggestion for you to review. Nothing is saved until you accept it."
                >
                    <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z"
                        />
                    </svg>
                    {loading ? 'Drafting…' : label}
                </button>

                {error && <p className="max-w-xs text-xs text-[var(--color-error)]">{error}</p>}
            </div>

            <AiReviewPanel
                draft={draft}
                show={open}
                onClose={() => setOpen(false)}
                onDecided={(decision) => decision === 'accept' && onAccepted?.(draft)}
            />
        </>
    );
}
