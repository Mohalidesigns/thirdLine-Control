import Countdown from '@/Components/Countdown';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import RelationshipsPanel from '@/Components/RelationshipsPanel';
import SeverityBadge from '@/Components/SeverityBadge';
import StatusBadge from '@/Components/StatusBadge';
import VerificationBadge from '@/Components/VerificationBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDateTime } from '@/utils';
import RichTextViewer from '@/Components/RichTextViewer';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Show({ incident, links = [], users = [], controls = [], actionTypes = [], can = {} }) {
    const [notifying, setNotifying] = useState(null);
    const [addingAction, setAddingAction] = useState(false);

    const notification = useForm({ notification_reference: '', notes: '' });
    const action = useForm({
        action_type: 'corrective',
        title: '',
        description: '',
        owner_id: '',
        due_at: '',
        is_mandatory: true,
    });

    const submitNotification = (e) => {
        e.preventDefault();
        notification.post(route('incidents.notifications.record', [incident.id, notifying.id]), {
            preserveScroll: true,
            onSuccess: () => {
                notification.reset();
                setNotifying(null);
            },
        });
    };

    const submitAction = (e) => {
        e.preventDefault();
        action.post(route('incidents.actions.store', incident.id), {
            preserveScroll: true,
            onSuccess: () => {
                action.reset();
                setAddingAction(false);
            },
        });
    };

    const openMandatory = (incident.actions ?? []).filter(
        (a) => a.is_mandatory && !['Completed', 'Verified', 'Cancelled'].includes(a.status),
    ).length;
    const outstanding = (incident.notifications ?? []).filter(
        (n) => n.is_required && n.status === 'Pending',
    ).length;

    return (
        <AuthenticatedLayout header={incident.incident_ref}>
            <Head title={incident.title} />

            <PageHeader
                title={incident.title}
                subtitle={`${incident.incident_ref} · ${incident.incident_type?.replace('_', ' ')}${incident.near_miss ? ' · near miss' : ''}`}
                breadcrumbs={[{ label: 'Incidents', href: route('incidents.index') }, { label: incident.incident_ref }]}
                actions={
                    <>
                        <SeverityBadge severity={incident.severity} />
                        <StatusBadge status={incident.status} />
                        {can.investigate && ['Triaged', 'Reopened'].includes(incident.status) && (
                            <button
                                className="btn-secondary"
                                onClick={() =>
                                    router.post(route('incidents.progress', incident.id), { action: 'investigate' }, { preserveScroll: true })
                                }
                            >
                                Open investigation
                            </button>
                        )}
                        {can.close && (
                            <button
                                className="btn-primary"
                                disabled={openMandatory > 0 || outstanding > 0}
                                title={
                                    openMandatory > 0 || outstanding > 0
                                        ? 'Settle the open mandatory actions and regulatory notifications first.'
                                        : undefined
                                }
                                onClick={() => router.post(route('incidents.close', incident.id), {}, { preserveScroll: true })}
                            >
                                Close incident
                            </button>
                        )}
                    </>
                }
            />

            {(openMandatory > 0 || outstanding > 0) && incident.status !== 'Closed' && (
                <div className="mb-6 rounded-lg border-l-4 border-l-[var(--color-warning)] bg-orange-50 p-4 text-sm">
                    <p className="font-semibold text-[var(--color-text-primary)]">This incident cannot be closed yet</p>
                    <ul className="mt-1 list-inside list-disc text-[var(--color-text-secondary)]">
                        {openMandatory > 0 && <li>{openMandatory} mandatory action(s) still open</li>}
                        {outstanding > 0 && <li>{outstanding} regulatory notification(s) outstanding</li>}
                    </ul>
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <div className="card">
                        <div className="card-header">Regulatory notification</div>
                        <div className="card-body space-y-4">
                            {(incident.notifications ?? []).length === 0 ? (
                                <p className="text-sm text-gray-400">
                                    No obligation in the register requires a notification for this incident type.
                                </p>
                            ) : (
                                (incident.notifications ?? []).map((row) => (
                                    <div key={row.id} className="rounded-lg border border-gray-200 p-4">
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <p className="font-semibold text-[var(--color-text-primary)]">
                                                    {row.regulator?.name ?? 'Regulator'}
                                                </p>
                                                <p className="text-xs text-gray-400">
                                                    <span className="font-mono">{row.obligation?.obligation_ref}</span>
                                                    {row.obligation?.legal_reference
                                                        ? ` · ${row.obligation.legal_reference}`
                                                        : ''}
                                                </p>
                                                <p className="mt-1 text-sm text-[var(--color-text-secondary)]">
                                                    {row.obligation?.title}
                                                </p>
                                                <div className="mt-2 flex items-center gap-2">
                                                    <StatusBadge status={row.status} />
                                                    <VerificationBadge status={row.verification_status} />
                                                </div>
                                            </div>

                                            <div className="text-right">
                                                {row.status === 'Pending' ? (
                                                    <Countdown dueAt={row.due_at} />
                                                ) : (
                                                    <div>
                                                        <p className="text-xs uppercase tracking-wide text-gray-400">
                                                            {row.status}
                                                        </p>
                                                        <p className="font-mono text-sm">
                                                            {row.notification_reference ?? '—'}
                                                        </p>
                                                    </div>
                                                )}
                                                <p className="mt-1 text-xs text-gray-400">
                                                    Due {formatDateTime(row.due_at)}
                                                </p>
                                                {can.notify && row.status === 'Pending' && (
                                                    <button
                                                        className="btn-primary mt-2"
                                                        onClick={() => setNotifying(row)}
                                                    >
                                                        Record notification
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                ))
                            )}
                            <p className="text-xs text-gray-400">
                                Windows are read from the obligation register, never from the application code —
                                editing an obligation moves every countdown here.
                            </p>
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header flex items-center justify-between">
                            <span>Actions</span>
                            {can.update && (
                                <button className="btn-secondary" onClick={() => setAddingAction(true)}>
                                    Raise action
                                </button>
                            )}
                        </div>
                        <div className="card-body">
                            {(incident.actions ?? []).length === 0 ? (
                                <p className="text-sm text-gray-400">No actions raised yet.</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="data-table">
                                        <thead>
                                            <tr>
                                                <th>Type</th>
                                                <th>Action</th>
                                                <th>Owner</th>
                                                <th>Due</th>
                                                <th>Status</th>
                                                <th />
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {incident.actions.map((row) => (
                                                <tr key={row.id}>
                                                    <td className="capitalize">{row.action_type}</td>
                                                    <td>
                                                        {row.title}
                                                        {row.is_mandatory && (
                                                            <span className="ml-2 badge badge-status-pending text-[10px]">
                                                                Mandatory
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td>{row.owner?.name ?? '—'}</td>
                                                    <td className="text-xs">{formatDateTime(row.due_at)}</td>
                                                    <td>
                                                        <StatusBadge status={row.status} />
                                                    </td>
                                                    <td className="text-right">
                                                        {can.update && row.status === 'Open' && (
                                                            <button
                                                                className="text-xs font-semibold text-[var(--color-primary)] hover:underline"
                                                                onClick={() =>
                                                                    router.put(
                                                                        route('incidents.actions.update', [incident.id, row.id]),
                                                                        { action: 'complete' },
                                                                        { preserveScroll: true },
                                                                    )
                                                                }
                                                            >
                                                                Complete
                                                            </button>
                                                        )}
                                                        {can.update && row.status === 'Completed' && (
                                                            <button
                                                                className="text-xs font-semibold text-[var(--color-success)] hover:underline"
                                                                onClick={() =>
                                                                    router.put(
                                                                        route('incidents.actions.update', [incident.id, row.id]),
                                                                        { action: 'verify' },
                                                                        { preserveScroll: true },
                                                                    )
                                                                }
                                                            >
                                                                Verify
                                                            </button>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">Timeline</div>
                        <div className="card-body">
                            <ol className="space-y-4">
                                {(incident.timeline ?? []).map((entry) => (
                                    <li key={entry.id} className="flex gap-3">
                                        <div
                                            className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${
                                                entry.is_material
                                                    ? 'bg-[var(--color-accent)]'
                                                    : 'bg-gray-300'
                                            }`}
                                        />
                                        <div>
                                            <p className="text-sm text-[var(--color-text-primary)]">
                                                {entry.description}
                                            </p>
                                            <p className="text-xs text-gray-400">
                                                {formatDateTime(entry.occurred_at)}
                                                {entry.actor ? ` · ${entry.actor.name}` : ''}
                                                {` · ${entry.event_type}`}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                                {(incident.timeline ?? []).length === 0 && (
                                    <p className="text-sm text-gray-400">Nothing recorded yet.</p>
                                )}
                            </ol>
                        </div>
                    </div>

                    {(incident.root_cause || incident.lessons_learned) && (
                        <div className="card">
                            <div className="card-header">Root cause and lessons</div>
                            <div className="card-body space-y-3 text-sm">
                                {incident.root_cause && (
                                    <div>
                                        <p className="text-xs uppercase tracking-wide text-gray-400">Root cause</p>
                                        <RichTextViewer className="mt-1" value={incident.root_cause_rich} fallback={incident.root_cause} />
                                    </div>
                                )}
                                {(incident.contributing_factors ?? []).length > 0 && (
                                    <div>
                                        <p className="text-xs uppercase tracking-wide text-gray-400">
                                            Contributing factors
                                        </p>
                                        <ul className="mt-1 list-inside list-disc">
                                            {incident.contributing_factors.map((factor, i) => (
                                                <li key={i}>{factor}</li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                                {incident.lessons_learned && (
                                    <div>
                                        <p className="text-xs uppercase tracking-wide text-gray-400">Lessons learned</p>
                                        <RichTextViewer className="mt-1" value={incident.lessons_learned_rich} fallback={incident.lessons_learned} />
                                    </div>
                                )}
                            </div>
                        </div>
                    )}
                </div>

                <div className="space-y-6">
                    <div className="card">
                        <div className="card-header">Detail</div>
                        <div className="card-body space-y-3 text-sm">
                            <Row label="Occurred" value={formatDateTime(incident.occurred_at)} />
                            <Row label="Detected" value={formatDateTime(incident.detected_at)} />
                            <Row label="Reported" value={formatDateTime(incident.reported_at)} />
                            <Row label="Detection" value={incident.detection_method} />
                            <Row label="Entity" value={incident.entity?.name} />
                            <Row label="Process" value={incident.process?.name} />
                            <Row label="Owner" value={incident.owner?.name} />
                            <Row label="Investigator" value={incident.investigator?.name} />
                            <Row label="Basel event type" value={incident.basel_event_type} />
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">Loss</div>
                        <div className="card-body space-y-3 text-sm">
                            <Row
                                label="Net loss"
                                value={incident.net_loss?.formatted ?? <span className="text-gray-400">—</span>}
                            />
                            <p className="text-xs text-gray-400">
                                Stored as integer minor units plus an ISO-4217 code; net is derived from gross less
                                recovery.
                            </p>
                        </div>
                    </div>

                    <RelationshipsPanel type="incident" id={incident.id} links={links} />
                </div>
            </div>

            <Modal show={notifying !== null} onClose={() => setNotifying(null)} maxWidth="lg">
                <form onSubmit={submitNotification} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold">Record the regulatory notification</h2>
                    <p className="text-sm text-gray-400">
                        {notifying?.regulator?.name} · {notifying?.obligation?.obligation_ref}
                    </p>

                    <div>
                        <label className="form-label">Acknowledgement reference</label>
                        <input
                            className="form-input"
                            value={notification.data.notification_reference}
                            onChange={(e) => notification.setData('notification_reference', e.target.value)}
                            placeholder="The reference the regulator gave you"
                        />
                    </div>

                    <div>
                        <label className="form-label">Notes</label>
                        <textarea
                            className="form-input"
                            rows={3}
                            value={notification.data.notes}
                            onChange={(e) => notification.setData('notes', e.target.value)}
                        />
                    </div>

                    <div className="flex justify-end gap-2">
                        <button type="button" className="btn-secondary" onClick={() => setNotifying(null)}>
                            Cancel
                        </button>
                        <button type="submit" className="btn-primary" disabled={notification.processing}>
                            Record
                        </button>
                    </div>
                </form>
            </Modal>

            <Modal show={addingAction} onClose={() => setAddingAction(false)} maxWidth="lg">
                <form onSubmit={submitAction} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold">Raise an action</h2>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="form-label">Type</label>
                            <select
                                className="form-select"
                                value={action.data.action_type}
                                onChange={(e) => action.setData('action_type', e.target.value)}
                            >
                                {actionTypes.map((type) => (
                                    <option key={type} value={type}>
                                        {type}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="form-label">Owner</label>
                            <select
                                className="form-select"
                                value={action.data.owner_id}
                                onChange={(e) => action.setData('owner_id', e.target.value)}
                            >
                                <option value="">—</option>
                                {users.map((user) => (
                                    <option key={user.id} value={user.id}>
                                        {user.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div>
                        <label className="form-label">Title</label>
                        <input
                            className="form-input"
                            value={action.data.title}
                            onChange={(e) => action.setData('title', e.target.value)}
                        />
                    </div>

                    <div>
                        <label className="form-label">Due at</label>
                        <input
                            type="datetime-local"
                            className="form-input"
                            value={action.data.due_at}
                            onChange={(e) => action.setData('due_at', e.target.value)}
                        />
                    </div>

                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={action.data.is_mandatory}
                            onChange={(e) => action.setData('is_mandatory', e.target.checked)}
                        />
                        Mandatory — the incident cannot close until this is done
                    </label>

                    <div className="flex justify-end gap-2">
                        <button type="button" className="btn-secondary" onClick={() => setAddingAction(false)}>
                            Cancel
                        </button>
                        <button type="submit" className="btn-primary" disabled={action.processing}>
                            Raise action
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
            <span className="text-right text-sm">{value || <span className="text-gray-400">—</span>}</span>
        </div>
    );
}
