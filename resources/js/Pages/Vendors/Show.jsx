import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import RelationshipsPanel from '@/Components/RelationshipsPanel';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import SeverityBadge from '@/Components/SeverityBadge';
import StatusBadge from '@/Components/StatusBadge';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate, formatDateTime } from '@/utils';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Plus } from 'lucide-react';

const RESULT_CLASSES = {
    clear: 'badge-low',
    potential_match: 'badge-medium',
    confirmed_match: 'badge-critical',
    error: 'badge-high',
};

export default function Show({
    vendor,
    assessments = [],
    contracts = [],
    links = [],
    campaigns = [],
    obligations = [],
    owners = [],
    entities = [],
    options = {},
    can = {},
}) {
    const [assessing, setAssessing] = useState(false);
    const [screening, setScreening] = useState(false);
    const [notifying, setNotifying] = useState(false);
    const [contracting, setContracting] = useState(false);
    const [dispositioning, setDispositioning] = useState(null);

    return (
        <AuthenticatedLayout header={vendor.legal_name}>
            <Head title={vendor.legal_name} />

            <PageHeader
                title={vendor.legal_name}
                subtitle={`${vendor.reference} · ${vendor.category ?? 'Uncategorised'} · ${vendor.criticality} criticality`}
                breadcrumbs={[{ label: 'Third parties', href: route('vendors.index') }, { label: vendor.reference }]}
                actions={
                    <>
                        {can.assess && <PrimaryButton onClick={() => setAssessing(true)}>New assessment</PrimaryButton>}
                        {can.screen && <SecondaryButton onClick={() => setScreening(true)}>Record screening</SecondaryButton>}
                        {can.contracts && <SecondaryButton onClick={() => setContracting(true)}>Add contract</SecondaryButton>}
                    </>
                }
            />

            {vendor.notification_outstanding && (
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-[var(--color-warning)] bg-orange-50 p-4">
                    <p className="text-sm text-[var(--color-warning)]">
                        <span className="font-semibold">Regulator notification outstanding.</span> This relationship was
                        assessed as requiring one and none has been recorded.
                    </p>
                    {can.notify && <SecondaryButton onClick={() => setNotifying(true)}>Record notification</SecondaryButton>}
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <section className="card">
                        <div className="card-header">
                            <h2 className="text-sm font-semibold text-[var(--color-primary)]">Due diligence</h2>
                        </div>
                        <div className="card-body space-y-3">
                            {assessments.length === 0 ? (
                                <p className="text-xs text-gray-400">This relationship has never been assessed.</p>
                            ) : (
                                assessments.map((assessment) => (
                                    <AssessmentRow
                                        key={assessment.id}
                                        vendor={vendor}
                                        assessment={assessment}
                                        can={can}
                                    />
                                ))
                            )}
                        </div>
                    </section>

                    <section className="card">
                        <div className="card-header">
                            <h2 className="text-sm font-semibold text-[var(--color-primary)]">Findings</h2>
                        </div>
                        <div className="card-body space-y-2">
                            {(vendor.findings ?? []).length === 0 ? (
                                <p className="text-xs text-gray-400">No findings raised.</p>
                            ) : (
                                vendor.findings.map((finding) => (
                                    <div key={finding.id} className="flex flex-wrap items-start justify-between gap-2 text-xs">
                                        <div>
                                            <span className="font-mono font-semibold text-[var(--color-primary)]">
                                                {finding.reference}
                                            </span>
                                            <p className="text-sm text-[var(--color-text-primary)]">{finding.title}</p>
                                            <p className="text-gray-400">
                                                {finding.owner?.name ?? 'Unowned'}
                                                {finding.due_date ? ` · due ${formatDate(finding.due_date)}` : ''}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <SeverityBadge severity={finding.severity} />
                                            <StatusBadge status={finding.status} />
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>
                    </section>

                    <section className="card">
                        <div className="card-header">
                            <h2 className="text-sm font-semibold text-[var(--color-primary)]">Screening</h2>
                        </div>
                        <div className="card-body space-y-2">
                            {(vendor.screenings ?? []).length === 0 ? (
                                <p className="text-xs text-gray-400">No sanctions, PEP or adverse-media screening recorded.</p>
                            ) : (
                                vendor.screenings.map((record) => (
                                    <div key={record.id} className="rounded-lg border border-gray-100 p-3 text-xs">
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <div>
                                                <span className="font-medium capitalize text-[var(--color-text-primary)]">
                                                    {record.screening_type.replace('_', ' ')}
                                                </span>
                                                <p className="text-gray-400">
                                                    {formatDateTime(record.screened_at)} · {record.hit_count} hit
                                                    {record.hit_count === 1 ? '' : 's'}
                                                </p>
                                            </div>
                                            <span className={`badge ${RESULT_CLASSES[record.result] ?? 'badge-status-draft'}`}>
                                                {record.result.replace('_', ' ')}
                                            </span>
                                        </div>
                                        {record.summary && <p className="mt-2 text-[var(--color-text-secondary)]">{record.summary}</p>}
                                        {record.cleared_at ? (
                                            <p className="mt-2 text-gray-400">
                                                Dispositioned by {record.clearer?.name ?? 'unknown'} on{' '}
                                                {formatDate(record.cleared_at)} — {record.disposition}
                                            </p>
                                        ) : (
                                            ['potential_match', 'confirmed_match'].includes(record.result) && (
                                                <div className="mt-2 flex items-center justify-between">
                                                    <span className="font-semibold text-[var(--color-warning)]">
                                                        Awaiting a named person's decision
                                                    </span>
                                                    {can.screen && (
                                                        <button
                                                            type="button"
                                                            className="font-semibold text-[var(--color-primary)]"
                                                            onClick={() => setDispositioning(record)}
                                                        >
                                                            Disposition
                                                        </button>
                                                    )}
                                                </div>
                                            )
                                        )}
                                    </div>
                                ))
                            )}
                        </div>
                    </section>

                    <section className="card">
                        <div className="card-header">
                            <h2 className="text-sm font-semibold text-[var(--color-primary)]">Contracts & SLAs</h2>
                        </div>
                        <div className="card-body space-y-2">
                            {contracts.length === 0 ? (
                                <p className="text-xs text-gray-400">No contracts registered.</p>
                            ) : (
                                contracts.map((contract) => (
                                    <div key={contract.id} className="rounded-lg border border-gray-100 p-3 text-xs">
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <div>
                                                <span className="font-mono font-semibold text-[var(--color-primary)]">
                                                    {contract.reference}
                                                </span>
                                                <p className="text-sm text-[var(--color-text-primary)]">{contract.title}</p>
                                                <p className="text-gray-400">
                                                    {contract.start_date ? formatDate(contract.start_date) : '—'} →{' '}
                                                    {contract.end_date ? formatDate(contract.end_date) : '—'}
                                                    {contract.notice_deadline
                                                        ? ` · notice by ${formatDate(contract.notice_deadline)}`
                                                        : ''}
                                                    {contract.value ? ` · ${contract.value.formatted}` : ''}
                                                </p>
                                            </div>
                                            <StatusBadge status={contract.status} />
                                        </div>
                                        {(contract.sla_commitments ?? []).length > 0 && (
                                            <ul className="mt-2 space-y-0.5 text-gray-500">
                                                {contract.sla_commitments.map((sla, index) => (
                                                    <li key={index}>
                                                        {sla.label}
                                                        {sla.target ? `: ${sla.target}` : ''}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </div>
                                ))
                            )}
                        </div>
                    </section>

                    <RelationshipsPanel type="vendor" id={vendor.id} links={links} canManage={can.manage} />
                </div>

                <div className="space-y-6">
                    <section className="card">
                        <div className="card-header">
                            <h2 className="text-sm font-semibold text-[var(--color-primary)]">Relationship</h2>
                        </div>
                        <div className="card-body space-y-2 text-xs">
                            <Detail label="Status" value={<StatusBadge status={vendor.status} />} />
                            <Detail label="Owner" value={vendor.owner?.name ?? 'Unowned'} />
                            <Detail label="Entity" value={vendor.entity?.legal_name ?? 'Group-wide'} />
                            <Detail label="Incorporated" value={vendor.jurisdiction} />
                            <Detail label="Processes data in" value={vendor.processing_country ?? vendor.jurisdiction} />
                            <Detail label="Annual spend" value={vendor.annual_spend?.formatted ?? '—'} />
                            <Detail
                                label="Data access"
                                value={
                                    <span className={vendor.touches_personal_data ? 'font-semibold text-[var(--color-warning)]' : ''}>
                                        {vendor.data_access_classification.replace('_', ' ')}
                                    </span>
                                }
                            />
                            <Detail label="Review cycle" value={`${vendor.review_frequency_months} months`} />
                            {vendor.regulator_notified_at && (
                                <Detail
                                    label="Regulator notified"
                                    value={`${formatDate(vendor.regulator_notified_at)} (${vendor.regulator_notification_ref})`}
                                />
                            )}
                            {vendor.services_provided && (
                                <p className="pt-2 text-[var(--color-text-secondary)]">{vendor.services_provided}</p>
                            )}
                        </div>
                    </section>

                    {(vendor.sub_processors ?? []).length > 0 && (
                        <section className="card">
                            <div className="card-header">
                                <h2 className="text-sm font-semibold text-[var(--color-primary)]">Sub-processors</h2>
                            </div>
                            <div className="card-body space-y-1 text-xs">
                                {vendor.sub_processors.map((processor, index) => (
                                    <p key={index}>
                                        <span className="font-medium">{processor.name}</span>
                                        {processor.service ? ` — ${processor.service}` : ''}
                                        {processor.country ? ` (${processor.country})` : ''}
                                    </p>
                                ))}
                            </div>
                        </section>
                    )}
                </div>
            </div>

            <AssessmentModal
                show={assessing}
                onClose={() => setAssessing(false)}
                vendor={vendor}
                campaigns={campaigns}
                options={options}
            />
            <ScreeningModal show={screening} onClose={() => setScreening(false)} vendor={vendor} options={options} />
            <DispositionModal
                screening={dispositioning}
                onClose={() => setDispositioning(null)}
                vendor={vendor}
                options={options}
            />
            <NotificationModal
                show={notifying}
                onClose={() => setNotifying(false)}
                vendor={vendor}
                obligations={obligations}
            />
            <ContractModal
                show={contracting}
                onClose={() => setContracting(false)}
                vendor={vendor}
                entities={entities}
                owners={owners}
                obligations={obligations}
                options={options}
            />
        </AuthenticatedLayout>
    );
}

function Detail({ label, value }) {
    return (
        <div className="flex items-center justify-between gap-3">
            <span className="text-[var(--color-text-secondary)]">{label}</span>
            <span className="text-right font-medium text-[var(--color-text-primary)]">{value}</span>
        </div>
    );
}

function AssessmentRow({ vendor, assessment, can }) {
    const [rejecting, setRejecting] = useState(false);
    const rejectForm = useForm({ reason: '' });

    return (
        <div className="rounded-lg border border-gray-100 p-3 text-xs">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <span className="font-mono font-semibold text-[var(--color-primary)]">{assessment.reference}</span>
                    <p className="text-sm capitalize text-[var(--color-text-primary)]">
                        {assessment.assessment_type} assessment
                        {assessment.risk_band ? ` · ${assessment.risk_band}` : ''}
                        {assessment.risk_score !== null ? ` · score ${assessment.risk_score}` : ''}
                    </p>
                    <p className="text-gray-400">
                        {assessment.assessor?.name ? `Assessed by ${assessment.assessor.name}` : 'Not yet submitted'}
                        {assessment.approver?.name ? ` · approved by ${assessment.approver.name}` : ''}
                        {assessment.expires_at ? ` · review due ${formatDate(assessment.expires_at)}` : ''}
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <StatusBadge status={assessment.status} />
                    {assessment.days_to_expiry !== null && assessment.days_to_expiry < 0 && (
                        <span className="text-[10px] font-semibold uppercase text-[var(--color-error)]">
                            {Math.abs(assessment.days_to_expiry)}d overdue
                        </span>
                    )}
                </div>
            </div>

            {assessment.conclusion && <p className="mt-2 text-[var(--color-text-secondary)]">{assessment.conclusion}</p>}

            {assessment.status === 'Submitted' && can.approve && (
                <div className="mt-3 flex items-center gap-3">
                    <button
                        type="button"
                        className="font-semibold text-[var(--color-primary)]"
                        onClick={() => router.post(route('vendors.assessments.approve', [vendor.id, assessment.id]))}
                    >
                        Approve
                    </button>
                    <button type="button" className="font-semibold text-[var(--color-error)]" onClick={() => setRejecting(true)}>
                        Return to assessor
                    </button>
                    <span className="text-gray-400">The assessor cannot approve their own conclusion.</span>
                </div>
            )}

            <Modal show={rejecting} onClose={() => setRejecting(false)} maxWidth="lg" title="Return to the assessor">
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        rejectForm.post(route('vendors.assessments.reject', [vendor.id, assessment.id]), {
                            onSuccess: () => setRejecting(false),
                        });
                    }}
                    className="space-y-4"
                >
                    <div>
                        <InputLabel value="Reason" required />
                        <TextArea
                            rows={3}
                            value={rejectForm.data.reason}
                            onChange={(e) => rejectForm.setData('reason', e.target.value)}
                        />
                    </div>
                    <div className="flex justify-end gap-2">
                        <SecondaryButton type="button" onClick={() => setRejecting(false)}>
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton disabled={rejectForm.processing}>Return</PrimaryButton>
                    </div>
                </form>
            </Modal>
        </div>
    );
}

function AssessmentModal({ show, onClose, vendor, campaigns, options }) {
    const form = useForm({
        assessment_type: 'periodic',
        period_label: '',
        campaign_id: '',
        risk_score: '',
        risk_band: '',
        conclusion: '',
        review_frequency_months: vendor.review_frequency_months ?? 12,
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('vendors.assessments.store', vendor.id), { onSuccess: onClose });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl" title="Open a due-diligence assessment">
            <form onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel value="Type" required />
                        <SelectInput
                            value={form.data.assessment_type}
                            onChange={(e) => form.setData('assessment_type', e.target.value)}
                        >
                            {(options.assessment_types ?? []).map((type) => (
                                <option key={type} value={type}>
                                    {type}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Period" />
                        <TextInput
                            value={form.data.period_label}
                            onChange={(e) => form.setData('period_label', e.target.value)}
                            placeholder="FY2026"
                        />
                    </div>
                </div>
                <div>
                    <InputLabel value="Questionnaire campaign" />
                    <SelectInput value={form.data.campaign_id} onChange={(e) => form.setData('campaign_id', e.target.value)}>
                        <option value="">None — recorded by hand</option>
                        {campaigns.map((campaign) => (
                            <option key={campaign.id} value={campaign.id}>
                                {campaign.reference} — {campaign.name}
                            </option>
                        ))}
                    </SelectInput>
                    <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                        Due-diligence questionnaires use the existing campaign engine, so questions, scoring and responses
                        live where every other questionnaire does.
                    </p>
                </div>
                <div className="grid grid-cols-3 gap-4">
                    <div>
                        <InputLabel value="Risk score" />
                        <TextInput
                            type="number"
                            min="0"
                            max="100"
                            value={form.data.risk_score}
                            onChange={(e) => form.setData('risk_score', e.target.value)}
                        />
                    </div>
                    <div>
                        <InputLabel value="Band" />
                        <SelectInput value={form.data.risk_band} onChange={(e) => form.setData('risk_band', e.target.value)}>
                            <option value="">—</option>
                            {(options.bands ?? []).map((band) => (
                                <option key={band} value={band}>
                                    {band}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Review every (months)" required />
                        <TextInput
                            type="number"
                            min="1"
                            max="60"
                            value={form.data.review_frequency_months}
                            onChange={(e) => form.setData('review_frequency_months', e.target.value)}
                        />
                    </div>
                </div>
                <div>
                    <InputLabel value="Conclusion" />
                    <TextArea rows={3} value={form.data.conclusion} onChange={(e) => form.setData('conclusion', e.target.value)} />
                </div>
                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Open assessment</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

function ScreeningModal({ show, onClose, vendor, options }) {
    const form = useForm({ screening_type: 'sanctions', result: 'clear', summary: '', hits: [] });

    const addHit = () => form.setData('hits', [...form.data.hits, { name: '', source: '', detail: '' }]);

    const setHit = (index, field, value) => {
        const next = [...form.data.hits];
        next[index] = { ...next[index], [field]: value };
        form.setData('hits', next);
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(route('vendors.screenings.store', vendor.id), { onSuccess: onClose });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl" title="Record a screening">
            <form onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel value="Type" required />
                        <SelectInput
                            value={form.data.screening_type}
                            onChange={(e) => form.setData('screening_type', e.target.value)}
                        >
                            {(options.screening_types ?? []).map((type) => (
                                <option key={type} value={type}>
                                    {type.replace('_', ' ')}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Result" required />
                        <SelectInput value={form.data.result} onChange={(e) => form.setData('result', e.target.value)}>
                            {(options.screening_results ?? []).map((result) => (
                                <option key={result} value={result}>
                                    {result.replace('_', ' ')}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                </div>
                <div>
                    <InputLabel value="Summary" />
                    <TextArea rows={2} value={form.data.summary} onChange={(e) => form.setData('summary', e.target.value)} />
                </div>
                <div>
                    <div className="mb-2 flex items-center justify-between">
                        <InputLabel value="Hits" />
                        <button type="button" className="text-xs font-semibold text-[var(--color-primary)]" onClick={addHit}>
                            <Plus className="h-4 w-4" strokeWidth={2} /> Add hit
                        </button>
                    </div>
                    <div className="space-y-2">
                        {form.data.hits.map((hit, index) => (
                            <div key={index} className="grid grid-cols-3 gap-2">
                                <TextInput placeholder="Name" value={hit.name} onChange={(e) => setHit(index, 'name', e.target.value)} />
                                <TextInput
                                    placeholder="Source"
                                    value={hit.source}
                                    onChange={(e) => setHit(index, 'source', e.target.value)}
                                />
                                <TextInput
                                    placeholder="Detail"
                                    value={hit.detail}
                                    onChange={(e) => setHit(index, 'detail', e.target.value)}
                                />
                            </div>
                        ))}
                    </div>
                    <p className="mt-2 text-xs text-[var(--color-text-secondary)]">
                        A match is never cleared automatically — it stays open until a named person records a decision.
                    </p>
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

function DispositionModal({ screening, onClose, vendor, options }) {
    const form = useForm({ disposition: '', raise_finding: false, severity: 'High' });

    if (!screening) {
        return null;
    }

    const submit = (event) => {
        event.preventDefault();
        form.post(route('vendors.screenings.disposition', [vendor.id, screening.id]), { onSuccess: onClose });
    };

    return (
        <Modal show onClose={onClose} maxWidth="lg" title="Disposition the screening hit">
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel value="Decision" required />
                    <TextArea
                        rows={3}
                        value={form.data.disposition}
                        onChange={(e) => form.setData('disposition', e.target.value)}
                        placeholder="False positive — different legal entity, confirmed against RC number."
                    />
                </div>
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        className="rounded border-gray-300"
                        checked={form.data.raise_finding}
                        onChange={(e) => form.setData('raise_finding', e.target.checked)}
                    />
                    Raise a finding to be worked
                </label>
                {form.data.raise_finding && (
                    <div>
                        <InputLabel value="Severity" />
                        <SelectInput value={form.data.severity} onChange={(e) => form.setData('severity', e.target.value)}>
                            {(options.severities ?? []).map((severity) => (
                                <option key={severity} value={severity}>
                                    {severity}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                )}
                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Record decision</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

function NotificationModal({ show, onClose, vendor, obligations }) {
    const form = useForm({
        regulator_notified_at: '',
        regulator_notification_ref: '',
        notification_obligation_id: vendor.notification_obligation_id ?? '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('vendors.notification.store', vendor.id), { onSuccess: onClose });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="lg" title="Record the regulator notification">
            <form onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel value="Date filed" required />
                        <TextInput
                            type="date"
                            value={form.data.regulator_notified_at}
                            onChange={(e) => form.setData('regulator_notified_at', e.target.value)}
                        />
                        {form.errors.regulator_notified_at && (
                            <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.regulator_notified_at}</p>
                        )}
                    </div>
                    <div>
                        <InputLabel value="Reference" required />
                        <TextInput
                            value={form.data.regulator_notification_ref}
                            onChange={(e) => form.setData('regulator_notification_ref', e.target.value)}
                        />
                    </div>
                </div>
                <div>
                    <InputLabel value="Obligation" />
                    <SelectInput
                        value={form.data.notification_obligation_id}
                        onChange={(e) => form.setData('notification_obligation_id', e.target.value)}
                    >
                        <option value="">—</option>
                        {obligations.map((obligation) => (
                            <option key={obligation.id} value={obligation.id}>
                                {obligation.obligation_ref} — {obligation.title}
                                {obligation.verification_status === 'unverified' ? ' (unverified)' : ''}
                            </option>
                        ))}
                    </SelectInput>
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

function ContractModal({ show, onClose, vendor, entities, owners, obligations, options }) {
    const form = useForm({
        entity_id: vendor.entity_id ?? '',
        title: '',
        contract_type: '',
        start_date: '',
        end_date: '',
        auto_renew: false,
        renewal_notice_days: 90,
        value_minor: '',
        currency: vendor.spend_currency ?? 'NGN',
        sla_commitments: [],
        obligation_id: '',
        owner_id: vendor.owner_id ?? '',
        status: 'Active',
    });

    const addSla = () =>
        form.setData('sla_commitments', [...form.data.sla_commitments, { label: '', target: '', measure: '' }]);

    const setSla = (index, field, value) => {
        const next = [...form.data.sla_commitments];
        next[index] = { ...next[index], [field]: value };
        form.setData('sla_commitments', next);
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(route('vendors.contracts.store', vendor.id), { onSuccess: onClose });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl" title="Register a contract">
            <form onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel value="Title" required />
                        <TextInput value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                        {form.errors.title && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.title}</p>}
                    </div>
                    <div>
                        <InputLabel value="Type" />
                        <TextInput
                            value={form.data.contract_type}
                            onChange={(e) => form.setData('contract_type', e.target.value)}
                            placeholder="Master services agreement"
                        />
                    </div>
                </div>
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <InputLabel value="Start" />
                        <TextInput type="date" value={form.data.start_date} onChange={(e) => form.setData('start_date', e.target.value)} />
                    </div>
                    <div>
                        <InputLabel value="End" />
                        <TextInput type="date" value={form.data.end_date} onChange={(e) => form.setData('end_date', e.target.value)} />
                    </div>
                    <div>
                        <InputLabel value="Notice (days)" required />
                        <TextInput
                            type="number"
                            min="0"
                            value={form.data.renewal_notice_days}
                            onChange={(e) => form.setData('renewal_notice_days', e.target.value)}
                        />
                    </div>
                    <div>
                        <InputLabel value="Status" required />
                        <SelectInput value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                            {(options.contract_statuses ?? []).map((status) => (
                                <option key={status} value={status}>
                                    {status}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                </div>
                <div className="grid grid-cols-3 gap-4">
                    <div className="col-span-2">
                        <InputLabel value="Contract value" />
                        <TextInput
                            type="number"
                            min="0"
                            value={form.data.value_minor === '' ? '' : form.data.value_minor / 100}
                            onChange={(e) =>
                                form.setData('value_minor', e.target.value === '' ? '' : Math.round(Number(e.target.value) * 100))
                            }
                        />
                    </div>
                    <div>
                        <InputLabel value="Currency" required />
                        <SelectInput value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value)}>
                            {['NGN', 'GHS', 'KES', 'ZAR', 'USD', 'EUR', 'GBP'].map((code) => (
                                <option key={code} value={code}>
                                    {code}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                </div>

                <div>
                    <div className="mb-2 flex items-center justify-between">
                        <InputLabel value="SLA commitments" />
                        <button type="button" className="text-xs font-semibold text-[var(--color-primary)]" onClick={addSla}>
                            <Plus className="h-4 w-4" strokeWidth={2} /> Add
                        </button>
                    </div>
                    <div className="space-y-2">
                        {form.data.sla_commitments.map((sla, index) => (
                            <div key={index} className="grid grid-cols-3 gap-2">
                                <TextInput placeholder="Commitment" value={sla.label} onChange={(e) => setSla(index, 'label', e.target.value)} />
                                <TextInput placeholder="Target" value={sla.target} onChange={(e) => setSla(index, 'target', e.target.value)} />
                                <TextInput
                                    placeholder="Measured by"
                                    value={sla.measure}
                                    onChange={(e) => setSla(index, 'measure', e.target.value)}
                                />
                            </div>
                        ))}
                    </div>
                </div>

                <div>
                    <InputLabel value="Discharges obligation" />
                    <SelectInput value={form.data.obligation_id} onChange={(e) => form.setData('obligation_id', e.target.value)}>
                        <option value="">—</option>
                        {obligations.map((obligation) => (
                            <option key={obligation.id} value={obligation.id}>
                                {obligation.obligation_ref} — {obligation.title}
                            </option>
                        ))}
                    </SelectInput>
                </div>

                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Register contract</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
