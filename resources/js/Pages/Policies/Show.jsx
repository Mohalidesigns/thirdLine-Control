import ConfirmDialog from '@/Components/ConfirmDialog';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import ProgressBar from '@/Components/ProgressBar';
import RelationshipsPanel from '@/Components/RelationshipsPanel';
import RichTextEditor from '@/Components/RichTextEditor';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate, formatDateTime } from '@/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Show({ policy, links = [], attestation = null, users = [], can = {} }) {
    const [dialog, setDialog] = useState(null);
    const [waiverOpen, setWaiverOpen] = useState(false);

    const act = (name, payload = {}) =>
        router.post(route(name, policy.id), payload, { preserveScroll: true, onFinish: () => setDialog(null) });

    const waiver = useForm({
        justification: '',
        risk_assessment: '',
        compensating_measures: '',
        requested_from: new Date().toISOString().slice(0, 10),
        requested_to: '',
    
        compensating_measures_rich: null,
        justification_rich: null,
});

    const submitWaiver = (e) => {
        e.preventDefault();
        waiver.post(route('policies.exceptions.store', policy.id), {
            preserveScroll: true,
            onSuccess: () => {
                waiver.reset();
                setWaiverOpen(false);
            },
        });
    };

    return (
        <AuthenticatedLayout header={policy.policy_ref}>
            <Head title={policy.title} />

            <PageHeader
                title={policy.title}
                subtitle={`${policy.policy_ref} · v${policy.version_no} · ${policy.policy_type}`}
                breadcrumbs={[{ label: 'Policies', href: route('policies.index') }, { label: policy.policy_ref }]}
                actions={
                    <>
                        <StatusBadge status={policy.status} />
                        {can.submit && ['Draft', 'Under Review', 'Under Revision'].includes(policy.status) && (
                            <button className="btn-secondary" onClick={() => act('policies.submit', { target: 'approval' })}>
                                Submit for approval
                            </button>
                        )}
                        {can.approve && (
                            <button className="btn-success" onClick={() => act('policies.approve')}>
                                Approve
                            </button>
                        )}
                        {can.publish && (
                            <button className="btn-primary" onClick={() => setDialog('publish')}>
                                Publish
                            </button>
                        )}
                        {can.revise && (
                            <button className="btn-secondary" onClick={() => act('policies.revise')}>
                                Open for revision
                            </button>
                        )}
                    </>
                }
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <div className="card">
                        <div className="card-header">Policy text</div>
                        <div className="card-body space-y-6">
                            {policy.description && (
                                <p className="text-sm text-[var(--color-text-secondary)]">{policy.description}</p>
                            )}

                            {(policy.sections ?? []).map((section) => (
                                <div key={section.id}>
                                    <h3 className="flex items-center gap-2 text-sm font-semibold text-[var(--color-text-primary)]">
                                        {section.heading}
                                        {section.is_mandatory && (
                                            <span className="badge badge-status-active text-[10px]">Mandatory</span>
                                        )}
                                    </h3>
                                    {section.body && (
                                        <p className="mt-1 whitespace-pre-line text-sm text-[var(--color-text-secondary)]">
                                            {section.body}
                                        </p>
                                    )}
                                    {(section.control_ids?.length > 0 || section.requirement_ids?.length > 0) && (
                                        <p className="mt-2 text-xs text-gray-400">
                                            Governs {section.control_ids?.length ?? 0} control(s) and{' '}
                                            {section.requirement_ids?.length ?? 0} requirement(s).
                                        </p>
                                    )}
                                </div>
                            ))}

                            {(policy.sections ?? []).length === 0 && (
                                <p className="text-sm text-gray-400">This policy has no sections yet.</p>
                            )}
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header flex items-center justify-between">
                            <span>Waivers</span>
                            {can.request_exception && policy.status === 'Published' && (
                                <button className="btn-secondary" onClick={() => setWaiverOpen(true)}>
                                    Request a waiver
                                </button>
                            )}
                        </div>
                        <div className="card-body">
                            {(policy.exceptions ?? []).length === 0 ? (
                                <p className="text-sm text-gray-400">No waivers have been raised against this policy.</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="data-table">
                                        <thead>
                                            <tr>
                                                <th>Ref</th>
                                                <th>Requested by</th>
                                                <th>Window</th>
                                                <th>Status</th>
                                                <th />
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {policy.exceptions.map((exception) => (
                                                <tr key={exception.id}>
                                                    <td className="font-mono text-xs">{exception.reference}</td>
                                                    <td>{exception.requester?.name ?? '—'}</td>
                                                    <td className="text-xs">
                                                        {formatDate(exception.requested_from)} –{' '}
                                                        {formatDate(exception.requested_to)}
                                                    </td>
                                                    <td>
                                                        <StatusBadge status={exception.status} />
                                                    </td>
                                                    <td className="text-right">
                                                        {can.decide_exception &&
                                                            ['Requested', 'Under Review'].includes(exception.status) && (
                                                                <div className="flex justify-end gap-2">
                                                                    <button
                                                                        className="text-xs font-semibold text-[var(--color-success)] hover:underline"
                                                                        onClick={() =>
                                                                            router.post(
                                                                                route('policy-exceptions.decide', exception.id),
                                                                                { approved: true },
                                                                                { preserveScroll: true },
                                                                            )
                                                                        }
                                                                    >
                                                                        Approve
                                                                    </button>
                                                                    <button
                                                                        className="text-xs font-semibold text-[var(--color-error)] hover:underline"
                                                                        onClick={() =>
                                                                            router.post(
                                                                                route('policy-exceptions.decide', exception.id),
                                                                                { approved: false },
                                                                                { preserveScroll: true },
                                                                            )
                                                                        }
                                                                    >
                                                                        Reject
                                                                    </button>
                                                                </div>
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
                </div>

                <div className="space-y-6">
                    <div className="card">
                        <div className="card-header">Governance</div>
                        <div className="card-body space-y-3 text-sm">
                            <Row label="Owner" value={policy.owner?.name} />
                            <Row label="Approver" value={policy.approver?.name} />
                            <Row label="Category" value={policy.category?.name} />
                            <Row label="Effective from" value={formatDate(policy.effective_from)} />
                            <Row label="Effective to" value={formatDate(policy.effective_to)} />
                            <Row
                                label="Review frequency"
                                value={policy.review_frequency?.replace('_', ' ')}
                            />
                            <Row label="Next review" value={formatDate(policy.next_review_at)} />
                            <Row label="Approved at" value={formatDateTime(policy.approved_at)} />
                            <Row label="Published at" value={formatDateTime(policy.published_at)} />
                            {policy.supersedes && (
                                <Row
                                    label="Supersedes"
                                    value={
                                        <Link
                                            href={route('policies.show', policy.supersedes.id)}
                                            className="text-[var(--color-primary)] hover:underline"
                                        >
                                            {policy.supersedes.policy_ref}
                                        </Link>
                                    }
                                />
                            )}
                        </div>
                    </div>

                    {attestation && (
                        <div className="card">
                            <div className="card-header">Attestation campaign</div>
                            <div className="card-body space-y-3 text-sm">
                                <Row label="Campaign" value={attestation.campaign.reference} />
                                <Row label="Status" value={<StatusBadge status={attestation.campaign.status} />} />
                                <Row label="Closes" value={formatDateTime(attestation.campaign.closes_at)} />
                                <div>
                                    <p className="mb-1 text-xs text-gray-400">
                                        {attestation.attested} of {attestation.invited} attested
                                    </p>
                                    <ProgressBar
                                        value={
                                            attestation.invited > 0
                                                ? Math.round((attestation.attested / attestation.invited) * 100)
                                                : 0
                                        }
                                        color="var(--color-success)"
                                    />
                                </div>
                            </div>
                        </div>
                    )}

                    <RelationshipsPanel type="policy" id={policy.id} links={links} />
                </div>
            </div>

            <ConfirmDialog
                show={dialog === 'publish'}
                title="Publish this policy?"
                message={
                    policy.requires_attestation
                        ? 'Publishing puts the policy in force and opens its attestation campaign immediately.'
                        : 'Publishing puts the policy in force and sets its next review date.'
                }
                variant="primary"
                onConfirm={() => act('policies.publish')}
                onCancel={() => setDialog(null)}
            />

            <Modal show={waiverOpen} onClose={() => setWaiverOpen(false)} maxWidth="lg">
                <form onSubmit={submitWaiver} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold">Request a policy waiver</h2>
                    <p className="text-sm text-gray-400">
                        A waiver is time-boxed and is never approved by the person who asks for it.
                    </p>

                    <div>
                        <label className="form-label">Justification</label>
                        <RichTextEditor
                            value={waiver.data.justification_rich ?? waiver.data.justification}
                            onChange={(doc, plain) => waiver.setData((d) => ({ ...d, justification: plain, justification_rich: doc }))}
                            tools="minimal"
                            minHeight={90}
                        />
                    </div>

                    <div>
                        <label className="form-label">Compensating measures</label>
                        <RichTextEditor
                            value={waiver.data.compensating_measures_rich ?? waiver.data.compensating_measures}
                            onChange={(doc, plain) => waiver.setData((d) => ({ ...d, compensating_measures: plain, compensating_measures_rich: doc }))}
                            tools="minimal"
                            minHeight={90}
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="form-label">From</label>
                            <input
                                type="date"
                                className="form-input"
                                value={waiver.data.requested_from}
                                onChange={(e) => waiver.setData('requested_from', e.target.value)}
                            />
                        </div>
                        <div>
                            <label className="form-label">To</label>
                            <input
                                type="date"
                                className="form-input"
                                value={waiver.data.requested_to}
                                onChange={(e) => waiver.setData('requested_to', e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="flex justify-end gap-2">
                        <button type="button" className="btn-secondary" onClick={() => setWaiverOpen(false)}>
                            Cancel
                        </button>
                        <button type="submit" className="btn-primary" disabled={waiver.processing}>
                            Request waiver
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
