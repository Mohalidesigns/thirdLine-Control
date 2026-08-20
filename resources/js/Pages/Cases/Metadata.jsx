import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import ReporterSignals from '@/Components/ReporterSignals';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDateTime } from '@/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Reporter technical metadata for one Speak Up case (CR).
 *
 * Three tabs: Tier 1 device & signals, the break-glass reveal flow, and
 * the immutable Metadata Access Log. Tier 2 renders only when the server
 * confirmed a usable approved reveal AND the viewer explicitly asked
 * (?reveal=1) — the ask is deliberate because every render is logged.
 */
export default function Metadata({
    case: reportCase,
    tier1 = null,
    signals = null,
    tier2 = null,
    purgeAfter = null,
    revealRequests = [],
    hasUsableReveal = false,
    reasonCodes = {},
    accessLog = null,
    can = {},
}) {
    const tabs = ['Device & signals', 'Identifying data', ...(can.audit_log ? ['Access log'] : [])];
    const [tab, setTab] = useState(tabs[0]);
    const [holding, setHolding] = useState(false);

    return (
        <AuthenticatedLayout header={`${reportCase.case_ref} · Reporter metadata`}>
            <Head title={`${reportCase.case_ref} metadata`} />

            <PageHeader
                title="Reporter technical metadata"
                subtitle={`${reportCase.case_ref} · ${reportCase.title}`}
                breadcrumbs={[
                    { label: 'Cases', href: route('cases.index') },
                    { label: reportCase.case_ref, href: route('cases.show', reportCase.id) },
                    { label: 'Metadata' },
                ]}
                actions={
                    can.approve_reveal &&
                    (reportCase.legal_hold ? (
                        <button
                            className="btn-secondary"
                            onClick={() => router.delete(route('cases.legal-hold.lift', reportCase.id), { preserveScroll: true })}
                        >
                            Lift legal hold
                        </button>
                    ) : (
                        <button className="btn-secondary" onClick={() => setHolding(true)}>
                            Set legal hold
                        </button>
                    ))
                }
            />

            {reportCase.legal_hold && (
                <div className="mb-6 rounded-lg border-l-4 border-l-[var(--color-warning)] bg-orange-50 p-4 text-sm">
                    <p className="font-semibold text-[var(--color-text-primary)]">Legal hold</p>
                    <p className="mt-1 text-[var(--color-text-secondary)]">
                        Metadata purge is suspended for this case
                        {reportCase.legal_hold_at ? ` since ${formatDateTime(reportCase.legal_hold_at)}` : ''}.{' '}
                        {reportCase.legal_hold_reason}
                    </p>
                </div>
            )}

            <div className="mb-4 flex gap-1 border-b border-gray-200">
                {tabs.map((label) => (
                    <button
                        key={label}
                        onClick={() => setTab(label)}
                        className={`px-4 py-2 text-sm font-medium ${
                            tab === label
                                ? 'border-b-2 border-[var(--color-primary)] text-[var(--color-primary)]'
                                : 'text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]'
                        }`}
                    >
                        {label}
                    </button>
                ))}
            </div>

            {tab === 'Device & signals' && (
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <div className="card">
                        <div className="card-header">Device & session (Tier 1 — non-identifying)</div>
                        <div className="card-body space-y-3 text-sm">
                            {tier1 ? (
                                <>
                                    <Row label="Device type" value={tier1.device_type} />
                                    <Row label="Browser" value={join(tier1.browser, tier1.browser_version)} />
                                    <Row label="Operating system" value={join(tier1.os, tier1.os_version)} />
                                    <Row label="Country / region" value={join(tier1.geo_country, tier1.geo_region, ' · ')} />
                                    <Row label="Timezone" value={tier1.timezone} />
                                    <Row
                                        label="Time on form"
                                        value={tier1.session_duration_seconds != null ? `${tier1.session_duration_seconds}s` : null}
                                    />
                                    <Row label="Authenticated staff session" value={tier1.is_authenticated ? 'Yes' : 'No'} />
                                    <Row label="Device fingerprint" value={tier1.fingerprint_short ? `${tier1.fingerprint_short}…` : null} />
                                    <Row label="Captured" value={formatDateTime(tier1.captured_at)} />
                                    <Row label="Notice version acknowledged" value={tier1.notice_version ? `v${tier1.notice_version}` : null} />
                                    <Row label="Scheduled purge" value={purgeAfter ? formatDateTime(purgeAfter) : null} />
                                </>
                            ) : (
                                <p className="text-gray-400">No metadata was captured for this report.</p>
                            )}
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">Reporter Signals</div>
                        <div className="card-body space-y-3">
                            <ReporterSignals signals={signals} />
                            <p className="text-xs text-gray-400">
                                Signals are decision support only. A signal is not evidence of a false report — every report
                                is assessed on its merits.
                            </p>
                        </div>
                    </div>
                </div>
            )}

            {tab === 'Identifying data' && (
                <IdentifyingTab
                    reportCase={reportCase}
                    tier2={tier2}
                    revealRequests={revealRequests}
                    hasUsableReveal={hasUsableReveal}
                    reasonCodes={reasonCodes}
                    can={can}
                />
            )}

            {tab === 'Access log' && <AccessLogTab accessLog={accessLog} />}

            <Modal show={holding} onClose={() => setHolding(false)} maxWidth="md">
                <LegalHoldForm reportCase={reportCase} onClose={() => setHolding(false)} />
            </Modal>
        </AuthenticatedLayout>
    );
}

