import FilterBar from '@/Components/FilterBar';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate, formatDateTime } from '@/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const RISK_CLASSES = {
    Critical: 'badge-critical',
    High: 'badge-high',
    Medium: 'badge-medium',
    Low: 'badge-low',
};

export default function Violations({
    violations,
    filters = {},
    statuses = [],
    riskLevels = [],
    rules = [],
    controls = [],
    stats = {},
    can = {},
}) {
    const [action, setAction] = useState(null);

    return (
        <AuthenticatedLayout header="SoD Violations">
            <Head title="SoD Violations" />

            <PageHeader
                title="Segregation-of-duties violations"
                subtitle="Accounts holding both halves of a toxic pair. Accepting one is a time-boxed risk decision, never an open-ended waiver."
                breadcrumbs={[
                    { label: 'Segregation of duties', href: route('sod.conflicts') },
                    { label: 'Violations' },
                ]}
                actions={
                    <Link href={route('sod.conflicts')} className="btn-secondary">
                        Conflict matrix
                    </Link>
                }
            />

            <div className="mb-6 grid grid-cols-3 gap-4">
                <StatCard title="Open" value={stats.open ?? 0} color="danger" prominent={(stats.open ?? 0) > 0} />
                <StatCard title="Accepted" value={stats.accepted ?? 0} color="warning" />
                <StatCard
                    title="Acceptances expiring in 30 days"
                    value={stats.expiring ?? 0}
                    color="info"
                    subtitle="They reopen automatically when they lapse"
                />
            </div>

            <FilterBar
                route="sod.violations"
                resource="sod-violations"
                currentFilters={filters}
                searchPlaceholder="Search by account or name…"
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    { name: 'status', type: 'select', label: 'Status', options: statuses },
                    { name: 'risk_level', type: 'select', label: 'Risk', options: riskLevels },
                    {
                        name: 'rule_id',
                        type: 'select',
                        label: 'Conflict',
                        options: rules.map((r) => ({ value: r.id, label: r.name })),
                    },
                ]}
            />

            <div className="card">
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Conflict</th>
                                <th>Risk</th>
                                <th>Status</th>
                                <th>Detected</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {violations.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="py-8 text-center text-sm text-gray-400">
                                        No violations match the filters.
                                    </td>
                                </tr>
                            )}
                            {violations.data.map((violation) => (
                                <tr key={violation.id}>
                                    <td>
                                        <p className="font-medium">{violation.subject_name || violation.subject_identifier}</p>
                                        <p className="font-mono text-xs text-gray-400">{violation.subject_identifier}</p>
                                        {violation.entity && <p className="text-xs text-gray-400">{violation.entity.name}</p>}
                                    </td>
                                    <td className="max-w-sm">
                                        <p className="text-sm">{violation.rule?.name}</p>
                                        <p className="text-xs text-gray-400">
                                            <span className="font-mono">{violation.rule?.function_a}</span> +{' '}
                                            <span className="font-mono">{violation.rule?.function_b}</span>
                                            {violation.rule?.system_key && ` · ${violation.rule.system_key}`}
                                        </p>
                                        {violation.rule?.mitigating_control && (
                                            <Link
                                                href={route('controls.show', violation.rule.mitigating_control.id)}
                                                className="text-xs text-[var(--color-primary)] hover:underline"
                                            >
                                                Mitigated by {violation.rule.mitigating_control.control_ref}
                                            </Link>
                                        )}
                                    </td>
                                    <td>
                                        <span className={`badge ${RISK_CLASSES[violation.rule?.risk_level]}`}>
                                            {violation.rule?.risk_level}
                                        </span>
                                    </td>
                                    <td>
                                        <StatusBadge status={violation.status} />
                                        {violation.accepted_until && (
                                            <p className="mt-1 text-xs text-[var(--color-warning)]">
                                                Until {formatDate(violation.accepted_until)}
                                            </p>
                                        )}
                                        {violation.exception && (
                                            <Link
                                                href={route('exceptions.show', violation.exception.id)}
                                                className="mt-1 block text-xs text-[var(--color-primary)] hover:underline"
                                            >
                                                {violation.exception.reference}
                                            </Link>
                                        )}
                                        {violation.mitigation_notes && (
                                            <p className="mt-1 max-w-xs text-xs italic text-gray-400">
                                                {violation.mitigation_notes}
                                            </p>
                                        )}
                                    </td>
                                    <td className="text-xs">{formatDateTime(violation.detected_at)}</td>
                                    <td>
                                        <div className="flex flex-wrap gap-1">
                                            {can.mitigate && violation.status !== 'Remediated' && (
                                                <>
                                                    <SecondaryButton onClick={() => setAction({ violation, kind: 'mitigate' })}>
                                                        Mitigate
                                                    </SecondaryButton>
                                                    <SecondaryButton onClick={() => setAction({ violation, kind: 'remediate' })}>
                                                        Remediated
                                                    </SecondaryButton>
                                                    <SecondaryButton onClick={() => setAction({ violation, kind: 'false-positive' })}>
                                                        False positive
                                                    </SecondaryButton>
                                                </>
                                            )}
                                            {can.accept && violation.status !== 'Remediated' && (
                                                <SecondaryButton onClick={() => setAction({ violation, kind: 'accept' })}>
                                                    Accept
                                                </SecondaryButton>
                                            )}
                                            {can.mitigate && violation.status === 'Open' && !violation.exception && (
                                                <PrimaryButton
                                                    onClick={() =>
                                                        router.post(
                                                            route('sod.violations.escalate', violation.id),
                                                            {},
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    Raise exception
                                                </PrimaryButton>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination paginator={violations} />
            </div>

            <ActionModal action={action} controls={controls} onClose={() => setAction(null)} />
        </AuthenticatedLayout>
    );
}

const TITLES = {
    mitigate: 'Record a mitigating arrangement',
    accept: 'Accept this conflict, time-boxed',
    remediate: 'Mark the entitlement removed',
    'false-positive': 'Mark a false positive',
};

const ROUTES = {
    mitigate: 'sod.violations.mitigate',
    accept: 'sod.violations.accept',
    remediate: 'sod.violations.remediate',
    'false-positive': 'sod.violations.false-positive',
};

function ActionModal({ action, controls, onClose }) {
    const form = useForm({ notes: '', accepted_until: '', mitigating_control_id: '' });

    if (!action) return null;

    const { violation, kind } = action;

    const submit = (event) => {
        event.preventDefault();
        form.post(route(ROUTES[kind], violation.id), { onSuccess: onClose, preserveScroll: true });
    };

    return (
        <Modal show={!!action} onClose={onClose} maxWidth="lg" title={TITLES[kind]}>
            <form onSubmit={submit} className="space-y-4">
                <div className="rounded-lg bg-gray-50 p-3 text-sm">
                    <p className="font-medium">{violation.subject_name || violation.subject_identifier}</p>
                    <p className="text-xs text-gray-400">
                        {violation.rule?.function_a} + {violation.rule?.function_b}
                        {violation.rule?.system_key && ` in ${violation.rule.system_key}`}
                    </p>
                </div>

                {kind === 'accept' && (
                    <div>
                        <InputLabel value="Accepted until" required />
                        <TextInput
                            type="date"
                            value={form.data.accepted_until}
                            onChange={(e) => form.setData('accepted_until', e.target.value)}
                        />
                        <p className="mt-1 text-xs text-gray-400">
                            The acceptance reopens automatically on this date — the conflict does not go away because the calendar
                            turned.
                        </p>
                        {form.errors.accepted_until && (
                            <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.accepted_until}</p>
                        )}
                    </div>
                )}

                {kind === 'mitigate' && (
                    <div>
                        <InputLabel value="Mitigating control" />
                        <SelectInput
                            value={form.data.mitigating_control_id}
                            onChange={(e) => form.setData('mitigating_control_id', e.target.value)}
                        >
                            <option value="">None</option>
                            {controls.map((control) => (
                                <option key={control.id} value={control.id}>
                                    {control.control_ref} — {control.title}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                )}

                <div>
                    <InputLabel value="Notes" required />
                    <TextArea
                        rows={4}
                        value={form.data.notes}
                        onChange={(e) => form.setData('notes', e.target.value)}
                        placeholder="What is actually stopping this from being exploited?"
                    />
                    {form.errors.notes && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.notes}</p>}
                    {form.errors.acceptance && (
                        <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.acceptance}</p>
                    )}
                </div>

                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Confirm</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
