import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDateTime } from '@/utils';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * The reveal approver's queue (CR): every pending break-glass request for
 * Tier 2 reporter metadata, decided here by a second person. The approver
 * sees who is asking, the case reference, the reason and the written
 * justification — never the case file and never a metadata value. A
 * request you made yourself renders without decision buttons: the service
 * refuses a self-approval regardless.
 */
export default function RevealQueue({ pending = [], decided = [], userId }) {
    return (
        <AuthenticatedLayout header="Reveal approvals">
            <Head title="Reveal approvals" />

            <PageHeader
                title="Speak Up reveal approvals"
                subtitle="Break-glass requests to see identifying reporter metadata. Approvals are second-person only and every decision is permanently logged."
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div className="card">
                    <div className="card-header">Pending</div>
                    <div className="card-body space-y-3">
                        {pending.length === 0 && <p className="text-sm text-gray-400">Nothing waiting.</p>}
                        {pending.map((request) => (
                            <PendingRequest key={request.id} request={request} own={request.requested_by === userId} />
                        ))}
                    </div>
                </div>

                <div className="card">
                    <div className="card-header">Recently decided</div>
                    <div className="card-body space-y-3 text-sm">
                        {decided.length === 0 && <p className="text-gray-400">None yet.</p>}
                        {decided.map((request) => (
                            <div key={request.id} className="rounded-lg bg-gray-50 p-3">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="font-medium">
                                        {request.report?.case_ref} · {request.requester?.name}
                                    </span>
                                    <span className="text-xs capitalize text-gray-400">{request.status}</span>
                                </div>
                                <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                                    {request.status} by {request.decider?.name ?? '—'} · {formatDateTime(request.decided_at)}
                                </p>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function PendingRequest({ request, own }) {
    const [note, setNote] = useState('');
    const form = useForm({ approve: true, note: '' });

    const decide = (approve) => {
        form.transform((data) => ({ ...data, approve, note }));
        form.post(route('speak-up.reveal-requests.decide', request.id), { preserveScroll: true });
    };

    return (
        <div className="rounded-lg border border-gray-100 p-3 text-sm">
            <div className="flex items-center justify-between gap-2">
                <span className="font-medium">
                    {request.report?.case_ref} · {request.requester?.name}
                </span>
                <span className="text-xs text-gray-400">{formatDateTime(request.created_at)}</span>
            </div>
            <p className="mt-1 text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">
                {request.reason_code?.replaceAll('_', ' ')}
            </p>
            <p className="mt-1 whitespace-pre-line text-xs">{request.justification}</p>

            {own ? (
                <p className="mt-2 rounded bg-amber-50 p-2 text-xs text-amber-700">
                    You made this request — a different approver must decide it.
                </p>
            ) : (
                <div className="mt-3 space-y-2">
                    <input
                        className="form-input text-xs"
                        placeholder="Decision note (optional)"
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                    />
                    <div className="flex justify-end gap-2">
                        <button className="btn-secondary" disabled={form.processing} onClick={() => decide(false)}>
                            Deny
                        </button>
                        <button className="btn-primary" disabled={form.processing} onClick={() => decide(true)}>
                            Approve
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
