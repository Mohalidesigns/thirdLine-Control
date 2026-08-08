import StatusBadge from '@/Components/StatusBadge';
import GuestLayout from '@/Layouts/GuestLayout';
import { formatDateTime } from '@/utils';
import { Head, Link, useForm } from '@inertiajs/react';

export default function Status({ result = null, notFound = false }) {
    const lookup = useForm({ token: '' });
    const reply = useForm({ token: '', message: '' });

    const check = (e) => {
        e.preventDefault();
        lookup.post(route('whistleblowing.check'), { preserveScroll: true });
    };

    const send = (e) => {
        e.preventDefault();
        reply.transform((data) => ({ ...data, token: lookup.data.token }));
        reply.post(route('whistleblowing.reply'), {
            preserveScroll: true,
            onSuccess: () => reply.reset('message'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Check a report" />

            <div className="w-full max-w-xl space-y-6">
                <div className="card">
                    <div className="card-body space-y-4">
                        <h1 className="text-xl font-bold text-[var(--color-text-primary)]">Check a report</h1>
                        <p className="text-sm text-[var(--color-text-secondary)]">
                            Enter the reference token you were given. It does not identify you, and looking a report up
                            never reveals who filed it.
                        </p>

                        <form onSubmit={check} className="flex gap-2">
                            <input
                                className="form-input font-mono"
                                placeholder="ABC123-DEF456"
                                value={lookup.data.token}
                                onChange={(e) => lookup.setData('token', e.target.value)}
                            />
                            <button type="submit" className="btn-primary" disabled={lookup.processing}>
                                Check
                            </button>
                        </form>

                        {notFound && (
                            <p className="text-sm text-[var(--color-error)]">
                                We could not find a report for that token. Check it and try again.
                            </p>
                        )}
                    </div>
                </div>

                {result && (
                    <div className="card">
                        <div className="card-header flex items-center justify-between">
                            <span className="font-mono text-sm">{result.case_ref}</span>
                            <StatusBadge status={result.status} />
                        </div>
                        <div className="card-body space-y-4">
                            <p className="text-xs text-gray-400">
                                Received {formatDateTime(result.received_at)}
                                {result.closed_at ? ` · closed ${formatDateTime(result.closed_at)}` : ''}
                            </p>

                            <div className="space-y-3">
                                <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                                    Updates
                                </p>
                                {(result.updates ?? []).length === 0 && (
                                    <p className="text-sm text-gray-400">
                                        No updates have been shared yet. Your report is being handled.
                                    </p>
                                )}
                                {(result.updates ?? []).map((update, index) => (
                                    <div key={index} className="rounded-lg bg-gray-50 p-3">
                                        <p className="text-sm">{update.note}</p>
                                        <p className="mt-1 text-xs text-gray-400">{formatDateTime(update.at)}</p>
                                    </div>
                                ))}
                            </div>

                            <form onSubmit={send} className="space-y-2 border-t border-gray-100 pt-4">
                                <label className="form-label">Add more information</label>
                                <textarea
                                    className="form-input"
                                    rows={3}
                                    value={reply.data.message}
                                    onChange={(e) => reply.setData('message', e.target.value)}
                                    placeholder="Anything else the investigators should know."
                                />
                                <div className="flex justify-end">
                                    <button type="submit" className="btn-primary" disabled={reply.processing}>
                                        Send
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}

                <p className="text-center text-sm">
                    <Link href={route('whistleblowing.create')} className="text-white/80 hover:underline">
                        Raise a new concern
                    </Link>
                </p>
            </div>
        </GuestLayout>
    );
}
