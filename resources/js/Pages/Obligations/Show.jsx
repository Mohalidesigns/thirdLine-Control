import DataTable from '@/Components/DataTable';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import StatusBadge from '@/Components/StatusBadge';
import TextArea from '@/Components/TextArea';
import VerificationBadge from '@/Components/VerificationBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate, formatMoney } from '@/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

function describeRule(rule) {
    if (!rule?.type) return 'No due rule recorded';

    if (rule.type === 'fixed_date') {
        const month = rule.month ? new Date(2000, rule.month - 1, 1).toLocaleDateString('en-NG', { month: 'long' }) : null;
        return month ? `Every ${rule.day} ${month}, after the period it reports on` : `By the ${rule.day} of the following month`;
    }

    if (rule.type === 'relative') {
        const parts = [];
        if (rule.offset_months) parts.push(`${rule.offset_months} month(s)`);
        if (rule.offset_days) parts.push(`${rule.offset_days} day(s)`);
        return `${parts.join(' and ') || 'On the day'} after ${(rule.anchor ?? 'period_end').replace(/_/g, ' ')}`;
    }

    return `${rule.offset_hours ?? 0} hours after "${rule.event ?? 'the event'}"`;
}

export default function Show({ obligation, assignments = [], instances = [], entities = [], users = [], can = {} }) {
    const [assigning, setAssigning] = useState(false);
    const [declaring, setDeclaring] = useState(null);
    const [reason, setReason] = useState('');

    const assignForm = useForm({
        obligation_id: obligation.id,
        entity_id: '',
        owner_id: '',
        reviewer_id: '',
    });

    const assign = (event) => {
        event.preventDefault();
        assignForm.post(route('obligation-assignments.store'), {
            preserveScroll: true,
            onSuccess: () => {
                setAssigning(false);
                assignForm.reset();
            },
        });
    };

    const declare = () => {
        router.post(
            route('obligation-assignments.non-applicable', declaring.id),
            { reason },
            {
                preserveScroll: true,
                onFinish: () => {
                    setDeclaring(null);
                    setReason('');
                },
            },
        );
    };

    const instanceColumns = [
        { field: 'period_label', label: 'Period' },
        { field: 'entity', label: 'Entity', render: (row) => row.assignment?.entity?.name ?? '—' },
        { field: 'due_at', label: 'Due', render: (row) => formatDate(row.due_at) },
        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
        {
            field: 'days_overdue',
            label: 'Overdue',
            render: (row) => (row.days_overdue > 0 ? `${row.days_overdue} day(s)` : '—'),
        },
        {
            field: 'penalty_exposure',
            label: 'Exposure',
            render: (row) => (row.penalty_exposure ? formatMoney(row.penalty_exposure) : '—'),
        },
        { field: 'submission_reference', label: 'Reference', render: (row) => row.submission_reference ?? '—' },
    ];

    return (
        <AuthenticatedLayout header={obligation.obligation_ref}>
            <Head title={obligation.title} />

            <PageHeader
                breadcrumbs={[{ label: 'Obligations', href: route('obligations.index') }, { label: obligation.obligation_ref }]}
                title={obligation.title}
                subtitle={`${obligation.regulator?.name ?? 'Regulator not recorded'} · ${obligation.obligation_type} · ${obligation.frequency.replace('_', ' ')}`}
                actions={
                    <>
                        {can.assign && (
                            <button type="button" onClick={() => setAssigning(true)} className="btn-secondary">
                                Assign to entity
                            </button>
                        )}
                        {can.manage && (
                            <Link href={route('obligations.edit', obligation.id)} className="btn-primary">
                                Edit
                            </Link>
                        )}
                    </>
                }
            />

            {obligation.verification_status !== 'verified' && (
                <div className="mb-4 rounded-lg border-l-4 border-l-[var(--color-warning)] bg-amber-50 px-4 py-3 text-sm">
                    <span className="font-semibold">This obligation is {obligation.verification_status}.</span> Its dates,
                    thresholds and penalties have not been confirmed against the regulator’s primary document, so it is
                    excluded from any generated regulatory submission.
                </div>
            )}

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <div className="card">
                        <div className="card-header">
                            <h2 className="text-sm font-semibold">Detail</h2>
                        </div>
                        <div className="card-body space-y-4">
                            {obligation.description && <p className="text-sm">{obligation.description}</p>}

                            <dl className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <dt className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                                        When it falls due
                                    </dt>
                                    <dd className="mt-1 text-sm">{describeRule(obligation.due_rule)}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                                        Grace period
                                    </dt>
                                    <dd className="mt-1 text-sm">{obligation.grace_period_days} day(s)</dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                                        Penalty
                                    </dt>
                                    <dd className="mt-1 text-sm">
                                        {obligation.penalty
                                            ? `${formatMoney(obligation.penalty)} — ${obligation.penalty_basis?.replace('_', ' ')}`
                                            : obligation.penalty_basis === 'percentage'
                                              ? `${(obligation.penalty_amount_minor / 100).toFixed(2)}% of the stated base`
                                              : 'None recorded'}
                                        {obligation.penalty_description && (
                                            <span className="block text-xs text-gray-400">{obligation.penalty_description}</span>
                                        )}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                                        Legal reference
                                    </dt>
                                    <dd className="mt-1 text-sm">
                                        {obligation.legal_reference ?? '—'}
                                        {obligation.source_url && (
                                            <a
                                                href={obligation.source_url}
                                                target="_blank"
                                                rel="noreferrer noopener"
                                                className="ml-2 text-xs text-[var(--color-primary)] underline"
                                            >
                                                source
                                            </a>
                                        )}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                                        Effective
                                    </dt>
                                    <dd className="mt-1 text-sm">
                                        {formatDate(obligation.effective_from)} — {obligation.effective_to ? formatDate(obligation.effective_to) : 'open'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                                        Framework link
                                    </dt>
                                    <dd className="mt-1 text-sm">
                                        {obligation.requirement
                                            ? `${obligation.framework?.code} ${obligation.requirement.ref_code}`
                                            : (obligation.framework?.code ?? '—')}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">
                            <h2 className="text-sm font-semibold">Calendar entries</h2>
                        </div>
                        <DataTable
                            columns={instanceColumns}
                            data={instances}
                            emptyMessage="No calendar entries yet — assign this obligation to an entity."
                            onRowClick={(row) => router.visit(route('obligations.instances.show', row.id))}
                        />
                    </div>
                </div>

                <div className="card h-fit">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">Assigned entities</h2>
                    </div>
                    <div className="card-body space-y-4">
                        {assignments.length === 0 && (
                            <p className="text-sm text-gray-400">Not yet assigned to any entity.</p>
                        )}

                        {assignments.map((assignment) => (
                            <div key={assignment.id} className="rounded-lg border border-gray-100 p-3">
                                <p className="text-sm font-medium">{assignment.entity?.name}</p>
                                <p className="mt-0.5 text-xs text-gray-400">
                                    Owner: {assignment.owner?.name ?? '—'}
                                    {assignment.reviewer && ` · Reviewer: ${assignment.reviewer.name}`}
                                </p>

                                {!assignment.is_applicable && (
                                    <div className="mt-2 text-xs">
                                        <span className={`badge ${assignment.approved_at ? 'badge-status-draft' : 'badge-medium'}`}>
                                            {assignment.approved_at ? 'Not applicable (approved)' : 'Not applicable — awaiting approval'}
                                        </span>
                                        <p className="mt-1 text-gray-400">{assignment.non_applicability_reason}</p>
                                        {!assignment.approved_at && (
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    router.post(
                                                        route('obligation-assignments.approve-non-applicability', assignment.id),
                                                        {},
                                                        { preserveScroll: true },
                                                    )
                                                }
                                                className="btn-secondary mt-2 text-xs"
                                            >
                                                Approve declaration
                                            </button>
                                        )}
                                    </div>
                                )}

                                {assignment.is_applicable && can.assign && (
                                    <button
                                        type="button"
                                        onClick={() => setDeclaring(assignment)}
                                        className="btn-secondary mt-2 text-xs"
                                    >
                                        Declare not applicable
                                    </button>
                                )}
                            </div>
                        ))}

                        <div className="pt-2">
                            <VerificationBadge status={obligation.verification_status} />
                        </div>
                    </div>
                </div>
            </div>

            <Modal show={assigning} maxWidth="md" title="Assign this obligation" onClose={() => setAssigning(false)}>
                <form onSubmit={assign} className="space-y-4">
                    <div>
                        <InputLabel htmlFor="entity_id" value="Entity" />
                        <SelectInput
                            id="entity_id"
                            value={assignForm.data.entity_id}
                            onChange={(e) => assignForm.setData('entity_id', e.target.value)}
                            className="mt-1 block w-full"
                            required
                        >
                            <option value="">Select an entity…</option>
                            {entities.map((entity) => (
                                <option key={entity.id} value={entity.id}>
                                    {entity.name}
                                </option>
                            ))}
                        </SelectInput>
                    </div>

                    <div>
                        <InputLabel htmlFor="owner_id" value="Owner" />
                        <SelectInput
                            id="owner_id"
                            value={assignForm.data.owner_id}
                            onChange={(e) => assignForm.setData('owner_id', e.target.value)}
                            className="mt-1 block w-full"
                            required
                        >
                            <option value="">Select an owner…</option>
                            {users.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name}
                                </option>
                            ))}
                        </SelectInput>
                    </div>

                    <div>
                        <InputLabel htmlFor="reviewer_id" value="Reviewer (accepts the filing)" />
                        <SelectInput
                            id="reviewer_id"
                            value={assignForm.data.reviewer_id}
                            onChange={(e) => assignForm.setData('reviewer_id', e.target.value)}
                            className="mt-1 block w-full"
                        >
                            <option value="">—</option>
                            {users
                                .filter((user) => String(user.id) !== String(assignForm.data.owner_id))
                                .map((user) => (
                                    <option key={user.id} value={user.id}>
                                        {user.name}
                                    </option>
                                ))}
                        </SelectInput>
                        <p className="mt-1 text-xs text-gray-400">
                            The reviewer must be someone other than the owner — the person who files cannot accept their
                            own filing.
                        </p>
                    </div>

                    <div className="flex justify-end gap-2">
                        <SecondaryButton type="button" onClick={() => setAssigning(false)}>
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton disabled={assignForm.processing}>Assign</PrimaryButton>
                    </div>
                </form>
            </Modal>

            <Modal
                show={Boolean(declaring)}
                maxWidth="md"
                title="Declare this obligation not applicable"
                onClose={() => setDeclaring(null)}
            >
                <p className="text-sm text-[var(--color-text-secondary)]">
                    The declaration takes effect only once a second person approves it. Until then the calendar keeps
                    generating entries.
                </p>
                <TextArea
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                    rows={4}
                    className="mt-3 block w-full"
                    placeholder="Why does this obligation not bind this entity? (minimum 20 characters)"
                />
                <div className="mt-6 flex justify-end gap-2">
                    <SecondaryButton onClick={() => setDeclaring(null)}>Cancel</SecondaryButton>
                    <PrimaryButton onClick={declare} disabled={reason.trim().length < 20}>
                        Record declaration
                    </PrimaryButton>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
