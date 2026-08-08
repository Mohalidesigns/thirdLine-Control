import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import StatusBadge from '@/Components/StatusBadge';
import TextArea from '@/Components/TextArea';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { QuestionInput } from './Show';

export default function Respond({ response, questions = [], instructions = null, can = {} }) {
    const { errors } = usePage().props;
    const campaign = response.campaign ?? {};

    const [values, setValues] = useState(() => {
        const initial = {};
        (response.answers ?? []).forEach((a) => {
            initial[a.question_id] = Array.isArray(a.answer_value) && a.answer_value.length === 1 ? a.answer_value[0] : a.answer_value;
        });
        return initial;
    });
    const [comments, setComments] = useState(() => {
        const initial = {};
        (response.answers ?? []).forEach((a) => { if (a.comment) initial[a.question_id] = a.comment; });
        return initial;
    });
    const [processing, setProcessing] = useState(false);
    const [showReview, setShowReview] = useState(false);

    const visibleQuestions = useMemo(
        () => questions.filter((q) => {
            if (!q.condition?.question_id) return true;
            const answer = values[q.condition.question_id];
            return (q.condition.in ?? []).some((v) => (Array.isArray(answer) ? answer : [answer]).some((a) => String(a) === String(v)));
        }),
        [questions, values],
    );

    const sections = useMemo(() => {
        const grouped = new Map();
        visibleQuestions.forEach((q) => {
            const key = q.section || 'General';
            if (!grouped.has(key)) grouped.set(key, []);
            grouped.get(key).push(q);
        });
        return [...grouped.entries()];
    }, [visibleQuestions]);

    const send = (submit) => {
        setProcessing(true);
        router.post(
            route('csa-responses.answers', response.id),
            {
                answers: visibleQuestions.map((q) => ({
                    question_id: q.id,
                    value: values[q.id] ?? null,
                    comment: comments[q.id] ?? null,
                })),
                submit,
            },
            { preserveScroll: true, onFinish: () => setProcessing(false) },
        );
    };

    const readOnly = !can.respond;

    return (
        <AuthenticatedLayout header={campaign.name}>
            <Head title={`Response — ${campaign.reference}`} />

            <PageHeader
                title={response.control ? `${response.control.control_ref} — ${response.control.title}` : campaign.name}
                subtitle={`${campaign.reference}${response.entity ? ` · ${response.entity.name}` : ''}`}
                breadcrumbs={[
                    { label: 'Campaigns', href: route('csa-campaigns.index') },
                    { label: campaign.reference ?? '', href: route('csa-campaigns.show', campaign.id) },
                    { label: 'Response' },
                ]}
                actions={
                    <div className="flex items-center gap-3">
                        <StatusBadge status={response.status} />
                        {can.review && (
                            <button type="button" className="btn-primary" onClick={() => setShowReview(true)}>Review response</button>
                        )}
                        {can.approveRating && (
                            <button
                                type="button"
                                className="btn-success"
                                onClick={() => router.post(route('csa-responses.approve-rating', response.id))}
                            >
                                Apply proposed rating ({response.proposed_design_rating})
                            </button>
                        )}
                    </div>
                }
            />

            {response.status === 'Returned' && response.review_notes && (
                <div className="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <p className="text-sm font-semibold text-amber-800">Returned by {response.reviewer?.name}</p>
                    <p className="mt-1 text-sm text-amber-700">{response.review_notes}</p>
                </div>
            )}

            {instructions && (
                <div className="card mb-6">
                    <div className="card-body text-sm text-[var(--color-text-secondary)]">{instructions}</div>
                </div>
            )}

            <div className="mx-auto max-w-4xl space-y-6">
                {sections.map(([section, sectionQuestions]) => (
                    <div key={section} className="card">
                        <div className="card-header"><h3 className="text-sm font-semibold">{section}</h3></div>
                        <div className="card-body space-y-6">
                            {sectionQuestions.map((q) => (
                                <div key={q.id}>
                                    <QuestionInput
                                        question={q}
                                        value={values[q.id]}
                                        onChange={readOnly ? () => {} : (v) => setValues({ ...values, [q.id]: v })}
                                    />
                                    {errors[`question_${q.id}`] && (
                                        <p className="mt-1 text-sm text-[var(--color-error)]">{errors[`question_${q.id}`]}</p>
                                    )}
                                    {!readOnly && (
                                        <input
                                            className="form-input mt-2 text-xs"
                                            placeholder="Comment (optional)"
                                            value={comments[q.id] ?? ''}
                                            onChange={(e) => setComments({ ...comments, [q.id]: e.target.value })}
                                        />
                                    )}
                                    {readOnly && comments[q.id] && (
                                        <p className="mt-1 text-xs text-[var(--color-text-secondary)]">Comment: {comments[q.id]}</p>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                ))}

                {!readOnly && (
                    <div className="flex justify-end gap-2">
                        <SecondaryButton type="button" disabled={processing} onClick={() => send(false)}>Save draft</SecondaryButton>
                        <PrimaryButton type="button" disabled={processing} onClick={() => send(true)}>Submit response</PrimaryButton>
                    </div>
                )}

                {(response.score !== null || response.self_rating) && (
                    <div className="card">
                        <div className="card-header"><h3 className="text-sm font-semibold">Outcome</h3></div>
                        <div className="card-body grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                            <div><p className="text-[var(--color-text-secondary)]">Score</p><p className="font-semibold">{response.score ?? '—'}%</p></div>
                            <div><p className="text-[var(--color-text-secondary)]">Self rating</p><p className="font-semibold">{response.self_rating ?? '—'}</p></div>
                            <div><p className="text-[var(--color-text-secondary)]">Reviewer rating</p><p className="font-semibold">{response.reviewer_rating ?? '—'}{response.variance_flag && <span className="badge badge-high ml-1">Variance</span>}</p></div>
                            <div><p className="text-[var(--color-text-secondary)]">Proposed design rating</p><p className="font-semibold">{response.proposed_design_rating ?? '—'}{response.rating_approved_at ? ' (applied)' : ''}</p></div>
                        </div>
                    </div>
                )}
            </div>

            <ReviewModal show={showReview} onClose={() => setShowReview(false)} response={response} />
        </AuthenticatedLayout>
    );
}

function ReviewModal({ show, onClose, response }) {
    const [decision, setDecision] = useState('accept');
    const [rating, setRating] = useState(response.self_rating ?? '');
    const [notes, setNotes] = useState('');
    const [processing, setProcessing] = useState(false);

    const submit = (e) => {
        e.preventDefault();
        setProcessing(true);
        router.post(
            route('csa-responses.review', response.id),
            { decision, reviewer_rating: rating || null, review_notes: notes || null },
            { onFinish: () => { setProcessing(false); onClose(); } },
        );
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="lg">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-lg font-semibold text-[var(--color-text-primary)]">Review response</h2>
                <p className="mt-1 text-sm text-[var(--color-text-secondary)]">
                    Self rating: <strong>{response.self_rating ?? '—'}</strong> · Score: <strong>{response.score ?? '—'}%</strong>.
                    A rating gap beyond the campaign threshold is flagged to the Control Function Head.
                </p>
                <div className="mt-4 space-y-4">
                    <div>
                        <label className="form-label">Decision</label>
                        <SelectInput value={decision} onChange={(e) => setDecision(e.target.value)}>
                            <option value="accept">Accept</option>
                            <option value="return">Return to respondent</option>
                        </SelectInput>
                    </div>
                    <div>
                        <label className="form-label">Reviewer rating</label>
                        <SelectInput value={rating} onChange={(e) => setRating(e.target.value)}>
                            <option value="">No rating</option>
                            <option value="Adequate">Adequate</option>
                            <option value="Inadequate">Inadequate</option>
                        </SelectInput>
                    </div>
                    <div>
                        <label className="form-label">Notes {decision === 'return' && <span className="text-red-500">*</span>}</label>
                        <TextArea rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} />
                    </div>
                </div>
                <div className="mt-6 flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>Cancel</SecondaryButton>
                    <PrimaryButton disabled={processing}>Record review</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
