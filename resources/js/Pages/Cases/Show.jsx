import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import RelationshipsPanel from '@/Components/RelationshipsPanel';
import SeverityBadge from '@/Components/SeverityBadge';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate, formatDateTime } from '@/utils';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Show({
    case: investigationCase,
    notes = [],
    links = [],
    allowlist = [],
    users = [],
    canSeePrivileged = false,
    can = {},
}) {
    const [concluding, setConcluding] = useState(false);

    const note = useForm({ note: '', is_privileged: false, is_reporter_visible: false });
    const conclusion = useForm({
        outcome: 'Substantiated',
        findings: '',
        conclusion: '',
        actions_taken: '',
        referred_to: '',
    });
    const access = useForm({ user_id: '' });

    const submitNote = (e) => {
        e.preventDefault();
        note.post(route('cases.notes.store', investigationCase.id), {
            preserveScroll: true,
            onSuccess: () => note.reset(),
        });
    };

    const submitConclusion = (e) => {
        e.preventDefault();
        conclusion.post(route('cases.conclude', investigationCase.id), {
            preserveScroll: true,
            onSuccess: () => setConcluding(false),
        });
    };

    return (
        <AuthenticatedLayout header={investigationCase.case_ref}>
            <Head title={investigationCase.case_ref} />

            <PageHeader
                title={investigationCase.title}
                subtitle={`${investigationCase.case_ref} · ${investigationCase.case_type?.replace(/_/g, ' ')} · ${investigationCase.confidentiality}`}
                breadcrumbs={[{ label: 'Cases', href: route('cases.index') }, { label: investigationCase.case_ref }]}
                actions={
                    <>
                        <SeverityBadge severity={investigationCase.severity} />
                        <StatusBadge status={investigationCase.status} />
                        {can.conclude && (
                            <button className="btn-primary" onClick={() => setConcluding(true)}>
                                Conclude
                            </button>
                        )}
                        {can.close && (
                            <button
                                className="btn-secondary"
                                onClick={() => router.post(route('cases.close', investigationCase.id), {}, { preserveScroll: true })}
                            >
                                Close
                            </button>
                        )}
                    </>
                }
            />

            {investigationCase.is_anonymous && (
                <div className="mb-6 rounded-lg border-l-4 border-l-[var(--color-accent)] bg-amber-50 p-4 text-sm">
                    <p className="font-semibold text-[var(--color-text-primary)]">Anonymous report</p>
                    <p className="mt-1 text-[var(--color-text-secondary)]">
                        No reporter identity was stored and none can be recovered. The reporter follows progress with a
                        one-way token; notes marked visible to the reporter are the only thing they see.
                        {investigationCase.was_anonymised && ` ${investigationCase.anonymisation_note}`}
                    </p>
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <div className="card">
                        <div className="card-header">The concern</div>
                        <div className="card-body">
                            <p className="whitespace-pre-line text-sm">{investigationCase.description || '—'}</p>
                        </div>
                    </div>

                    {investigationCase.investigation_plan && (
                        <div className="card">
                            <div className="card-header">Investigation plan</div>
                            <div className="card-body">
                                <p className="whitespace-pre-line text-sm">{investigationCase.investigation_plan}</p>
                            </div>
                        </div>
                    )}

                    {(investigationCase.findings || investigationCase.conclusion) && (
                        <div className="card">
                            <div className="card-header">Outcome</div>
                            <div className="card-body space-y-3 text-sm">
                                {investigationCase.findings && (
                                    <div>
                                        <p className="text-xs uppercase tracking-wide text-gray-400">Findings</p>
                                        <p className="mt-1 whitespace-pre-line">{investigationCase.findings}</p>
                                    </div>
                                )}
                                {investigationCase.conclusion && (
                                    <div>
                                        <p className="text-xs uppercase tracking-wide text-gray-400">Conclusion</p>
                                        <p className="mt-1 whitespace-pre-line">{investigationCase.conclusion}</p>
                                    </div>
                                )}
                                {investigationCase.actions_taken && (
                                    <div>
                                        <p className="text-xs uppercase tracking-wide text-gray-400">Actions taken</p>
                                        <p className="mt-1 whitespace-pre-line">{investigationCase.actions_taken}</p>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    <div className="card">
                        <div className="card-header">
                            Notes
                            {!canSeePrivileged && (
                                <span className="ml-2 text-xs font-normal text-gray-400">
                                    (privileged notes are withheld)
                                </span>
                            )}
                        </div>
                        <div className="card-body space-y-4">
                            {notes.length === 0 && <p className="text-sm text-gray-400">No notes yet.</p>}

                            {notes.map((row) => (
                                <div
                                    key={row.id}
                                    className={`rounded-lg p-3 ${
                                        row.is_privileged ? 'border border-dashed border-[var(--color-warning)] bg-orange-50' : 'bg-gray-50'
                                    }`}
                                >
                                    <p className="whitespace-pre-line text-sm">{row.note}</p>
                                    <p className="mt-1 text-xs text-gray-400">
                                        {row.author?.name ?? 'Anonymous reporter'} · {formatDateTime(row.created_at)}
                                        {row.is_privileged ? ' · privileged' : ''}
                                        {row.is_reporter_visible ? ' · visible to the reporter' : ''}
                                    </p>
                                </div>
                            ))}

                            {can.investigate && (
                                <form onSubmit={submitNote} className="space-y-3 border-t border-gray-100 pt-4">
                                    <textarea
                                        className="form-input"
                                        rows={3}
                                        placeholder="Add a note…"
                                        value={note.data.note}
                                        onChange={(e) => note.setData('note', e.target.value)}
                                    />
                                    <div className="flex flex-wrap items-center gap-4">
                                        <label className="flex items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                checked={note.data.is_privileged}
                                                onChange={(e) => {
                                                    note.setData('is_privileged', e.target.checked);
                                                    if (e.target.checked) note.setData('is_reporter_visible', false);
                                                }}
                                            />
                                            Privileged
                                        </label>
                                        <label className="flex items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                disabled={note.data.is_privileged}
                                                checked={note.data.is_reporter_visible}
                                                onChange={(e) => note.setData('is_reporter_visible', e.target.checked)}
                                            />
                                            Visible to the reporter
                                        </label>
                                        <button type="submit" className="btn-primary ml-auto" disabled={note.processing}>
                                            Add note
                                        </button>
                                    </div>
                                </form>
                            )}
                        </div>
                    </div>
                </div>

                <div className="space-y-6">
                    <div className="card">
                        <div className="card-header">Detail</div>
                        <div className="card-body space-y-3 text-sm">
                            <Row label="Received" value={formatDate(investigationCase.received_at)} />
                            <Row label="Channel" value={investigationCase.channel} />
                            <Row label="Entity" value={investigationCase.entity?.name} />
                            <Row label="Lead investigator" value={investigationCase.lead_investigator?.name} />
                            <Row label="Referred to" value={investigationCase.referred_to} />
                            <Row label="Closed" value={formatDateTime(investigationCase.closed_at)} />
                            <Row
                                label="Related incident"
                                value={investigationCase.incident?.incident_ref}
                            />
                            <Row
                                label="Related complaint"
                                value={investigationCase.complaint?.complaint_ref}
                            />
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">Who may see this case</div>
                        <div className="card-body space-y-3">
                            <ul className="space-y-1 text-sm">
                                {allowlist.map((user) => (
                                    <li key={user.id} className="flex items-center justify-between gap-2">
                                        <span>{user.name}</span>
                                        {can.manage_access && user.id !== investigationCase.lead_investigator_id && (
                                            <button
                                                className="text-xs text-[var(--color-error)] hover:underline"
                                                onClick={() =>
                                                    router.delete(route('cases.access.revoke', investigationCase.id), {
                                                        data: { user_id: user.id },
                                                        preserveScroll: true,
                                                    })
                                                }
                                            >
                                                Remove
                                            </button>
                                        )}
                                    </li>
                                ))}
                                {allowlist.length === 0 && <li className="text-gray-400">Nobody else.</li>}
                            </ul>

                            {can.manage_access && (
                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        access.post(route('cases.access.grant', investigationCase.id), {
                                            preserveScroll: true,
                                            onSuccess: () => access.reset(),
                                        });
                                    }}
                                    className="flex gap-2"
                                >
                                    <select
                                        className="form-select"
                                        value={access.data.user_id}
                                        onChange={(e) => access.setData('user_id', e.target.value)}
                                    >
                                        <option value="">Grant access to…</option>
                                        {users.map((user) => (
                                            <option key={user.id} value={user.id}>
                                                {user.name}
                                            </option>
                                        ))}
                                    </select>
                                    <button type="submit" className="btn-secondary" disabled={access.processing}>
                                        Add
                                    </button>
                                </form>
                            )}

                            <p className="text-xs text-gray-400">Every grant and every file open is logged.</p>
                        </div>
                    </div>

                    <RelationshipsPanel type="case" id={investigationCase.id} links={links} />
                </div>
            </div>

            <Modal show={concluding} onClose={() => setConcluding(false)} maxWidth="lg">
                <form onSubmit={submitConclusion} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold">Conclude the investigation</h2>

                    <div>
                        <label className="form-label">Outcome</label>
                        <select
                            className="form-select"
                            value={conclusion.data.outcome}
                            onChange={(e) => conclusion.setData('outcome', e.target.value)}
                        >
                            {['Substantiated', 'Unsubstantiated', 'Referred'].map((outcome) => (
                                <option key={outcome} value={outcome}>
                                    {outcome}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div>
                        <label className="form-label">Findings</label>
                        <textarea
                            className="form-input"
                            rows={4}
                            value={conclusion.data.findings}
                            onChange={(e) => conclusion.setData('findings', e.target.value)}
                        />
                    </div>

                    <div>
                        <label className="form-label">Conclusion</label>
                        <textarea
                            className="form-input"
                            rows={3}
                            value={conclusion.data.conclusion}
                            onChange={(e) => conclusion.setData('conclusion', e.target.value)}
                        />
                    </div>

                    {conclusion.data.outcome === 'Referred' && (
                        <div>
                            <label className="form-label">Referred to</label>
                            <input
                                className="form-input"
                                value={conclusion.data.referred_to}
                                onChange={(e) => conclusion.setData('referred_to', e.target.value)}
                            />
                        </div>
                    )}

                    <div className="flex justify-end gap-2">
                        <button type="button" className="btn-secondary" onClick={() => setConcluding(false)}>
                            Cancel
                        </button>
                        <button type="submit" className="btn-primary" disabled={conclusion.processing}>
                            Conclude
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
