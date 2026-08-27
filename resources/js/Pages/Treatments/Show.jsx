import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import ProgressBar from '@/Components/ProgressBar';
import RelationshipsPanel from '@/Components/RelationshipsPanel';
import RichTextEditor from '@/Components/RichTextEditor';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import StatusBadge from '@/Components/StatusBadge';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate, formatDateTime, formatMoney } from '@/utils';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Plus } from 'lucide-react';

export default function Show({ treatment, links = [], users = [], can = {} }) {
    const { features = [] } = usePage().props;
    const [approving, setApproving] = useState(false);
    const [verifying, setVerifying] = useState(false);
    const [addingMilestone, setAddingMilestone] = useState(false);

    const act = (routeName, payload = {}) =>
        router.post(route(routeName, treatment.id), payload, { preserveScroll: true });

    return (
        <AuthenticatedLayout header={treatment.reference}>
            <Head title={treatment.reference} />

            <PageHeader
                title={treatment.title}
                subtitle={<span className="font-mono">{treatment.reference}</span>}
                breadcrumbs={[
                    { label: 'Treatment Plans', href: route('treatments.index') },
                    { label: treatment.reference },
                ]}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusBadge status={treatment.status} />
                        {can.approve && treatment.status === 'Proposed' && (
                            <PrimaryButton onClick={() => setApproving(true)}>Approve</PrimaryButton>
                        )}
                        {can.progress && treatment.status === 'Approved' && (
                            <SecondaryButton type="button" onClick={() => act('treatments.progress', { action: 'start' })}>
                                Start
                            </SecondaryButton>
                        )}
                        {can.progress && ['In Progress', 'Overdue'].includes(treatment.status) && (
                            <SecondaryButton type="button" onClick={() => act('treatments.progress', { action: 'implement' })}>
                                Mark implemented
                            </SecondaryButton>
                        )}
                        {can.verify && treatment.status === 'Implemented' && (
                            <PrimaryButton onClick={() => setVerifying(true)}>Verify</PrimaryButton>
                        )}
                    </div>
                }
            />

            {treatment.status === 'Implemented' && !can.verify && (
                <div className="mb-6 rounded-xl border border-amber-100 bg-amber-50 p-4 text-sm">
                    Awaiting independent verification. The plan owner cannot verify their own work.
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <div className="card">
                        <div className="card-header">
                            <h3 className="text-sm font-semibold">Plan</h3>
                            <span className="badge badge-status-draft">{treatment.strategy}</span>
                        </div>
                        <div className="card-body space-y-3 text-sm">
                            <p>{treatment.description || '—'}</p>
                            <div>
                                <ProgressBar
                                    value={treatment.progress ?? 0}
                                    color={treatment.status === 'Overdue' ? 'var(--color-error)' : 'var(--color-success)'}
                                />
                                <p className="mt-1 text-xs text-gray-400">{treatment.progress ?? 0}% complete</p>
                            </div>
                            {can.progress && (
                                <ProgressForm treatment={treatment} />
                            )}
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">
                            <h3 className="text-sm font-semibold">Milestones</h3>
                            {can.update && (
                                <button
                                    type="button"
                                    className="text-xs font-semibold text-[var(--color-primary)] hover:underline"
                                    onClick={() => setAddingMilestone(true)}
                                >
                                    <Plus className="h-4 w-4" strokeWidth={2} /> Add
                                </button>
                            )}
                        </div>
                        <div className="card-body">
                            {treatment.milestones?.length ? (
                                <ul className="divide-y divide-gray-100">
                                    {treatment.milestones.map((milestone) => (
                                        <li key={milestone.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
                                            <div>
                                                <p className="text-sm font-medium text-[var(--color-text-primary)]">
                                                    {milestone.sequence}. {milestone.title}
                                                </p>
                                                <p className="text-xs text-gray-400">
                                                    Due {formatDate(milestone.due_at)} · {milestone.owner?.name ?? 'Unassigned'}
                                                </p>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <StatusBadge status={milestone.status} />
                                                {can.progress && !['Completed', 'Cancelled'].includes(milestone.status) && (
                                                    <button
                                                        type="button"
                                                        className="btn-success !px-2 !py-1 text-xs"
                                                        onClick={() =>
                                                            router.post(
                                                                route('treatments.milestones.complete', [treatment.id, milestone.id]),
                                                                {},
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                    >
                                                        Complete
                                                    </button>
                                                )}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-sm text-[var(--color-text-secondary)]">No milestones recorded.</p>
                            )}
                        </div>
                    </div>

                    {treatment.verification_notes && (
                        <div className="card">
                            <div className="card-header">
                                <h3 className="text-sm font-semibold">Verification</h3>
                            </div>
                            <div className="card-body text-sm">
                                <p>{treatment.verification_notes}</p>
                                <p className="mt-1 text-xs text-gray-400">
                                    Verified by {treatment.verifier?.name ?? '—'} on {formatDateTime(treatment.verified_at)}
                                </p>
                            </div>
                        </div>
                    )}
                </div>

                <div className="space-y-6">
                    <div className="card">
                        <div className="card-header">
                            <h3 className="text-sm font-semibold">Details</h3>
                        </div>
                        <div className="card-body space-y-1.5 text-sm">
                            <p>
                                <span className="text-gray-500">Risk:</span>{' '}
                                {treatment.risk ? (
                                    <Link href={route('risks.show', treatment.risk.id)} className="text-[var(--color-primary)] hover:underline">
                                        {treatment.risk.code}
                                    </Link>
                                ) : (
                                    '—'
                                )}
                            </p>
                            <p><span className="text-gray-500">Owner:</span> {treatment.owner?.name ?? '—'}</p>
                            <p><span className="text-gray-500">Approver:</span> {treatment.approver?.name ?? '—'}</p>
                            <p><span className="text-gray-500">Target rating:</span> {treatment.target_rating ?? '—'}</p>
                            <p><span className="text-gray-500">Start:</span> {formatDate(treatment.start_at)}</p>
                            <p><span className="text-gray-500">Due:</span> {formatDate(treatment.due_at)}</p>
                            <p><span className="text-gray-500">Cost:</span> {treatment.cost_minor ? formatMoney(treatment.cost_minor, treatment.currency) : '—'}</p>
                            <p><span className="text-gray-500">Benefit:</span> {treatment.benefit_description ?? '—'}</p>
                            <p>
                                <span className="text-gray-500">Implementing control:</span>{' '}
                                {treatment.control ? (
                                    <Link href={route('controls.show', treatment.control.id)} className="text-[var(--color-primary)] hover:underline">
                                        {treatment.control.control_ref}
                                    </Link>
                                ) : (
                                    '—'
                                )}
                            </p>
                            <p>
                                <span className="text-gray-500">Alerts:</span>{' '}
                                {treatment.alerts_enabled ? `On, ${treatment.alert_lead_days} day lead` : 'Muted'}
                            </p>
                        </div>
                    </div>

                    {treatment.strategy === 'Accept' && (
                        <div className="card">
                            <div className="card-header">
                                <h3 className="text-sm font-semibold">Risk acceptance</h3>
                            </div>
                            <div className="card-body space-y-1.5 text-sm">
                                <p>{treatment.acceptance_reason ?? '—'}</p>
                                <p className="text-xs text-gray-400">
                                    Expires {formatDate(treatment.acceptance_expiry)} — re-confirmation required after that date.
                                </p>
                            </div>
                        </div>
                    )}

                    {features.includes('linkage') && (
                        <RelationshipsPanel type="treatment" id={treatment.id} links={links} canLink={can.update} />
                    )}
                </div>
            </div>

            <ApproveModal show={approving} onClose={() => setApproving(false)} treatment={treatment} />
            <VerifyModal show={verifying} onClose={() => setVerifying(false)} treatment={treatment} />
            <MilestoneModal
                show={addingMilestone}
                onClose={() => setAddingMilestone(false)}
                treatment={treatment}
                users={users}
            />
        </AuthenticatedLayout>
    );
}

function ProgressForm({ treatment }) {
    const form = useForm({ action: 'progress', progress: treatment.progress ?? 0, note: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('treatments.progress', treatment.id), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-end gap-3 border-t border-gray-100 pt-3">
            <div className="w-28">
                <InputLabel value="Progress %" />
                <TextInput
                    type="number"
                    min="0"
                    max="100"
                    value={form.data.progress}
                    onChange={(e) => form.setData('progress', Number(e.target.value))}
                />
            </div>
            <div className="min-w-[200px] flex-1">
                <InputLabel value="Note" />
                <TextInput value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
            </div>
            <PrimaryButton disabled={form.processing}>Record</PrimaryButton>
        </form>
    );
}

function ApproveModal({ show, onClose, treatment }) {
    const isAcceptance = treatment.strategy === 'Accept';
    const form = useForm({ acceptance_reason: '', acceptance_expiry: '' , acceptance_reason_rich: null});

    const submit = (event) => {
        event.preventDefault();
        form.post(route('treatments.approve', treatment.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Modal show={show} onClose={onClose} title={isAcceptance ? 'Accept this risk' : 'Approve treatment plan'}>
            <form onSubmit={submit} className="space-y-4">
                {isAcceptance ? (
                    <>
                        <p className="text-sm text-[var(--color-text-secondary)]">
                            Accepting a risk is a Control Function Head decision and must carry an expiry date, after which
                            the acceptance is re-confirmed.
                        </p>
                        <div>
                            <InputLabel value="Reason for acceptance" required />
                            <RichTextEditor
                                value={form.data.acceptance_reason_rich ?? form.data.acceptance_reason}
                                onChange={(doc, plain) => form.setData((d) => ({ ...d, acceptance_reason: plain, acceptance_reason_rich: doc }))}
                                tools="minimal"
                                minHeight={90}
                            />
                        </div>
                        <div>
                            <InputLabel value="Acceptance expires" required />
                            <TextInput type="date" value={form.data.acceptance_expiry} onChange={(e) => form.setData('acceptance_expiry', e.target.value)} />
                            {form.errors.acceptance_expiry && (
                                <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.acceptance_expiry}</p>
                            )}
                        </div>
                    </>
                ) : (
                    <p className="text-sm text-[var(--color-text-primary)]">
                        Approve <span className="font-mono">{treatment.reference}</span>? The plan owner cannot approve their
                        own plan.
                    </p>
                )}

                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Approve</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

function VerifyModal({ show, onClose, treatment }) {
    const form = useForm({ verification_notes: '' , verification_notes_rich: null});

    const submit = (event) => {
        event.preventDefault();
        form.post(route('treatments.verify', treatment.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal show={show} onClose={onClose} title="Verify treatment">
            <form onSubmit={submit} className="space-y-4">
                <p className="text-sm text-[var(--color-text-secondary)]">
                    Verification is independent of the plan owner. Verifying re-scores the risk and re-checks it against
                    appetite.
                </p>
                <div>
                    <InputLabel value="What was checked" />
                    <RichTextEditor
                        value={form.data.verification_notes_rich ?? form.data.verification_notes}
                        onChange={(doc, plain) => form.setData((d) => ({ ...d, verification_notes: plain, verification_notes_rich: doc }))}
                        tools="minimal"
                        minHeight={90}
                    />
                </div>
                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Verify</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

function MilestoneModal({ show, onClose, treatment, users }) {
    const form = useForm({ title: '', due_at: '', owner_id: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('treatments.milestones.store', treatment.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="lg" title="Add milestone">
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel value="Title" required />
                    <TextInput value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                </div>
                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel value="Due" />
                        <TextInput type="date" value={form.data.due_at} onChange={(e) => form.setData('due_at', e.target.value)} />
                    </div>
                    <div>
                        <InputLabel value="Owner" />
                        <SelectInput value={form.data.owner_id} onChange={(e) => form.setData('owner_id', e.target.value)}>
                            <option value="">Plan owner</option>
                            {users.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                </div>
                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Add</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
