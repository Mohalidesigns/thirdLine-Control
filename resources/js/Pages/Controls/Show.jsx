import ActivityTab from '@/Components/ActivityTab';
import ConfirmDialog from '@/Components/ConfirmDialog';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import SeverityBadge from '@/Components/SeverityBadge';
import StatusBadge from '@/Components/StatusBadge';
import TextArea from '@/Components/TextArea';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate, formatDateTime } from '@/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

function Field({ label, children }) {
    return (
        <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">{label}</p>
            <div className="mt-0.5 text-sm text-[var(--color-text-primary)]">{children ?? '—'}</div>
        </div>
    );
}

function SummaryCard({ title, value, valueClass = '', footer = null }) {
    return (
        <div className="card">
            <div className="card-body">
                <p className="text-sm text-[var(--color-text-secondary)]">{title}</p>
                <p className={`mt-1 text-2xl font-bold ${valueClass}`}>{value ?? '—'}</p>
                {footer && <p className="mt-1 text-xs text-gray-400">{footer}</p>}
            </div>
        </div>
    );
}

const TABS = ['Overview', 'Linked Risks', 'Assessment History', 'Assess', 'Activity'];

export default function Show({
    control,
    recentTests = [],
    openExceptions = [],
    ratings = [],
    assessments = [],
    designRatings = [],
    operatingRatings = [],
    can = {},
}) {
    const [tab, setTab] = useState('Overview');
    const [showRetire, setShowRetire] = useState(false);
    const [showReject, setShowReject] = useState(false);
    const rejectForm = useForm({ reason: '' });

    const designForm = useForm({ design_result: '', notes: '' });
    const operatingForm = useForm({ operating_result: '', notes: '' });

    const submitDesign = (e) => {
        e.preventDefault();
        designForm.post(route('controls.assess', control.id), {
            preserveScroll: true,
            onSuccess: () => designForm.reset(),
        });
    };

    const submitOperating = (e) => {
        e.preventDefault();
        operatingForm.post(route('controls.assess', control.id), {
            preserveScroll: true,
            onSuccess: () => operatingForm.reset(),
        });
    };

    const tabLabel = (name) => {
        if (name === 'Linked Risks') return `Linked Risks (${control.risks?.length ?? 0})`;
        if (name === 'Assessment History') return `Assessment History (${assessments.length})`;
        return name;
    };

    const visibleTabs = TABS.filter((name) => name !== 'Assess' || can.assess);

    return (
        <AuthenticatedLayout header={control.control_ref}>
            <Head title={control.control_ref} />

            <PageHeader
                title={control.title}
                subtitle={<span className="font-mono">{control.control_ref} · v{control.current_version}</span>}
                breadcrumbs={[{ label: 'Control Library', href: route('controls.index') }, { label: control.title }]}
                actions={
                    <>
                        {can.update && control.status === 'Draft' && (
                            <PrimaryButton onClick={() => router.post(route('controls.submit', control.id))}>
                                Submit for approval
                            </PrimaryButton>
                        )}
                        {can.approve && (
                            <>
                                <PrimaryButton onClick={() => router.post(route('controls.approve', control.id))}>
                                    Approve
                                </PrimaryButton>
                                <SecondaryButton onClick={() => setShowReject(true)}>Return to draft</SecondaryButton>
                            </>
                        )}
                        {can.update && (
                            <Link href={route('controls.edit', control.id)} className="btn-secondary">
                                Edit
                            </Link>
                        )}
                        {can.retire && control.status === 'Active' && (
                            <SecondaryButton onClick={() => setShowRetire(true)}>Retire</SecondaryButton>
                        )}
                    </>
                }
            />

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <StatusBadge status={control.status} />
                <StatusBadge status={control.type} className="bg-blue-50 text-blue-700" />
                <StatusBadge status={control.design_effectiveness} />
                <StatusBadge status={control.operating_effectiveness} />
                {control.is_key_control && <span className="badge bg-[var(--color-accent)]/15 text-[#8a6d1a]">Key control</span>}
            </div>

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <SummaryCard
                    title="Design Effectiveness"
                    value={control.design_effectiveness}
                    valueClass={control.design_effectiveness === 'Inadequate' ? 'text-[var(--color-error)]' : 'text-[var(--color-text-primary)]'}
                />
                <SummaryCard
                    title="Operating Effectiveness"
                    value={control.operating_effectiveness}
                    valueClass={control.operating_effectiveness === 'Ineffective' ? 'text-[var(--color-error)]' : 'text-[var(--color-text-primary)]'}
                />
                <SummaryCard title="Overall Score" value={control.overall_rating ?? 'Not Rated'} />
                <SummaryCard
                    title="Test Status"
                    value={`${recentTests.length} Test${recentTests.length === 1 ? '' : 's'}`}
                    footer={control.last_assessed_at ? `Last: ${formatDate(control.last_assessed_at)}` : 'Not yet assessed'}
                />
            </div>

            <div className="mb-6 border-b border-gray-200">
                <nav className="-mb-px flex gap-6">
                    {visibleTabs.map((name) => (
                        <button
                            key={name}
                            type="button"
                            onClick={() => setTab(name)}
                            className={`whitespace-nowrap border-b-2 pb-3 text-sm font-medium transition-colors ${
                                tab === name
                                    ? 'border-[var(--color-primary)] text-[var(--color-primary)]'
                                    : 'border-transparent text-gray-500 hover:text-[var(--color-text-primary)]'
                            }`}
                        >
                            {tabLabel(name)}
                        </button>
                    ))}
                </nav>
            </div>

            {tab === 'Overview' && (
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        <div className="card">
                            <div className="card-header"><h3 className="text-sm font-semibold">Control Information</h3></div>
                            <div className="card-body space-y-4">
                                {control.description && (
                                    <p className="text-sm text-[var(--color-text-primary)]">{control.description}</p>
                                )}
                                {control.objective && <Field label="Objective">{control.objective}</Field>}
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <Field label="Control Reference">
                                        <span className="font-mono">{control.control_ref}</span>
                                    </Field>
                                    <Field label="Entity">{control.unit?.name}</Field>
                                    <Field label="Department">{control.department}</Field>
                                    <Field label="Control Owner">{control.owner?.name}</Field>
                                </div>
                            </div>
                        </div>

                        <div className="card">
                            <div className="card-header"><h3 className="text-sm font-semibold">Classification</h3></div>
                            <div className="card-body space-y-4">
                                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                                    <Field label="Control Type">{control.type}</Field>
                                    <Field label="Nature">{control.nature}</Field>
                                    <Field label="Frequency">{control.frequency}</Field>
                                    <Field label="Category">{control.category?.name}</Field>
                                    <Field label="COSO Component">{control.coso_component}</Field>
                                    <Field label="Test Frequency">{control.test_frequency}</Field>
                                </div>
                                {control.framework_refs?.length > 0 && (
                                    <Field label="Framework references">
                                        <div className="flex flex-wrap gap-1.5">
                                            {control.framework_refs.map((ref) => (
                                                <span key={ref} className="badge badge-status-draft">{ref}</span>
                                            ))}
                                        </div>
                                    </Field>
                                )}
                            </div>
                        </div>

                        <div className="card">
                            <div className="card-header"><h3 className="text-sm font-semibold">Control Documentation</h3></div>
                            <div className="card-body">
                                <p className="text-sm text-[var(--color-text-primary)] whitespace-pre-wrap">
                                    {control.control_documentation || <span className="text-gray-400">No documentation referenced.</span>}
                                </p>
                            </div>
                        </div>

                        <div className="card">
                            <div className="card-header"><h3 className="text-sm font-semibold">Notes</h3></div>
                            <div className="card-body">
                                <p className="text-sm text-[var(--color-text-primary)] whitespace-pre-wrap">
                                    {control.notes || <span className="text-gray-400">No additional notes.</span>}
                                </p>
                            </div>
                        </div>

                        <div className="card">
                            <div className="card-header">
                                <h3 className="text-sm font-semibold">Recent tests</h3>
                                <Link href={route('test-scripts.create', { control_id: control.id })} className="text-sm text-[var(--color-primary)] hover:underline">
                                    Build test script
                                </Link>
                            </div>
                            <div className="card-body">
                                {recentTests.length ? (
                                    <ul className="divide-y divide-gray-100">
                                        {recentTests.map((test) => (
                                            <li key={test.id} className="flex items-center justify-between py-2 text-sm">
                                                <Link href={route('test-instances.show', test.id)} className="text-[var(--color-primary)] hover:underline">
                                                    <span className="font-mono text-xs">{test.reference}</span> · {test.period_label}
                                                </Link>
                                                <div className="flex items-center gap-2">
                                                    <span className="text-xs text-gray-500">{test.tester?.name ?? 'Unassigned'}</span>
                                                    <StatusBadge status={test.status} />
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="text-sm text-gray-400">No test instances yet.</p>
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="space-y-6">
                        <div className="card">
                            <div className="card-header"><h3 className="text-sm font-semibold">Effectiveness Summary</h3></div>
                            <div className="card-body space-y-3">
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-gray-600">Design</span>
                                    <StatusBadge status={control.design_effectiveness} />
                                </div>
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-gray-600">Operating</span>
                                    <StatusBadge status={control.operating_effectiveness} />
                                </div>
                                <div className="flex items-center justify-between border-t border-gray-100 pt-3 text-sm">
                                    <span className="font-medium text-[var(--color-text-primary)]">Overall</span>
                                    {control.overall_rating ? <StatusBadge status={control.overall_rating} /> : <span className="text-gray-400">—</span>}
                                </div>
                            </div>
                        </div>

                        <div className="card">
                            <div className="card-header"><h3 className="text-sm font-semibold">Quick Actions</h3></div>
                            <div className="card-body space-y-2">
                                {can.assess && (
                                    <button type="button" className="btn-primary w-full justify-center" onClick={() => setTab('Assess')}>
                                        Assess Control
                                    </button>
                                )}
                                {can.update && (
                                    <Link href={route('controls.edit', control.id)} className="btn-secondary w-full justify-center">
                                        Edit Control
                                    </Link>
                                )}
                            </div>
                        </div>

                        <div className="card">
                            <div className="card-header"><h3 className="text-sm font-semibold">Open exceptions</h3></div>
                            <div className="card-body">
                                {openExceptions.length ? (
                                    <ul className="space-y-2">
                                        {openExceptions.map((exception) => (
                                            <li key={exception.id} className="text-sm">
                                                <Link href={route('exceptions.show', exception.id)} className="flex items-center justify-between gap-2 hover:underline">
                                                    <span className="truncate text-[var(--color-primary)]">
                                                        <span className="font-mono text-xs">{exception.reference}</span> {exception.title}
                                                    </span>
                                                    <SeverityBadge severity={exception.severity} />
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="text-sm text-gray-400">None — nothing outstanding.</p>
                                )}
                            </div>
                        </div>

                        <div className="card">
                            <div className="card-header"><h3 className="text-sm font-semibold">Version history</h3></div>
                            <div className="card-body">
                                <ol className="relative space-y-4 border-s border-gray-200 ps-4">
                                    {(control.versions ?? []).map((version) => (
                                        <li key={version.id} className="relative">
                                            <span className="absolute -start-[21px] top-1 h-2.5 w-2.5 rounded-full bg-[var(--color-accent)]" />
                                            <p className="text-sm font-medium text-[var(--color-text-primary)]">
                                                v{version.version_no}
                                                {version.version_no === control.current_version && (
                                                    <span className="ms-2 badge badge-status-active">Current</span>
                                                )}
                                            </p>
                                            <p className="text-xs text-gray-500">{version.change_reason}</p>
                                            <p className="text-xs text-gray-400">
                                                {version.author?.name ?? '—'} · {formatDate(version.effective_from)}
                                                {version.effective_to ? ` → ${formatDate(version.effective_to)}` : ''}
                                            </p>
                                        </li>
                                    ))}
                                </ol>
                            </div>
                        </div>

                        <div className="card">
                            <div className="card-header"><h3 className="text-sm font-semibold">Metadata</h3></div>
                            <div className="card-body space-y-3">
                                <Field label="Created">{formatDate(control.created_at)}</Field>
                                <Field label="Last Updated">{formatDate(control.updated_at)}</Field>
                                {control.approved_at && (
                                    <Field label="Approved">
                                        {control.approver?.name} · {formatDateTime(control.approved_at)}
                                    </Field>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {tab === 'Linked Risks' && (
                <div className="card">
                    <div className="card-header"><h3 className="text-sm font-semibold">Linked Risks</h3></div>
                    {control.risks?.length ? (
                        <div className="overflow-x-auto">
                            <table className="data-table">
                                <thead>
                                    <tr>
                                        <th>Risk ID</th>
                                        <th>Risk Title</th>
                                        <th>Risk Category</th>
                                        <th>Inherent Rating</th>
                                        <th>Residual Rating</th>
                                        <th>Linked Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {control.risks.map((risk) => (
                                        <tr key={risk.id} className="cursor-pointer" onClick={() => router.visit(route('risks.show', risk.id))}>
                                            <td><span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{risk.code}</span></td>
                                            <td className="font-medium text-[var(--color-text-primary)]">{risk.title}</td>
                                            <td>{risk.category ?? '—'}</td>
                                            <td><SeverityBadge severity={risk.inherent_rating} /></td>
                                            <td><SeverityBadge severity={risk.residual_rating} /></td>
                                            <td>{risk.pivot?.mapped_at ? formatDate(risk.pivot.mapped_at) : '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="card-body">
                            <p className="text-sm text-[var(--color-warning)]">Orphan control — no mapped risk.</p>
                        </div>
                    )}
                </div>
            )}

            {tab === 'Assessment History' && (
                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold">Assessment History</h3>
                        {can.assess && (
                            <button type="button" className="btn-primary" onClick={() => setTab('Assess')}>
                                New Assessment
                            </button>
                        )}
                    </div>
                    {assessments.length ? (
                        <div className="overflow-x-auto">
                            <table className="data-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Period</th>
                                        <th>Design Result</th>
                                        <th>Operating Result</th>
                                        <th>Assessed By</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {assessments.map((assessment) => (
                                        <tr key={assessment.id}>
                                            <td>{formatDate(assessment.created_at)}</td>
                                            <td>{assessment.period_label}</td>
                                            <td>{assessment.design_result ? <StatusBadge status={assessment.design_result} /> : '—'}</td>
                                            <td>{assessment.operating_result ? <StatusBadge status={assessment.operating_result} /> : '—'}</td>
                                            <td>{assessment.assessor?.name ?? '—'}</td>
                                            <td className="max-w-xs truncate">{assessment.notes ?? '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="card-body">
                            <p className="text-sm text-gray-400">No assessments recorded yet.</p>
                        </div>
                    )}
                </div>
            )}

            {tab === 'Activity' && (
                <div className="card">
                    <div className="card-body">
                        <ActivityTab alias="control" entityId={control.id} />
                    </div>
                </div>
            )}

            {tab === 'Assess' && can.assess && (
                <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <form onSubmit={submitDesign} className="card">
                        <div className="card-header flex-col items-start gap-1">
                            <h3 className="text-sm font-semibold">Design Effectiveness Assessment</h3>
                            <p className="text-xs font-normal text-gray-500">
                                Evaluate whether the control is appropriately designed to mitigate the identified risk
                            </p>
                        </div>
                        <div className="card-body space-y-4">
                            <div>
                                <InputLabel htmlFor="design_result" value="Design Effectiveness" required />
                                <SelectInput
                                    id="design_result"
                                    value={designForm.data.design_result}
                                    onChange={(e) => designForm.setData('design_result', e.target.value)}
                                >
                                    <option value="">— Select —</option>
                                    <option value="Adequate">Adequate - Control is properly designed</option>
                                    <option value="Inadequate">Inadequate - Control design has gaps</option>
                                </SelectInput>
                                <InputError message={designForm.errors.design_result} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="design_notes" value="Assessment Notes" />
                                <TextArea
                                    id="design_notes"
                                    rows={4}
                                    value={designForm.data.notes}
                                    onChange={(e) => designForm.setData('notes', e.target.value)}
                                    placeholder="Document the rationale for your assessment, key observations, and any recommendations…"
                                />
                                <InputError message={designForm.errors.notes} className="mt-1" />
                            </div>
                            <div className="flex justify-end">
                                <PrimaryButton disabled={designForm.processing || !designForm.data.design_result}>
                                    Submit Design Assessment
                                </PrimaryButton>
                            </div>
                        </div>
                    </form>

                    <form onSubmit={submitOperating} className="card">
                        <div className="card-header flex-col items-start gap-1">
                            <h3 className="text-sm font-semibold">Operating Effectiveness Assessment</h3>
                            <p className="text-xs font-normal text-gray-500">
                                Evaluate whether the control is operating as designed over the assessment period
                            </p>
                        </div>
                        <div className="card-body space-y-4">
                            <div>
                                <InputLabel htmlFor="operating_result" value="Operating Effectiveness" required />
                                <SelectInput
                                    id="operating_result"
                                    value={operatingForm.data.operating_result}
                                    onChange={(e) => operatingForm.setData('operating_result', e.target.value)}
                                >
                                    <option value="">— Select —</option>
                                    <option value="Effective">Effective - Control is operating as designed</option>
                                    <option value="Partially Effective">Partially Effective - Some exceptions noted</option>
                                    <option value="Ineffective">Ineffective - Control is not operating as designed</option>
                                </SelectInput>
                                <InputError message={operatingForm.errors.operating_result} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="operating_notes" value="Assessment Notes" />
                                <TextArea
                                    id="operating_notes"
                                    rows={4}
                                    value={operatingForm.data.notes}
                                    onChange={(e) => operatingForm.setData('notes', e.target.value)}
                                    placeholder="Document the testing performed, sample sizes, exceptions noted, and conclusions…"
                                />
                                <InputError message={operatingForm.errors.notes} className="mt-1" />
                            </div>
                            <div className="flex justify-end">
                                <PrimaryButton disabled={operatingForm.processing || !operatingForm.data.operating_result}>
                                    Submit Operating Assessment
                                </PrimaryButton>
                            </div>
                        </div>
                    </form>
                </div>
            )}

            <ConfirmDialog
                show={showRetire}
                title={`Retire ${control.control_ref}?`}
                message="A retired control stops generating test instances. This transition cannot be reversed."
                confirmLabel="Retire control"
                onConfirm={() => {
                    router.post(route('controls.retire', control.id));
                    setShowRetire(false);
                }}
                onCancel={() => setShowRetire(false)}
            />

            <Modal show={showReject} maxWidth="md" onClose={() => setShowReject(false)} title="Return to draft">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        rejectForm.post(route('controls.reject', control.id), { onSuccess: () => setShowReject(false) });
                    }}
                    className="space-y-4"
                >
                    <TextArea
                        value={rejectForm.data.reason}
                        onChange={(e) => rejectForm.setData('reason', e.target.value)}
                        placeholder="Why is this control being returned?"
                    />
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={() => setShowReject(false)}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={rejectForm.processing}>Return to draft</PrimaryButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
