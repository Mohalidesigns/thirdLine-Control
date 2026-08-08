import ConfirmDialog from '@/Components/ConfirmDialog';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import StatusBadge from '@/Components/StatusBadge';
import TextArea from '@/Components/TextArea';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Show({ distribution, can = {} }) {
    const [showDecline, setShowDecline] = useState(false);
    const [decision, setDecision] = useState(null);

    const control = distribution.control ?? {};
    const child = distribution.child_control;

    return (
        <AuthenticatedLayout header="Distribution">
            <Head title={`Distribution — ${control.control_ref}`} />

            <PageHeader
                title={`${control.control_ref} → ${distribution.entity?.name}`}
                subtitle={control.title}
                breadcrumbs={[
                    { label: 'Control Library', href: route('controls.index') },
                    { label: 'Distribution', href: route('distributions.index') },
                    { label: control.control_ref ?? '' },
                ]}
                actions={
                    <>
                        {can.acknowledge && (
                            <PrimaryButton onClick={() => router.post(route('distributions.acknowledge', distribution.id))}>
                                Acknowledge receipt
                            </PrimaryButton>
                        )}
                        {can.requestDecline && (
                            <button type="button" className="btn-danger" onClick={() => setShowDecline(true)}>
                                Request decline
                            </button>
                        )}
                    </>
                }
            />

            {distribution.status === 'Decline Requested' && (
                <div className="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <p className="text-sm font-semibold text-amber-800">Decline requested</p>
                    <p className="mt-1 text-sm text-amber-700">“{distribution.decline_reason}”</p>
                    {can.decideDecline && (
                        <div className="mt-3 flex gap-2">
                            <button type="button" className="btn-danger" onClick={() => setDecision('approve')}>
                                Approve decline
                            </button>
                            <SecondaryButton onClick={() => setDecision('reject')}>Reject decline</SecondaryButton>
                        </div>
                    )}
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <div className="card">
                        <div className="card-header flex items-center justify-between">
                            <h3 className="text-sm font-semibold">Implementation tasks</h3>
                            <span className="text-xs text-[var(--color-text-secondary)]">{distribution.progress}% complete</span>
                        </div>
                        <div className="card-body">
                            <div className="progress-bar mb-4">
                                <div className="progress-bar-fill bg-[var(--color-secondary)]" style={{ width: `${distribution.progress}%` }} />
                            </div>
                            {distribution.tasks.length === 0 && (
                                <p className="text-sm text-[var(--color-text-secondary)]">No implementation tasks were defined for this distribution.</p>
                            )}
                            <ul className="divide-y divide-gray-100">
                                {distribution.tasks.map((task) => (
                                    <TaskRow key={task.id} distribution={distribution} task={task} editable={can.progressTask} />
                                ))}
                            </ul>
                        </div>
                    </div>
                </div>

                <div className="space-y-6">
                    <div className="card">
                        <div className="card-header"><h3 className="text-sm font-semibold">Details</h3></div>
                        <div className="card-body space-y-3 text-sm">
                            <Detail label="Status"><StatusBadge status={distribution.status} /></Detail>
                            <Detail label="Entity">{distribution.entity?.name}</Detail>
                            <Detail label="Assigned owner">{distribution.assigned_owner?.name ?? '—'}</Detail>
                            <Detail label="Distributed by">{distribution.distributor?.name ?? '—'}</Detail>
                            <Detail label="Distributed">{formatDate(distribution.distributed_at)}</Detail>
                            <Detail label="Due">{distribution.due_at ? formatDate(distribution.due_at) : '—'}</Detail>
                            <Detail label="Acknowledged">{distribution.acknowledged_at ? formatDate(distribution.acknowledged_at) : 'Not yet'}</Detail>
                            {distribution.decline_approver && (
                                <Detail label="Decline approved by">{distribution.decline_approver.name}</Detail>
                            )}
                        </div>
                    </div>

                    {child && (
                        <div className="card">
                            <div className="card-header"><h3 className="text-sm font-semibold">Entity control instance</h3></div>
                            <div className="card-body space-y-3 text-sm">
                                <Detail label="Reference">
                                    <Link href={route('controls.show', child.id)} className="font-mono text-xs font-semibold text-[var(--color-primary)]">
                                        {child.control_ref}
                                    </Link>
                                </Detail>
                                <Detail label="Owner">{child.owner?.name ?? '—'}</Detail>
                                <Detail label="Implementation"><StatusBadge status={child.implementation_status} /></Detail>
                                <Detail label="Control status"><StatusBadge status={child.status} /></Detail>
                            </div>
                        </div>
                    )}

                    {distribution.local_adaptations && (
                        <div className="card">
                            <div className="card-header"><h3 className="text-sm font-semibold">Local adaptations</h3></div>
                            <div className="card-body text-sm text-[var(--color-text-secondary)]">{distribution.local_adaptations}</div>
                        </div>
                    )}
                </div>
            </div>

            <DeclineModal show={showDecline} onClose={() => setShowDecline(false)} distribution={distribution} />

            <ConfirmDialog
                show={decision !== null}
                title={decision === 'approve' ? 'Approve decline?' : 'Reject decline?'}
                message={
                    decision === 'approve'
                        ? 'The entity control instance will be retired and this control will report as a coverage gap for the entity.'
                        : 'The distribution will return to the entity owner for implementation.'
                }
                variant={decision === 'approve' ? 'danger' : 'primary'}
                onConfirm={() => {
                    router.post(route('distributions.decide-decline', distribution.id), { decision }, { onFinish: () => setDecision(null) });
                }}
                onCancel={() => setDecision(null)}
            />
        </AuthenticatedLayout>
    );
}

function Detail({ label, children }) {
    return (
        <div className="flex items-start justify-between gap-3">
            <span className="text-[var(--color-text-secondary)]">{label}</span>
            <span className="text-right font-medium text-[var(--color-text-primary)]">{children}</span>
        </div>
    );
}

function TaskRow({ distribution, task, editable }) {
    const update = (status) => {
        const payload = { status };
        if (status === 'Blocked') {
            const reason = window.prompt('What is blocking this task?');
            if (!reason) return;
            payload.blocker_reason = reason;
        }
        router.put(route('distributions.tasks.update', [distribution.id, task.id]), payload, { preserveScroll: true });
    };

    return (
        <li className="flex items-center justify-between gap-3 py-3">
            <div>
                <p className={`text-sm font-medium ${task.status === 'Complete' ? 'text-gray-400 line-through' : 'text-[var(--color-text-primary)]'}`}>
                    {task.title}
                </p>
                {task.description && <p className="text-xs text-[var(--color-text-secondary)]">{task.description}</p>}
                {task.status === 'Blocked' && task.blocker_reason && (
                    <p className="mt-1 text-xs text-[var(--color-error)]">Blocked: {task.blocker_reason}</p>
                )}
                {task.completed_at && (
                    <p className="mt-1 text-xs text-gray-400">Completed {formatDate(task.completed_at)} by {task.completer?.name}</p>
                )}
            </div>
            <div className="flex items-center gap-2">
                <StatusBadge status={task.status} />
                {editable && task.status !== 'Complete' && (
                    <select
                        className="form-select !w-auto !py-1 text-xs"
                        value={task.status}
                        onChange={(e) => update(e.target.value)}
                    >
                        {['Open', 'In Progress', 'Complete', 'Blocked'].map((s) => (
                            <option key={s} value={s}>{s}</option>
                        ))}
                    </select>
                )}
            </div>
        </li>
    );
}

function DeclineModal({ show, onClose, distribution }) {
    const { data, setData, post, processing, errors } = useForm({ reason: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('distributions.request-decline', distribution.id), { onSuccess: onClose });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="lg">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-lg font-semibold text-[var(--color-text-primary)]">Request to decline this control</h2>
                <p className="mt-1 text-sm text-[var(--color-text-secondary)]">
                    A decline needs Control Function Head approval. Until then the control stays assigned; if approved it reports as a coverage gap for your entity.
                </p>
                <div className="mt-4">
                    <TextArea
                        rows={4}
                        value={data.reason}
                        onChange={(e) => setData('reason', e.target.value)}
                        placeholder="Why does this control not apply to this entity? (minimum 10 characters)"
                    />
                    {errors.reason && <p className="mt-1 text-sm text-[var(--color-error)]">{errors.reason}</p>}
                </div>
                <div className="mt-6 flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>Cancel</SecondaryButton>
                    <PrimaryButton disabled={processing}>Submit request</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
