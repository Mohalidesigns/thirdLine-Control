import Countdown from '@/Components/Countdown';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import RelationshipsPanel from '@/Components/RelationshipsPanel';
import StatusBadge from '@/Components/StatusBadge';
import VerificationBadge from '@/Components/VerificationBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDateTime } from '@/utils';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Show({
    complaint,
    links = [],
    acknowledgementObligation = null,
    resolutionDays = 14,
    rootCauses = [],
    handlers = [],
    controls = [],
    incidents = [],
    can = {},
}) {
    const [resolving, setResolving] = useState(false);

    const resolution = useForm({
        resolution_summary: '',
        resolution_type: 'upheld',
        root_cause_id: '',
        redress_amount_minor: '',
        redress_currency: 'NGN',
        customer_satisfied: true,
        linked_control_id: '',
    });

    const submitResolution = (e) => {
        e.preventDefault();
        resolution.post(route('complaints.resolve', complaint.id), {
            preserveScroll: true,
            onSuccess: () => setResolving(false),
        });
    };

    return (
        <AuthenticatedLayout header={complaint.complaint_ref}>
            <Head title={complaint.subject} />

            <PageHeader
                title={complaint.subject}
                subtitle={`${complaint.complaint_ref} · tracking ${complaint.tracking_id} · ${complaint.channel?.replace('_', ' ')}`}
                breadcrumbs={[
                    { label: 'Complaints', href: route('complaints.index') },
                    { label: complaint.complaint_ref },
                ]}
                actions={
                    <>
                        <StatusBadge status={complaint.status} />
                        {can.acknowledge && (
                            <button
                                className="btn-primary"
                                onClick={() =>
                                    router.post(route('complaints.acknowledge', complaint.id), {}, { preserveScroll: true })
                                }
                            >
                                Acknowledge
                            </button>
                        )}
                        {can.resolve && (
                            <button className="btn-success" onClick={() => setResolving(true)}>
                                Resolve
                            </button>
                        )}
                        {can.close && complaint.status === 'Resolved' && (
                            <button
                                className="btn-secondary"
                                onClick={() => router.post(route('complaints.close', complaint.id), {}, { preserveScroll: true })}
                            >
                                Close
                            </button>
                        )}
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
                <div className="card">
                    <div className="card-body">
                        {complaint.acknowledged_at ? (
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                                    Acknowledged
                                </p>
                                <p className="mt-1 text-sm font-semibold">
                                    {formatDateTime(complaint.acknowledged_at)}
                                </p>
                                <p className="mt-1 text-xs text-gray-400">
                                    Due {formatDateTime(complaint.acknowledgement_due_at)} · stamped server-side
                                </p>
                            </div>
                        ) : (
                            <>
                                <Countdown dueAt={complaint.acknowledgement_due_at} label="Acknowledgement due in" />
                                <p className="mt-2 text-xs text-gray-400">
                                    Window from{' '}
                                    <span className="font-mono">
                                        {acknowledgementObligation?.obligation_ref ?? 'no obligation on file'}
                                    </span>
                                    {acknowledgementObligation && (
                                        <VerificationBadge
                                            status={acknowledgementObligation.verification_status}
                                            className="ml-2"
                                        />
                                    )}
                                </p>
                            </>
                        )}
                    </div>
                </div>

                <div className="card">
                    <div className="card-body">
                        {complaint.resolved_at ? (
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                                    Resolved
                                </p>
                                <p className="mt-1 text-sm font-semibold">{formatDateTime(complaint.resolved_at)}</p>
                            </div>
                        ) : (
                            <Countdown dueAt={complaint.resolution_due_at} label="Resolution due in" />
                        )}
                        <p className="mt-2 text-xs text-gray-400">
                            {resolutionDays}-day resolution window (tenant setting — the obligation pack carries no
                            per-case window).
                        </p>
                    </div>
                </div>

                <div className={`card ${complaint.penalty_exposure_minor > 0 ? 'border-l-4 border-l-[var(--color-error)]' : ''}`}>
                    <div className="card-body">
                        <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                            Live penalty exposure
                        </p>
                        <p
                            className={`mt-1 text-2xl font-bold ${
                                complaint.penalty_exposure_minor > 0
                                    ? 'text-[var(--color-error)]'
                                    : 'text-[var(--color-success)]'
                            }`}
                        >
                            {complaint.penalty_exposure?.formatted ?? '—'}
                        </p>
                        {acknowledgementObligation?.penalty_description && (
                            <p className="mt-1 text-xs text-gray-400">
                                {acknowledgementObligation.penalty_description}
                            </p>
                        )}
                    </div>
                </div>
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <div className="card">
                        <div className="card-header">Complaint</div>
                        <div className="card-body space-y-3 text-sm">
                            <p className="whitespace-pre-line">{complaint.description || '—'}</p>
                            {complaint.resolution_summary && (
                                <div className="rounded-lg bg-gray-50 p-3">
                                    <p className="text-xs uppercase tracking-wide text-gray-400">Resolution</p>
                                    <p className="mt-1">{complaint.resolution_summary}</p>
                                    <p className="mt-1 text-xs capitalize text-gray-400">
                                        {complaint.resolution_type?.replace('_', ' ')}
                                        {complaint.root_cause ? ` · root cause: ${complaint.root_cause.name}` : ''}
                                    </p>
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">Handling trail</div>
                        <div className="card-body">
                            <ol className="space-y-4">
                                {(complaint.activities ?? []).map((entry) => (
                                    <li key={entry.id} className="flex gap-3">
                                        <div
                                            className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${
                                                entry.is_customer_visible
                                                    ? 'bg-[var(--color-accent)]'
                                                    : 'bg-gray-300'
                                            }`}
                                        />
                                        <div>
                                            <p className="text-sm">{entry.description ?? entry.activity_type}</p>
                                            <p className="text-xs text-gray-400">
                                                {formatDateTime(entry.occurred_at)}
                                                {entry.actor ? ` · ${entry.actor.name}` : ''}
                                                {entry.is_customer_visible ? ' · visible to the customer' : ''}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                                {(complaint.activities ?? []).length === 0 && (
                                    <p className="text-sm text-gray-400">Nothing recorded yet.</p>
                                )}
                            </ol>
                        </div>
                    </div>
                </div>

                <div className="space-y-6">
                    <div className="card">
                        <div className="card-header">Customer</div>
                        <div className="card-body space-y-3 text-sm">
                            <Row label="Name" value={complaint.customer_name} />
                            <Row label="Reference" value={complaint.customer_reference} />
                            <Row label="Email" value={complaint.customer_contact?.email} />
                            <Row label="Phone" value={complaint.customer_contact?.phone} />
                            <Row label="Product" value={complaint.product} />
                            <Row label="Branch" value={complaint.branch} />
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">Handling</div>
                        <div className="card-body space-y-3 text-sm">
                            <Row label="Received" value={formatDateTime(complaint.received_at)} />
                            <Row label="Handler" value={complaint.assignee?.name} />
                            <Row label="Entity" value={complaint.entity?.name} />
                            <Row label="Category" value={complaint.category?.name} />
                            <Row label="Days open" value={complaint.days_open} />
                            <Row
                                label="Linked incident"
                                value={complaint.incident ? complaint.incident.incident_ref : null}
                            />
                            <Row
                                label="Failing control"
                                value={complaint.control ? complaint.control.control_ref : null}
                            />
                        </div>
                    </div>

                    <RelationshipsPanel type="complaint" id={complaint.id} links={links} />
                </div>
            </div>

            <Modal show={resolving} onClose={() => setResolving(false)} maxWidth="lg">
                <form onSubmit={submitResolution} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold">Resolve the complaint</h2>
                    <p className="text-sm text-gray-400">
                        A root cause is mandatory — it is what feeds the control library.
                    </p>

                    <div>
                        <label className="form-label">Resolution summary</label>
                        <textarea
                            className="form-input"
                            rows={3}
                            value={resolution.data.resolution_summary}
                            onChange={(e) => resolution.setData('resolution_summary', e.target.value)}
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="form-label">Outcome</label>
                            <select
                                className="form-select"
                                value={resolution.data.resolution_type}
                                onChange={(e) => resolution.setData('resolution_type', e.target.value)}
                            >
                                {['upheld', 'partially_upheld', 'not_upheld', 'withdrawn'].map((type) => (
                                    <option key={type} value={type}>
                                        {type.replace('_', ' ')}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="form-label">Root cause</label>
                            <select
                                className="form-select"
                                value={resolution.data.root_cause_id}
                                onChange={(e) => resolution.setData('root_cause_id', e.target.value)}
                            >
                                <option value="">Select…</option>
                                {rootCauses.map((cause) => (
                                    <option key={cause.id} value={cause.id}>
                                        {cause.name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="form-label">Redress (minor units)</label>
                            <input
                                type="number"
                                min="0"
                                className="form-input"
                                value={resolution.data.redress_amount_minor}
                                onChange={(e) => resolution.setData('redress_amount_minor', e.target.value)}
                            />
                        </div>

                        <div>
                            <label className="form-label">Failing control</label>
                            <select
                                className="form-select"
                                value={resolution.data.linked_control_id}
                                onChange={(e) => resolution.setData('linked_control_id', e.target.value)}
                            >
                                <option value="">—</option>
                                {controls.map((control) => (
                                    <option key={control.id} value={control.id}>
                                        {control.control_ref} — {control.title}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div className="flex justify-end gap-2">
                        <button type="button" className="btn-secondary" onClick={() => setResolving(false)}>
                            Cancel
                        </button>
                        <button type="submit" className="btn-primary" disabled={resolution.processing}>
                            Resolve
                        </button>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}

function Row({ label, value }) {
    return (
        <div className="flex items-start justify-between gap-4">
            <span className="text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">{label}</span>
            <span className="text-right text-sm">
                {value !== null && value !== undefined && value !== '' ? value : <span className="text-gray-400">—</span>}
            </span>
        </div>
    );
}
