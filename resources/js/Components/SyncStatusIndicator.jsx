import { acknowledgeIssue, subscribeOutbox, syncNow } from '@/offline/outbox';
import { formatDateTime } from '@/utils';
import { useEffect, useState } from 'react';

/**
 * Visible sync status (15.1): pending outbox count, last sync time, and
 * the "server changed this" / rejection prompts a replay produced. Never
 * silent — a queued action that could not apply is shown with the
 * server's reason until the user acknowledges it.
 */
export default function SyncStatusIndicator() {
    const [status, setStatus] = useState({ pending: 0, syncing: false, lastSyncAt: null, issues: [] });
    const [open, setOpen] = useState(false);

    useEffect(() => subscribeOutbox(setStatus), []);

    if (status.pending === 0 && status.issues.length === 0 && !status.syncing) return null;

    return (
        <>
            <button
                type="button"
                onClick={() => (status.issues.length > 0 ? setOpen(true) : syncNow())}
                className={`fixed bottom-4 right-4 z-[60] flex min-h-[44px] items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold text-white shadow-lg ${
                    status.issues.length > 0 ? 'bg-[var(--color-error)]' : 'bg-[var(--color-primary)]'
                }`}
                aria-live="polite"
            >
                {status.syncing ? (
                    <span className="inline-block h-3 w-3 animate-spin rounded-full border-2 border-white border-t-transparent" />
                ) : (
                    <span className="inline-block h-2.5 w-2.5 rounded-full bg-[var(--color-accent)]" />
                )}
                {status.issues.length > 0
                    ? `${status.issues.length} sync issue${status.issues.length > 1 ? 's' : ''}`
                    : status.syncing
                      ? 'Syncing…'
                      : `${status.pending} pending — tap to sync`}
            </button>

            {open && (
                <div className="fixed inset-0 z-[70] flex items-end justify-center bg-black/40 p-4 sm:items-center" role="dialog" aria-modal="true">
                    <div className="max-h-[80vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-5 shadow-xl">
                        <h3 className="mb-1 text-base font-semibold text-gray-900">Sync results</h3>
                        <p className="mb-4 text-xs text-gray-500">
                            Last sync: {status.lastSyncAt ? formatDateTime(status.lastSyncAt) : 'never'}
                        </p>

                        {status.issues.map((issue) => (
                            <div key={issue.client_id} className="mb-3 rounded-lg border border-gray-200 p-3">
                                <p className="text-sm font-semibold text-gray-900">
                                    {issue.status === 'conflict' ? 'The server changed this record' : 'Action rejected'}
                                    <span className="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium text-gray-600">
                                        {issue.action?.type}
                                    </span>
                                </p>
                                <p className="mt-1 text-sm text-gray-600">{issue.reason}</p>
                                {issue.status === 'conflict' && (
                                    <p className="mt-1 text-xs text-gray-500">
                                        Your offline change was NOT applied. Review the current server version, then redo the change if it still applies.
                                    </p>
                                )}
                                <button
                                    type="button"
                                    className="btn-secondary mt-2 min-h-[44px] text-sm"
                                    onClick={() => {
                                        acknowledgeIssue(issue.client_id);
                                        setStatus((s) => ({ ...s, issues: s.issues.filter((i) => i.client_id !== issue.client_id) }));
                                    }}
                                >
                                    Acknowledge
                                </button>
                            </div>
                        ))}

                        {status.issues.length === 0 && <p className="text-sm text-gray-500">No outstanding issues.</p>}

                        <button type="button" className="btn-primary mt-2 min-h-[44px] w-full" onClick={() => setOpen(false)}>
                            Close
                        </button>
                    </div>
                </div>
            )}
        </>
    );
}