function IdentifyingTab({ reportCase, tier2, revealRequests, hasUsableReveal, reasonCodes, can }) {
    const form = useForm({ reason_code: '', justification: '' });

    const submit = (e) => {
        e.preventDefault();
        form.post(route('cases.metadata.reveal.request', reportCase.id), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div className="card">
                <div className="card-header">Identifying data (Tier 2 — break-glass)</div>
                <div className="card-body space-y-3 text-sm">
                    {tier2 ? (
                        <>
                            <p className="rounded-lg bg-red-50 p-3 text-xs text-red-700">
                                This view has been written to the Metadata Access Log.
                            </p>
                            <Row label="IP address" value={tier2.ip_address} />
                            <Row label="Forwarded chain" value={tier2.ip_forwarded_chain} />
                            <Row label="Hostname" value={tier2.hostname} detail={tier2.hostname_source} />
                            <Row label="ASN" value={tier2.asn} />
                            <Row label="ISP" value={tier2.isp} />
                            <Row label="City" value={tier2.geo_city} />
                            <Row label="Device model" value={tier2.device_model} />
                            <Row label="Screen" value={tier2.screen_resolution} />
                            <Row label="Locale" value={tier2.locale} />
                            <Row label="Referrer" value={tier2.referrer} />
                            <Row label="User agent" value={tier2.user_agent_raw} />
                            <Row
                                label="Linked staff identity"
                                value={tier2.reporter ? `${tier2.reporter.name} (${tier2.reporter.email})` : null}
                            />
                        </>
                    ) : hasUsableReveal ? (
                        <div className="space-y-3">
                            <p className="text-[var(--color-text-secondary)]">
                                Your reveal is approved. Viewing writes a permanent entry in the Metadata Access Log.
                            </p>
                            <Link
                                href={route('cases.metadata.show', { case: reportCase.id, reveal: 1 })}
                                className="btn-primary inline-block"
                                preserveScroll
                            >
                                View identifying data (logged)
                            </Link>
                        </div>
                    ) : (
                        <p className="text-[var(--color-text-secondary)]">
                            Identifying fields — IP address, network, hostname, city, linked staff identity — are held in a
                            restricted vault. Access requires a reason, a written justification, and approval by a second
                            authorised officer. Every access is permanently logged.
                        </p>
                    )}
                </div>
            </div>

            <div className="space-y-6">
                {can.request_reveal && !hasUsableReveal && (
                    <div className="card">
                        <div className="card-header">Request a reveal</div>
                        <form onSubmit={submit} className="card-body space-y-3">
                            <div>
                                <label className="form-label">Reason</label>
                                <select
                                    className="form-select"
                                    value={form.data.reason_code}
                                    onChange={(e) => form.setData('reason_code', e.target.value)}
                                >
                                    <option value="">Select a reason…</option>
                                    {Object.entries(reasonCodes).map(([code, label]) => (
                                        <option key={code} value={code}>
                                            {label}
                                        </option>
                                    ))}
                                </select>
                                <p className="mt-1 text-xs text-red-600">{form.errors.reason_code}</p>
                            </div>
                            <div>
                                <label className="form-label">Written justification</label>
                                <textarea
                                    className="form-input"
                                    rows={4}
                                    value={form.data.justification}
                                    onChange={(e) => form.setData('justification', e.target.value)}
                                    placeholder="Why investigating this case requires the reporter's identifying data…"
                                />
                                <p className="mt-1 text-xs text-red-600">{form.errors.justification}</p>
                            </div>
                            <div className="flex items-center justify-between">
                                <p className="text-xs text-gray-400">A second approver must decide it. You cannot approve your own request.</p>
                                <button type="submit" className="btn-primary" disabled={form.processing}>
                                    Submit request
                                </button>
                            </div>
                        </form>
                    </div>
                )}

                <div className="card">
                    <div className="card-header">Reveal requests on this report</div>
                    <div className="card-body space-y-3 text-sm">
                        {revealRequests.length === 0 && <p className="text-gray-400">None yet.</p>}
                        {revealRequests.map((request) => (
                            <div key={request.id} className="rounded-lg bg-gray-50 p-3">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="font-medium">{request.requester?.name}</span>
                                    <StatusPill status={request.status} />
                                </div>
                                <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                                    {request.reason_code?.replaceAll('_', ' ')} · {formatDateTime(request.created_at)}
                                </p>
                                <p className="mt-1 whitespace-pre-line text-xs">{request.justification}</p>
                                {request.decider && (
                                    <p className="mt-1 text-xs text-gray-400">
                                        {request.status} by {request.decider.name} · {formatDateTime(request.decided_at)}
                                        {request.decision_note ? ` — ${request.decision_note}` : ''}
                                    </p>
                                )}
                            </div>
                        ))}
                        {can.approve_reveal && revealRequests.some((r) => r.status === 'pending') && (
                            <p className="text-xs text-gray-400">
                                Decide pending requests from the{' '}
                                <Link href={route('speak-up.reveal-requests')} className="text-[var(--color-primary)] hover:underline">
                                    reveal approvals queue
                                </Link>
                                .
                            </p>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

function AccessLogTab({ accessLog }) {
    return (
        <div className="card">
            <div className="card-header">Metadata Access Log (immutable)</div>
            <div className="card-body">
                {!accessLog?.length && <p className="text-sm text-gray-400">No access events recorded.</p>}
                <div className="space-y-2">
                    {(accessLog ?? []).map((entry) => (
                        <div key={entry.id} className="rounded-lg border border-gray-100 p-3 text-sm">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="font-medium capitalize">{entry.action}</span>
                                <span className="text-xs text-gray-400">{formatDateTime(entry.occurred_at)}</span>
                            </div>
                            <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                                {entry.requester ? `Requested by ${entry.requester.name}` : null}
                                {entry.approver ? ` · approved by ${entry.approver.name}` : null}
                                {entry.reason_code ? ` · ${entry.reason_code.replaceAll('_', ' ')}` : null}
                            </p>
                            {entry.justification && <p className="mt-1 whitespace-pre-line text-xs">{entry.justification}</p>}
                            {entry.fields_revealed?.length > 0 && (
                                <p className="mt-1 text-xs text-gray-400">Fields: {entry.fields_revealed.join(', ')}</p>
                            )}
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

function LegalHoldForm({ reportCase, onClose }) {
    const form = useForm({ reason: '' });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(route('cases.legal-hold.set', reportCase.id), {
                    preserveScroll: true,
                    onSuccess: onClose,
                });
            }}
            className="space-y-4 p-6"
        >
            <h2 className="text-lg font-semibold">Set a legal hold</h2>
            <p className="text-sm text-[var(--color-text-secondary)]">
                A legal hold suspends the scheduled purge of this case's reporter metadata until it is lifted. The hold is
                logged.
            </p>
            <div>
                <label className="form-label">Reason</label>
                <textarea
                    className="form-input"
                    rows={3}
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                />
                <p className="mt-1 text-xs text-red-600">{form.errors.reason}</p>
            </div>
            <div className="flex justify-end gap-2">
                <button type="button" className="btn-secondary" onClick={onClose}>
                    Cancel
                </button>
                <button type="submit" className="btn-primary" disabled={form.processing}>
                    Set hold
                </button>
            </div>
        </form>
    );
}

function StatusPill({ status }) {
    const tones = {
        pending: 'bg-amber-50 text-amber-700',
        approved: 'bg-green-50 text-green-700',
        denied: 'bg-red-50 text-red-700',
        expired: 'bg-gray-100 text-gray-500',
    };

    return <span className={`rounded-full px-2 py-0.5 text-xs font-medium capitalize ${tones[status] ?? tones.expired}`}>{status}</span>;
}

function Row({ label, value, detail }) {
    return (
        <div className="flex items-start justify-between gap-4">
            <span className="text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">{label}</span>
            <span className="break-all text-right text-sm">
                {value || <span className="text-gray-400">—</span>}
                {detail && <span className="block text-xs text-gray-400">{detail}</span>}
            </span>
        </div>
    );
}

function join(a, b, sep = ' ') {
    return [a, b].filter(Boolean).join(sep) || null;
}
