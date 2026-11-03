import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import ProgressBar from '@/Components/ProgressBar';
import RelationshipsPanel from '@/Components/RelationshipsPanel';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import StatusBadge from '@/Components/StatusBadge';
import TextArea from '@/Components/TextArea';
import RichTextEditor from '@/Components/RichTextEditor';
import RichTextViewer from '@/Components/RichTextViewer';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate, formatMoney } from '@/utils';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Plus } from 'lucide-react';

const BAND_CLASSES = {
    Low: 'badge-low',
    Moderate: 'badge-medium',
    High: 'badge-high',
    Critical: 'badge-critical',
};

export default function Show({
    risk,
    scales = {},
    appetite = null,
    targetGap = null,
    links = [],
    mappableControls = [],
    users = [],
    can = {},
}) {
    const { features = [], auth } = usePage().props;
    const [assessing, setAssessing] = useState(false);
    const [revising, setRevising] = useState(null);
    const [reviewing, setReviewing] = useState(null);
    const [treating, setTreating] = useState(false);

    const mapForm = useForm({ control_id: '', contribution_weight: 1 });

    const attach = (event) => {
        event.preventDefault();
        mapForm.post(route('risks.controls.attach', risk.id), {
            preserveScroll: true,
            onSuccess: () => mapForm.reset(),
        });
    };

    return (
        <AuthenticatedLayout header={risk.code}>
            <Head title={risk.code} />

            <PageHeader
                title={risk.title}
                subtitle={
                    <span className="font-mono">
                        {risk.code}
                        {risk.risk_category ? ` · ${risk.risk_category.name}` : ''}
                    </span>
                }
                breadcrumbs={[
                    { label: 'Risk Register', href: can.register ? route('risks.index') : undefined },
                    { label: risk.code },
                ]}
                actions={
                    <>
                        {features.includes('risk-assessments') && can.assess && (
                            <PrimaryButton
                                onClick={() => {
                                    setRevising(null);
                                    setAssessing(true);
                                }}
                            >
                                <Plus className="h-4 w-4" strokeWidth={2} /> Assessment
                            </PrimaryButton>
                        )}
                        {features.includes('risk-treatments') && can.treat && (
                            <SecondaryButton type="button" onClick={() => setTreating(true)}>
                                <Plus className="h-4 w-4" strokeWidth={2} /> Treatment plan
                            </SecondaryButton>
                        )}
                    </>
                }
            />

            {risk.appetite_breached && (
                <div className="mb-6 rounded-xl border-l-4 border-l-[var(--color-error)] border border-red-100 bg-red-50 p-4">
                    <p className="text-sm font-semibold text-[var(--color-error)]">Outside risk appetite</p>
                    <p className="mt-1 text-sm text-[var(--color-text-primary)]">
                        The residual position of {risk.residual_rating ?? risk.inherent_rating} exceeds the approved
                        tolerance{appetite?.tolerance_upper ? ` of ${appetite.tolerance_upper}` : ''}. Flagged{' '}
                        {formatDate(risk.appetite_breach_at)}.
                    </p>
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <PositionCard risk={risk} targetGap={targetGap} />

                    {features.includes('risk-assessments') && (
                        <AssessmentHistory
                            risk={risk}
                            can={can}
                            userId={auth?.user?.id}
                            onReview={setReviewing}
                            onRevise={(assessment) => {
                                setRevising(assessment);
                                setAssessing(true);
                            }}
                        />
                    )}

                    {features.includes('risk-treatments') && <TreatmentList risk={risk} />}

                    <div className="card">
                        <div className="card-header">
                            <h3 className="text-sm font-semibold">Mitigating controls</h3>
                        </div>
                        <div className="card-body">
                            {risk.controls?.length ? (
                                <ul className="divide-y divide-gray-100">
                                    {risk.controls.map((control) => (
                                        <li key={control.id} className="flex items-center justify-between gap-3 py-2.5">
                                            <div>
                                                <Link
                                                    href={route('controls.show', control.id)}
                                                    className="text-sm font-medium text-[var(--color-primary)] hover:underline"
                                                >
                                                    <span className="font-mono text-xs">{control.control_ref}</span> — {control.title}
                                                </Link>
                                                <p className="text-xs text-gray-400">
                                                    Owner: {control.owner?.name ?? '—'} · Weight{' '}
                                                    {control.pivot?.contribution_weight ?? 1}
                                                </p>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <StatusBadge status={control.status} />
                                                {can.map && (
                                                    <button
                                                        type="button"
                                                        className="text-xs text-[var(--color-error)] hover:underline"
                                                        onClick={() =>
                                                            router.delete(route('risks.controls.detach', [risk.id, control.id]), {
                                                                preserveScroll: true,
                                                            })
                                                        }
                                                    >
                                                        Unmap
                                                    </button>
                                                )}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-sm text-[var(--color-error)]">
                                    Control gap — this risk has no mapped control.
                                </p>
                            )}

                            {can.map && mappableControls.length > 0 && (
                                <form onSubmit={attach} className="mt-4 flex flex-wrap items-end gap-3 border-t border-gray-100 pt-4">
                                    <div className="min-w-[260px] flex-1">
                                        <InputLabel value="Map a control" />
                                        <SelectInput
                                            value={mapForm.data.control_id}
                                            onChange={(e) => mapForm.setData('control_id', e.target.value)}
                                        >
                                            <option value="">— Select control —</option>
                                            {mappableControls.map((control) => (
                                                <option key={control.id} value={control.id}>
                                                    {control.control_ref} — {control.title}
                                                </option>
                                            ))}
                                        </SelectInput>
                                    </div>
                                    <div className="w-32">
                                        <InputLabel value="Weight" />
                                        <SelectInput
                                            value={mapForm.data.contribution_weight}
                                            onChange={(e) => mapForm.setData('contribution_weight', Number(e.target.value))}
                                        >
                                            {[0.5, 1, 2, 3].map((weight) => (
                                                <option key={weight} value={weight}>
                                                    {weight}
                                                </option>
                                            ))}
                                        </SelectInput>
                                    </div>
                                    <PrimaryButton disabled={mapForm.processing || !mapForm.data.control_id}>Map</PrimaryButton>
                                </form>
                            )}
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">
                            <h3 className="text-sm font-semibold">Description</h3>
                        </div>
                        <div className="card-body"><RichTextViewer value={risk.description_rich} fallback={risk.description} /></div>
                    </div>
                </div>

                <div className="space-y-6">
                    {appetite && <AppetiteCard appetite={appetite} />}
                    <AttributesCard risk={risk} />
                    {features.includes('linkage') && (
                        <RelationshipsPanel type="risk" id={risk.id} links={links} canLink={can.link} />
                    )}
                </div>
            </div>

            <AssessmentModal
                key={revising?.id ?? 'new'}
                show={assessing}
                onClose={() => setAssessing(false)}
                risk={risk}
                scales={scales}
                from={revising}
            />
            <ReviewModal assessment={reviewing} risk={risk} onClose={() => setReviewing(null)} />
            <TreatmentModal show={treating} onClose={() => setTreating(false)} risk={risk} users={users} />
        </AuthenticatedLayout>
    );
}

function PositionCard({ risk, targetGap }) {
    const positions = [
        { label: 'Inherent', score: risk.inherent_rating, l: risk.inherent_likelihood, i: risk.inherent_impact, colour: 'text-[var(--color-error)]' },
        { label: 'Residual', score: risk.residual_rating, l: risk.residual_likelihood, i: risk.residual_impact, colour: 'text-[var(--color-primary)]' },
        { label: 'Target', score: risk.target_rating, l: risk.target_likelihood, i: risk.target_impact, colour: 'text-[var(--color-success)]' },
    ];

    return (
        <div className="card">
            <div className="card-header">
                <h3 className="text-sm font-semibold">Position</h3>
                {risk.current_rating_band && (
                    <span className={`badge ${BAND_CLASSES[risk.current_rating_band] ?? 'badge-status-draft'}`}>
                        {risk.current_rating_band}
                    </span>
                )}
            </div>
            <div className="card-body grid grid-cols-1 gap-4 sm:grid-cols-3">
                {positions.map((position) => (
                    <div key={position.label}>
                        <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                            {position.label}
                        </p>
                        <p className={`mt-1 text-3xl font-bold ${position.colour}`}>
                            {position.score ?? '—'}
                        </p>
                        <p className="text-xs text-gray-400">
                            {position.l && position.i ? `Likelihood ${position.l} × Impact ${position.i}` : 'Not assessed'}
                        </p>
                    </div>
                ))}
            </div>
            {targetGap !== null && targetGap !== undefined && (
                <div className="border-t border-gray-100 px-5 py-3 text-sm">
                    <span className="text-[var(--color-text-secondary)]">Gap to target: </span>
                    <span className={targetGap > 0 ? 'font-semibold text-[var(--color-warning)]' : 'font-semibold text-[var(--color-success)]'}>
                        {targetGap > 0 ? `${targetGap} above target` : 'At or below target'}
                    </span>
                </div>
            )}
            {(risk.expected_loss_minor || risk.var_95_minor) && (
                <div className="border-t border-gray-100 px-5 py-3 text-sm">
                    <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                        Quantified exposure
                    </p>
                    <p className="mt-1 tabular-nums">
                        Expected loss {formatMoney(risk.expected_loss_minor, risk.loss_currency)} · VaR 95%{' '}
                        {formatMoney(risk.var_95_minor, risk.loss_currency)} · VaR 99%{' '}
                        {formatMoney(risk.var_99_minor, risk.loss_currency)}
                    </p>
                </div>
            )}
        </div>
    );
}

function AssessmentHistory({ risk, can, userId, onReview, onRevise }) {
    const assessments = risk.assessments ?? [];

    return (
        <div className="card">
            <div className="card-header">
                <h3 className="text-sm font-semibold">Assessment history</h3>
            </div>
            <div className="card-body">
                {assessments.length === 0 ? (
                    <p className="text-sm text-[var(--color-text-secondary)]">No assessment recorded yet.</p>
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {assessments.map((assessment) => (
                            <li key={assessment.id} className="py-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="badge badge-status-draft capitalize">{assessment.assessment_type}</span>
                                    <span className={`badge ${BAND_CLASSES[assessment.rating] ?? 'badge-status-draft'}`}>
                                        {assessment.score} · {assessment.rating}
                                    </span>
                                    <StatusBadge status={assessment.status === 'Rejected' ? 'Returned' : assessment.status} />
                                    <span className="text-xs text-gray-400">
                                        {formatDate(assessment.assessed_at)} by {assessment.assessor?.name ?? '—'}
                                        {assessment.period_label ? ` · ${assessment.period_label}` : ''}
                                        {assessment.status === 'Published' && assessment.approver && assessment.approved_by !== assessment.assessed_by
                                            ? ` · published by ${assessment.approver.name}`
                                            : ''}
                                    </span>
                                </div>

                                {assessment.driving_dimension && (
                                    <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                                        Driven by <span className="font-medium">{assessment.driving_dimension}</span> impact
                                        · {assessment.method.replace('_', ' ')} · {assessment.confidence} confidence
                                    </p>
                                )}

                                <AssessmentRationale assessment={assessment} />

                                {assessment.status === 'Rejected' && (
                                    <div className="mt-2 rounded-lg border-l-4 border-l-[var(--color-warning)] bg-orange-50 p-3 text-sm">
                                        <p className="text-xs font-semibold text-[var(--color-text-secondary)]">
                                            Returned by {assessment.returner?.name ?? '—'}
                                            {assessment.returned_at ? ` on ${formatDate(assessment.returned_at)}` : ''}
                                        </p>
                                        <p className="mt-1 whitespace-pre-line">{assessment.return_reason ?? 'No reason recorded.'}</p>
                                        {can.assess && (
                                            <button
                                                type="button"
                                                className="btn-secondary mt-2 !px-2 !py-1 text-xs"
                                                onClick={() => onRevise(assessment)}
                                            >
                                                Revise and record again
                                            </button>
                                        )}
                                    </div>
                                )}

                                {assessment.status === 'Pending Review' &&
                                    (assessment.assessed_by === userId ? (
                                        <p className="mt-2 text-xs text-[var(--color-text-secondary)]">
                                            Awaiting second-line review. Someone other than you must publish or return it.
                                        </p>
                                    ) : (
                                        can.review && (
                                            <button
                                                type="button"
                                                className="btn-primary mt-2 !px-2 !py-1 text-xs"
                                                onClick={() => onReview(assessment)}
                                            >
                                                Review
                                            </button>
                                        )
                                    ))}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}

function AssessmentRationale({ assessment }) {
    const dimensions = Object.entries(assessment.impact_dimensions ?? {}).filter(([, level]) => level);
    const hasLikelihood = assessment.likelihood_rationale || assessment.likelihood_rationale_rich;
    const hasImpact = assessment.impact_rationale || assessment.impact_rationale_rich;

    if (!hasLikelihood && !hasImpact && !dimensions.length && !assessment.notes) {
        return null;
    }

    return (
        <div className="mt-2 space-y-1.5 text-sm">
            <p className="text-xs text-[var(--color-text-secondary)]">
                Likelihood {assessment.likelihood_level} × Impact {assessment.impact_level}
                {dimensions.length > 0 && ` · ${dimensions.map(([name, level]) => `${name} ${level}`).join(', ')}`}
            </p>
            {hasLikelihood && (
                <div>
                    <span className="text-xs font-semibold text-[var(--color-text-secondary)]">Likelihood rationale</span>
                    <RichTextViewer value={assessment.likelihood_rationale_rich} fallback={assessment.likelihood_rationale} />
                </div>
            )}
            {hasImpact && (
                <div>
                    <span className="text-xs font-semibold text-[var(--color-text-secondary)]">Impact rationale</span>
                    <RichTextViewer value={assessment.impact_rationale_rich} fallback={assessment.impact_rationale} />
                </div>
            )}
            {assessment.notes && <p className="text-xs text-[var(--color-text-secondary)]">{assessment.notes}</p>}
        </div>
    );
}

function ReviewModal({ assessment, risk, onClose }) {
    const form = useForm({ reason: '' });
    // Keep the last assessment on screen while the dialog animates closed.
    const [shown, setShown] = useState(assessment);

    useEffect(() => {
        if (assessment) setShown(assessment);
    }, [assessment]);

    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };

    const send = (action) => {
        form.post(route(`risks.assessments.${action}`, [risk.id, shown.id]), {
            preserveScroll: true,
            onSuccess: close,
        });
    };

    const errors = Object.entries(form.errors).filter(([field]) => field !== 'reason');

    return (
        <Modal show={!!assessment} onClose={close} maxWidth="2xl" title={`Review ${risk.code} assessment`}>
            {shown && (
                <div className="space-y-4">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="badge badge-status-draft capitalize">{shown.assessment_type}</span>
                        <span className={`badge ${BAND_CLASSES[shown.rating] ?? 'badge-status-draft'}`}>
                            {shown.score} · {shown.rating}
                        </span>
                        <span className="text-xs text-gray-400">
                            {formatDate(shown.assessed_at)} by {shown.assessor?.name ?? '—'}
                        </span>
                    </div>

                    <AssessmentRationale assessment={shown} />

                    <p className="text-sm text-[var(--color-text-secondary)]">
                        Publishing makes this the risk&apos;s {shown.assessment_type} position and re-checks it against
                        appetite. Returning sends it back to the assessor with your reason.
                    </p>

                    <div>
                        <InputLabel value="Reason for returning" />
                        <TextArea
                            value={form.data.reason}
                            onChange={(e) => form.setData('reason', e.target.value)}
                            rows={3}
                            placeholder="Required to return the assessment"
                        />
                        <InputError message={form.errors.reason} />
                    </div>

                    {errors.length > 0 && (
                        <div className="rounded-lg border border-red-100 bg-red-50 p-3 text-sm text-[var(--color-error)]">
                            {errors.map(([field, message]) => (
                                <p key={field}>{message}</p>
                            ))}
                        </div>
                    )}

                    <div className="flex flex-wrap justify-end gap-2">
                        <SecondaryButton type="button" onClick={close} disabled={form.processing}>
                            Cancel
                        </SecondaryButton>
                        <DangerButton type="button" onClick={() => send('reject')} disabled={form.processing || !form.data.reason.trim()}>
                            Return to assessor
                        </DangerButton>
                        <PrimaryButton type="button" onClick={() => send('publish')} disabled={form.processing}>
                            Publish
                        </PrimaryButton>
                    </div>
                </div>
            )}
        </Modal>
    );
}

function TreatmentList({ risk }) {
    const treatments = risk.treatments ?? [];

    return (
        <div className="card">
            <div className="card-header">
                <h3 className="text-sm font-semibold">Treatment plans</h3>
            </div>
            <div className="card-body">
                {treatments.length === 0 ? (
                    <p className="text-sm text-[var(--color-text-secondary)]">No treatment plan yet.</p>
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {treatments.map((treatment) => (
                            <li key={treatment.id} className="py-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <Link
                                        href={route('treatments.show', treatment.id)}
                                        className="text-sm font-medium text-[var(--color-primary)] hover:underline"
                                    >
                                        <span className="font-mono text-xs">{treatment.reference}</span> — {treatment.title}
                                    </Link>
                                    <div className="flex items-center gap-2">
                                        <span className="badge badge-status-draft">{treatment.strategy}</span>
                                        <StatusBadge status={treatment.status} />
                                    </div>
                                </div>
                                <p className="mt-1 text-xs text-gray-400">
                                    Owner {treatment.owner?.name ?? '—'} · due {formatDate(treatment.due_at)}
                                    {treatment.milestones?.length ? ` · ${treatment.milestones.length} milestone(s)` : ''}
                                </p>
                                <div className="mt-2">
                                    <ProgressBar value={treatment.progress ?? 0} />
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}

function AppetiteCard({ appetite }) {
    const withinAppetite = appetite.position === 'Within appetite';

    return (
        <div className="card">
            <div className="card-header">
                <h3 className="text-sm font-semibold">Risk appetite</h3>
                <span className={`badge ${withinAppetite ? 'badge-low' : 'badge-critical'}`}>{appetite.position}</span>
            </div>
            <div className="card-body space-y-2 text-sm">
                <p className="text-[var(--color-text-primary)]">{appetite.statement}</p>
                <p className="text-xs text-[var(--color-text-secondary)]">
                    Level {appetite.appetite_level} · tolerance ≤ {appetite.tolerance_upper ?? '—'} · capacity{' '}
                    {appetite.capacity ?? '—'}
                </p>
            </div>
        </div>
    );
}

function AttributesCard({ risk }) {
    const rows = [
        ['Owner', risk.owner?.name],
        ['Second-line reviewer', risk.second_line_reviewer?.name],
        ['Entity', risk.entity?.name],
        ['Process', risk.process?.name],
        ['Taxonomy', risk.risk_category?.name],
        ['Basel event type', risk.basel_event_type],
        ['Velocity', risk.velocity],
        ['Horizon', risk.horizon],
        ['Type', risk.risk_type],
        ['Source', risk.risk_source],
        ['Emerging', risk.is_emerging ? 'Yes' : 'No'],
        ['Status', risk.status],
    ];

    return (
        <div className="card">
            <div className="card-header">
                <h3 className="text-sm font-semibold">Attributes</h3>
            </div>
            <div className="card-body space-y-1.5 text-sm">
                {rows.map(([label, value]) => (
                    <p key={label}>
                        <span className="text-gray-500">{label}:</span> {value ?? '—'}
                    </p>
                ))}
            </div>
        </div>
    );
}

function currentQuarter() {
    const now = new Date();

    return `${now.getFullYear()}-Q${Math.floor(now.getMonth() / 3) + 1}`;
}

/** `from` is a returned assessment being revised: its scores and rationale prefill the form. */
function AssessmentModal({ show, onClose, risk, scales, from = null }) {
    const likelihood = scales.likelihood ?? [];
    const impact = scales.impact ?? [];
    const dimensions = scales.dimensions ?? [];

    const form = useForm({
        assessment_type: from?.assessment_type ?? 'residual',
        likelihood_level: from?.likelihood_level ?? risk.residual_likelihood ?? risk.inherent_likelihood ?? 3,
        impact_level: from?.impact_level ?? risk.residual_impact ?? risk.inherent_impact ?? 3,
        likelihood_rationale: from?.likelihood_rationale ?? '',
        impact_rationale: from?.impact_rationale ?? '',
        impact_dimensions: from?.impact_dimensions ?? {},
        confidence: from?.confidence ?? 'Medium',
        method: from?.method === 'monte_carlo' ? 'workshop' : from?.method ?? 'workshop',
        period_label: from?.period_label ?? '',
        notes: from?.notes ?? '',
        loss_min_minor: from?.loss_min_minor ?? '',
        loss_likely_minor: from?.loss_likely_minor ?? '',
        loss_max_minor: from?.loss_max_minor ?? '',
        likelihood_percent: from?.likelihood_percent ?? '',
        currency: from?.currency ?? risk.loss_currency ?? 'NGN',
        impact_rationale_rich: from?.impact_rationale_rich ?? null,
        likelihood_rationale_rich: from?.likelihood_rationale_rich ?? null,
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('risks.assessments.store', risk.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="4xl" title={from ? 'Revise the returned assessment' : 'Record a risk assessment'}>
            <form onSubmit={submit} className="space-y-4">
                {from?.return_reason && (
                    <div className="rounded-lg border-l-4 border-l-[var(--color-warning)] bg-orange-50 p-3 text-sm">
                        <p className="text-xs font-semibold text-[var(--color-text-secondary)]">
                            Returned by {from.returner?.name ?? '—'}
                        </p>
                        <p className="mt-1 whitespace-pre-line">{from.return_reason}</p>
                    </div>
                )}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <InputLabel value="Assessment" required />
                        <SelectInput value={form.data.assessment_type} onChange={(e) => form.setData('assessment_type', e.target.value)}>
                            {['inherent', 'residual', 'target'].map((type) => (
                                <option key={type} value={type}>
                                    {type}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Likelihood" required />
                        <SelectInput
                            value={form.data.likelihood_level}
                            onChange={(e) => form.setData('likelihood_level', Number(e.target.value))}
                        >
                            {likelihood.map((level) => (
                                <option key={level.level} value={level.level}>
                                    {level.level} — {level.label}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Impact (overall)" required />
                        <SelectInput value={form.data.impact_level} onChange={(e) => form.setData('impact_level', Number(e.target.value))}>
                            {impact.map((level) => (
                                <option key={level.level} value={level.level}>
                                    {level.level} — {level.label}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                </div>

                <div>
                    <InputLabel value="Impact by dimension" />
                    <p className="mb-2 text-xs text-[var(--color-text-secondary)]">
                        Score each dimension that applies. The overall impact is the weighted aggregate, and the highest
                        dimension is kept visible as the driver.
                    </p>
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        {dimensions.map((dimension) => (
                            <div key={dimension}>
                                <label className="filter-bar-label capitalize">{dimension}</label>
                                <SelectInput
                                    value={form.data.impact_dimensions[dimension] ?? ''}
                                    onChange={(e) =>
                                        form.setData('impact_dimensions', {
                                            ...form.data.impact_dimensions,
                                            [dimension]: e.target.value ? Number(e.target.value) : undefined,
                                        })
                                    }
                                >
                                    <option value="">—</option>
                                    {impact.map((level) => (
                                        <option key={level.level} value={level.level}>
                                            {level.level}
                                        </option>
                                    ))}
                                </SelectInput>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel value="Likelihood rationale" />
                        <RichTextEditor
                            value={form.data.likelihood_rationale_rich ?? form.data.likelihood_rationale}
                            onChange={(doc, plain) => form.setData((d) => ({ ...d, likelihood_rationale: plain, likelihood_rationale_rich: doc }))}
                            tools="minimal"
                            minHeight={90}
                        />
                    </div>
                    <div>
                        <InputLabel value="Impact rationale" />
                        <RichTextEditor
                            value={form.data.impact_rationale_rich ?? form.data.impact_rationale}
                            onChange={(doc, plain) => form.setData((d) => ({ ...d, impact_rationale: plain, impact_rationale_rich: doc }))}
                            tools="minimal"
                            minHeight={90}
                        />
                    </div>
                </div>

                <details className="rounded-lg border border-gray-100 bg-gray-50 p-3">
                    <summary className="cursor-pointer text-sm font-semibold text-[var(--color-text-primary)]">
                        Quantitative inputs (optional)
                    </summary>
                    <p className="mt-2 text-xs text-[var(--color-text-secondary)]">
                        Amounts in minor units — kobo for NGN. Providing all four runs a 10,000-iteration Monte Carlo and
                        stores expected loss, VaR 95% and VaR 99%.
                    </p>
                    <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        {[
                            ['loss_min_minor', 'Best case'],
                            ['loss_likely_minor', 'Most likely'],
                            ['loss_max_minor', 'Worst case'],
                            ['likelihood_percent', 'Occurrence %'],
                        ].map(([field, label]) => (
                            <div key={field}>
                                <label className="filter-bar-label">{label}</label>
                                <TextInput
                                    type="number"
                                    value={form.data[field]}
                                    onChange={(e) => form.setData(field, e.target.value)}
                                />
                                {form.errors[field] && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors[field]}</p>}
                            </div>
                        ))}
                    </div>
                </details>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <InputLabel value="Method" />
                        <SelectInput value={form.data.method} onChange={(e) => form.setData('method', e.target.value)}>
                            {['workshop', 'interview', 'data_driven', 'scenario', 'monte_carlo'].map((method) => (
                                <option key={method} value={method}>
                                    {method.replace('_', ' ')}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Confidence" />
                        <SelectInput value={form.data.confidence} onChange={(e) => form.setData('confidence', e.target.value)}>
                            {['Low', 'Medium', 'High'].map((level) => (
                                <option key={level} value={level}>
                                    {level}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Period" />
                        <TextInput value={form.data.period_label} onChange={(e) => form.setData('period_label', e.target.value)} placeholder={currentQuarter()} />
                    </div>
                </div>

                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Record assessment</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

function TreatmentModal({ show, onClose, risk, users }) {
    const form = useForm({
        strategy: 'Reduce',
        title: '',
        description: '',
        description_rich: null,
        owner_id: '',
        target_rating: 'Moderate',
        cost_minor: '',
        currency: 'NGN',
        benefit_description: '',
        benefit_description_rich: null,
        start_at: '',
        due_at: '',
        alerts_enabled: true,
        alert_lead_days: 7,
        milestones: [],
    });

    const addMilestone = () =>
        form.setData('milestones', [...form.data.milestones, { title: '', due_at: '', owner_id: '' }]);

    const submit = (event) => {
        event.preventDefault();
        form.post(route('risks.treatments.store', risk.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="4xl" title="Propose a treatment plan">
            <form onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <InputLabel value="Strategy" required />
                        <SelectInput value={form.data.strategy} onChange={(e) => form.setData('strategy', e.target.value)}>
                            {['Avoid', 'Reduce', 'Transfer', 'Accept', 'Exploit'].map((strategy) => (
                                <option key={strategy} value={strategy}>
                                    {strategy}
                                </option>
                            ))}
                        </SelectInput>
                        {form.data.strategy === 'Accept' && (
                            <p className="mt-1 text-xs text-[var(--color-warning)]">
                                Acceptance needs Control Function Head approval and an expiry date.
                            </p>
                        )}
                    </div>
                    <div className="sm:col-span-2">
                        <InputLabel value="Title" required />
                        <TextInput value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                        {form.errors.title && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.title}</p>}
                    </div>
                </div>

                <div>
                    <InputLabel value="Description" />
                    <RichTextEditor
                        value={form.data.description_rich ?? form.data.description}
                        onChange={(doc, plain) => form.setData((d) => ({ ...d, description: plain, description_rich: doc }))}
                        tools="default"
                        minHeight={100}
                    />
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
                    <div>
                        <InputLabel value="Owner" required />
                        <SelectInput value={form.data.owner_id} onChange={(e) => form.setData('owner_id', e.target.value)}>
                            <option value="">— Select —</option>
                            {users.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name}
                                </option>
                            ))}
                        </SelectInput>
                        {form.errors.owner_id && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.owner_id}</p>}
                    </div>
                    <div>
                        <InputLabel value="Start" />
                        <TextInput type="date" value={form.data.start_at} onChange={(e) => form.setData('start_at', e.target.value)} />
                    </div>
                    <div>
                        <InputLabel value="Due" />
                        <TextInput type="date" value={form.data.due_at} onChange={(e) => form.setData('due_at', e.target.value)} />
                    </div>
                    <div>
                        <InputLabel value="Cost (minor units)" />
                        <TextInput type="number" value={form.data.cost_minor} onChange={(e) => form.setData('cost_minor', e.target.value)} />
                    </div>
                </div>

                <div>
                    <div className="flex items-center justify-between">
                        <InputLabel value="Milestones" />
                        <button type="button" className="text-xs font-semibold text-[var(--color-primary)] hover:underline" onClick={addMilestone}>
                            <Plus className="h-4 w-4" strokeWidth={2} /> Add milestone
                        </button>
                    </div>
                    {form.data.milestones.map((milestone, index) => (
                        <div key={index} className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <TextInput
                                value={milestone.title}
                                placeholder="Milestone"
                                onChange={(e) => {
                                    const next = [...form.data.milestones];
                                    next[index] = { ...next[index], title: e.target.value };
                                    form.setData('milestones', next);
                                }}
                            />
                            <TextInput
                                type="date"
                                value={milestone.due_at}
                                onChange={(e) => {
                                    const next = [...form.data.milestones];
                                    next[index] = { ...next[index], due_at: e.target.value };
                                    form.setData('milestones', next);
                                }}
                            />
                        </div>
                    ))}
                </div>

                <label className="flex items-center gap-2 text-sm text-gray-600">
                    <input
                        type="checkbox"
                        className="rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                        checked={form.data.alerts_enabled}
                        onChange={(e) => form.setData('alerts_enabled', e.target.checked)}
                    />
                    Alert the owner and escalate when this plan slips
                </label>

                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Propose plan</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
