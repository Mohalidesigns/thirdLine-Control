import EmptyState from '@/Components/EmptyState';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import StatusBadge from '@/Components/StatusBadge';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import VerificationBadge from '@/Components/VerificationBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Filings({
    filings = [],
    entities = [],
    regulators = [],
    owners = [],
    schedule = [],
    suggestedAdoptionYears = {},
    reportingClasses = [],
    can = {},
}) {
    const [planning, setPlanning] = useState(false);
    const [filing, setFiling] = useState(null);
    const [verifying, setVerifying] = useState(null);

    return (
        <AuthenticatedLayout header="Filing Tracker">
            <Head title="Sustainability Filing Tracker" />

            <PageHeader
                title="Pre-reporting filing tracker"
                subtitle="Stage deadlines computed from each entity's fiscal year start — the offsets are data, and unverified until confirmed"
                breadcrumbs={[{ label: 'Sustainability', href: route('sustainability.index') }, { label: 'Filings' }]}
                actions={
                    <>
                        <Link href={route('sustainability.index')} className="btn-secondary">
                            Materiality
                        </Link>
                        {can.manage && <PrimaryButton onClick={() => setPlanning(true)}>+ Plan a filing</PrimaryButton>}
                    </>
                }
            />

            <section className="card mb-6">
                <div className="card-header">
                    <h2 className="text-sm font-semibold text-[var(--color-primary)]">Stage schedule in force</h2>
                </div>
                <div className="card-body">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        {schedule.map((stage) => (
                            <div key={stage.key} className="rounded-lg border border-gray-100 p-3">
                                <p className="text-sm font-medium text-[var(--color-text-primary)]">{stage.label}</p>
                                <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                                    {stage.offset_months >= 0 ? '+' : ''}
                                    {stage.offset_months} months from the year start
                                </p>
                                {stage.description && <p className="mt-1 text-xs text-gray-400">{stage.description}</p>}
                            </div>
                        ))}
                    </div>
                    <p className="mt-3 text-xs text-[var(--color-text-secondary)]">
                        These offsets are tenant configuration, not code. A filing built from them ships unverified and is
                        excluded from generated submissions until an officer confirms the dates against the regulator's own
                        published schedule.
                    </p>
                </div>
            </section>

            {filings.length === 0 ? (
                <EmptyState
                    title="No filings planned"
                    description="Plan a filing against an entity's fiscal year start to compute its stage deadlines."
                    actionLabel={can.manage ? 'Plan a filing' : undefined}
                    onAction={can.manage ? () => setPlanning(true) : undefined}
                />
            ) : (
                <div className="space-y-4">
                    {filings.map((record) => (
                        <section key={record.id} className="card">
                            <div className="card-header flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">
                                        {record.reference}
                                    </span>
                                    <p className="text-sm font-medium text-[var(--color-text-primary)]">
                                        {record.entity?.legal_name ?? 'Group-wide'} — {record.period_label}
                                    </p>
                                    <p className="text-xs text-gray-400">
                                        {record.reporting_class_label}
                                        {record.adoption_year ? ` · first reports ${record.adoption_year}` : ''} · year starts{' '}
                                        {formatDate(record.fiscal_year_start)}
                                        {record.regulator ? ` · ${record.regulator.code}` : ''}
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <VerificationBadge status={record.verification_status} />
                                    <StatusBadge status={record.status} />
                                    {can.manage && record.verification_status !== 'verified' && (
                                        <button
                                            type="button"
                                            className="text-xs font-semibold text-[var(--color-primary)]"
                                            onClick={() => setVerifying(record)}
                                        >
                                            Confirm deadlines
                                        </button>
                                    )}
                                </div>
                            </div>
                            <div className="card-body overflow-x-auto">
                                <table className="data-table w-full min-w-[36rem]">
                                    <thead>
                                        <tr>
                                            <th>Stage</th>
                                            <th>Due</th>
                                            <th>Status</th>
                                            <th>Filed</th>
                                            <th />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {record.stages.map((stage) => (
                                            <tr key={stage.id}>
                                                <td className="text-sm">{stage.label}</td>
                                                <td className="text-xs">
                                                    {formatDate(stage.due_at)}
                                                    {stage.status !== 'Submitted' && (
                                                        <span
                                                            className={`ml-2 ${
                                                                stage.days_to_due < 0
                                                                    ? 'font-semibold text-[var(--color-error)]'
                                                                    : 'text-gray-400'
                                                            }`}
                                                        >
                                                            {stage.days_to_due < 0
                                                                ? `${Math.abs(stage.days_to_due)}d overdue`
                                                                : `in ${stage.days_to_due}d`}
                                                        </span>
                                                    )}
                                                </td>
                                                <td>
                                                    <StatusBadge status={stage.status} />
                                                </td>
                                                <td className="text-xs text-gray-400">
                                                    {stage.submitted_at
                                                        ? `${formatDate(stage.submitted_at)}${
                                                              stage.submission_reference ? ` · ${stage.submission_reference}` : ''
                                                          }`
                                                        : '—'}
                                                </td>
                                                <td className="text-right text-xs">
                                                    {can.manage && stage.status !== 'Submitted' && (
                                                        <button
                                                            type="button"
                                                            className="font-semibold text-[var(--color-primary)]"
                                                            onClick={() => setFiling({ filing: record, stage })}
                                                        >
                                                            Record filing
                                                        </button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    ))}
                </div>
            )}

            <PlanModal
                show={planning}
                onClose={() => setPlanning(false)}
                entities={entities}
                regulators={regulators}
                owners={owners}
                reportingClasses={reportingClasses}
                suggestedAdoptionYears={suggestedAdoptionYears}
            />
            <FileStageModal target={filing} onClose={() => setFiling(null)} />
            <VerifyFilingModal filing={verifying} onClose={() => setVerifying(null)} />
        </AuthenticatedLayout>
    );
}

function PlanModal({ show, onClose, entities, regulators, owners, reportingClasses, suggestedAdoptionYears }) {
    const form = useForm({
        entity_id: '',
        regulator_id: '',
        reporting_class: 'pie',
        adoption_year: '',
        fiscal_year_start: '',
        period_label: '',
        owner_id: '',
    });

    const suggestion = suggestedAdoptionYears[form.data.reporting_class];

    const submit = (event) => {
        event.preventDefault();
        form.post(route('sustainability.filings.store'), { onSuccess: onClose });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl" title="Plan a filing">
            <form onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel value="Entity" />
                        <SelectInput value={form.data.entity_id} onChange={(e) => form.setData('entity_id', e.target.value)}>
                            <option value="">Group-wide</option>
                            {entities.map((entity) => (
                                <option key={entity.id} value={entity.id}>
                                    {entity.legal_name}
                                    {entity.fiscal_year_end ? ` (year end ${entity.fiscal_year_end})` : ''}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Regulator" />
                        <SelectInput value={form.data.regulator_id} onChange={(e) => form.setData('regulator_id', e.target.value)}>
                            <option value="">—</option>
                            {regulators.map((regulator) => (
                                <option key={regulator.id} value={regulator.id}>
                                    {regulator.code} — {regulator.name}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <InputLabel value="Reporting class" required />
                        <SelectInput
                            value={form.data.reporting_class}
                            onChange={(e) => form.setData('reporting_class', e.target.value)}
                        >
                            {reportingClasses.map((cls) => (
                                <option key={cls.value} value={cls.value}>
                                    {cls.label}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="First reports" />
                        <TextInput
                            type="number"
                            min="2020"
                            max="2100"
                            value={form.data.adoption_year}
                            onChange={(e) => form.setData('adoption_year', e.target.value)}
                            placeholder={suggestion ? String(suggestion) : ''}
                        />
                        {suggestion && (
                            <p className="mt-1 text-[11px] text-[var(--color-text-secondary)]">
                                Roadmap suggests {suggestion} — confirm before relying on it.
                            </p>
                        )}
                    </div>
                    <div>
                        <InputLabel value="Fiscal year start" required />
                        <TextInput
                            type="date"
                            value={form.data.fiscal_year_start}
                            onChange={(e) => form.setData('fiscal_year_start', e.target.value)}
                        />
                        {form.errors.fiscal_year_start && (
                            <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.fiscal_year_start}</p>
                        )}
                    </div>
                    <div>
                        <InputLabel value="Period label" required />
                        <TextInput
                            value={form.data.period_label}
                            onChange={(e) => form.setData('period_label', e.target.value)}
                            placeholder="FY2028"
                        />
                    </div>
                </div>

                <div>
                    <InputLabel value="Owner" />
                    <SelectInput value={form.data.owner_id} onChange={(e) => form.setData('owner_id', e.target.value)}>
                        <option value="">—</option>
                        {owners.map((owner) => (
                            <option key={owner.id} value={owner.id}>
                                {owner.name}
                            </option>
                        ))}
                    </SelectInput>
                </div>

                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Plan filing</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

function FileStageModal({ target, onClose }) {
    const form = useForm({ submission_reference: '', note: '' });

    if (!target) {
        return null;
    }

    const submit = (event) => {
        event.preventDefault();
        form.post(route('sustainability.filings.stages.submit', [target.filing.id, target.stage.id]), {
            onSuccess: onClose,
        });
    };

    return (
        <Modal show onClose={onClose} maxWidth="lg" title={`Record filing — ${target.stage.label}`}>
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel value="Submission reference" />
                    <TextInput
                        value={form.data.submission_reference}
                        onChange={(e) => form.setData('submission_reference', e.target.value)}
                    />
                </div>
                <div>
                    <InputLabel value="Note" />
                    <TextArea rows={2} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                </div>
                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Record</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

function VerifyFilingModal({ filing, onClose }) {
    const form = useForm({ verification_note: '' });

    if (!filing) {
        return null;
    }

    const submit = (event) => {
        event.preventDefault();
        form.post(route('sustainability.filings.verify', filing.id), { onSuccess: onClose });
    };

    return (
        <Modal show onClose={onClose} maxWidth="lg" title="Confirm the filing deadlines">
            <form onSubmit={submit} className="space-y-4">
                <p className="text-xs text-[var(--color-text-secondary)]">
                    Confirm that these stage deadlines have been checked against the regulator's own published schedule.
                    Until they are, this filing is excluded from generated submissions.
                </p>
                <div>
                    <InputLabel value="What was checked, and against what" required />
                    <TextArea
                        rows={3}
                        value={form.data.verification_note}
                        onChange={(e) => form.setData('verification_note', e.target.value)}
                        placeholder="Checked against the FRC roadmap dated …, section …"
                    />
                    {form.errors.verification_note && (
                        <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.verification_note}</p>
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
