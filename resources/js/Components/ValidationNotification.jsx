import { usePage } from '@inertiajs/react';
import { AlertTriangle, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

/**
 * The safety net under every form in the application.
 *
 * FlashNotification shows `flash.*` — session messages the server chose to
 * send. A validation failure sends none: Laravel returns 422 and Inertia
 * puts the messages in `page.props.errors`, which nothing was reading.
 *
 * The visible consequence was that a modal submitted with a missing or
 * invalid field simply sat there. No message, no close, no write. Users
 * reported it as "the modal does not save", and the audit found roughly
 * sixty modals with fields that render no <InputError> at all — so for
 * those, the errors bag was the only evidence a submission had failed and
 * nobody was showing it.
 *
 * This is deliberately a net and not a replacement. Where a form renders
 * <InputError> beside the offending field, that inline message is the
 * better one and stays; this repeats the same messages in the corner so
 * that a form which forgot to — or a field scrolled out of view inside a
 * long dialog — can never fail silently again.
 */
export default function ValidationNotification() {
    const { errors } = usePage().props;
    const [dismissed, setDismissed] = useState(null);

    // The identity of the current error set, so dismissing one failure
    // does not suppress the next.
    const signature = useMemo(() => {
        const keys = Object.keys(errors ?? {});

        return keys.length ? keys.sort().join('|') + '::' + JSON.stringify(errors) : null;
    }, [errors]);

    useEffect(() => {
        setDismissed(null);
    }, [signature]);

    if (!signature || dismissed === signature) return null;

    const messages = Object.values(errors).filter(Boolean);
    const shown = messages.slice(0, 4);
    const rest = messages.length - shown.length;

    return (
        <div className="fixed bottom-6 right-6 z-[60] max-w-sm animate-slide-in" role="alert" aria-live="assertive">
            <div className="rounded-xl border-l-4 border-[var(--color-error)] bg-red-50 px-4 py-3 text-red-800 shadow-lg">
                <div className="flex items-start gap-3">
                    <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" strokeWidth={1.8} aria-hidden="true" />
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-semibold">
                            {messages.length === 1 ? 'This could not be saved' : `${messages.length} fields need attention`}
                        </p>
                        <ul className="mt-1 space-y-0.5 text-sm">
                            {shown.map((message, index) => (
                                <li key={index}>{message}</li>
                            ))}
                            {rest > 0 && <li className="opacity-70">and {rest} more.</li>}
                        </ul>
                    </div>
                    <button
                        type="button"
                        onClick={() => setDismissed(signature)}
                        className="text-current opacity-60 hover:opacity-100"
                        aria-label="Dismiss"
                    >
                        <X className="h-4 w-4" strokeWidth={2} />
                    </button>
                </div>
            </div>
        </div>
    );
}
