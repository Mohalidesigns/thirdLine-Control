import ConfirmDialog from '@/Components/ConfirmDialog';
import DataTable from '@/Components/DataTable';
import PageHeader from '@/Components/PageHeader';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import QuestionnaireBuilder from './QuestionnaireBuilder';
import { AlertTriangle, Info, Send, TrendingUp } from 'lucide-react';

const TYPE_LABELS = { csa: 'Control Self-Assessment', attestation: 'Attestation', survey: 'Survey' };

export default function Show({
    campaign,
    questionnaire,
    responses = [],
    myResponses = [],
    responseRate = 0,
    results = null,
    can = {},
    controls = [],
    requirements = [],
}) {
    const [confirm, setConfirm] = useState(null); // 'approve' | 'open' | 'close'
    const isDraft = campaign.status === 'Draft';

    const submitted = responses.filter((r) => ['Submitted', 'Under Review', 'Accepted'].includes(r.status)).length;

    const responseColumns = [
        { field: 'respondent', label: 'Respondent', render: (r) => r.respondent?.name ?? '—' },
        { field: 'entity', label: 'Entity', render: (r) => r.entity?.name ?? '—' },
        {
            field: 'control',
            label: 'Control',
            render: (r) => (r.control ? <span className="font-mono text-xs">{r.control.control_ref}</span> : '—'),
        },
        { field: 'score', label: 'Score', render: (r) => (r.score !== null ? `${r.score}%` : '—') },
        { field: 'self_rating', label: 'Self', render: (r) => r.self_rating ?? '—' },
        {
            field: 'reviewer_rating',
            label: 'Reviewer',
            render: (r) => (
                <span>
                    {r.reviewer_rating ?? '—'}
                    {r.variance_flag && <span className="badge badge-high ml-1">Variance</span>}
                </span>
            ),
        },
        { field: 'status', label: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    ];

    return (
        <AuthenticatedLayout header={campaign.name}>
            <Head title={campaign.reference} />

            <PageHeader
                title={campaign.name}
                subtitle={`${TYPE_LABELS[campaign.campaign_type]}${campaign.is_anonymous ? ' · Anonymous' : ''}${campaign.period_label ? ` · ${campaign.period_label}` : ''}`}
                breadcrumbs={[{ label: 'Campaigns', href: route('csa-campaigns.index') }, { label: campaign.reference }]}
                actions={
                    <>
                        {can.approve && (
                            <button type="button" className="btn-success" onClick={() => setConfirm('approve')}>Approve campaign</button>
                        )}
                        {can.open && (
                            <button type="button" className="btn-primary" onClick={() => setConfirm('open')}>Open campaign</button>
                        )}
                        {can.close && (
                            <button type="button" className="btn-warning" onClick={() => setConfirm('close')}>Close campaign</button>
                        )}
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
                <StatCard icon={Info} title="Status" value={campaign.status} color="primary" />
                <StatCard icon={Send}
                    title="Responses"
                    value={campaign.is_anonymous ? `${results?.response_count ?? 0} received` : `${submitted}/${responses.length}`}
                    color="primary"
                />
                <StatCard icon={TrendingUp} title="Response rate" value={`${responseRate}%`} subtitle={campaign.response_rate_target ? `Target ${campaign.response_rate_target}%` : null} color={responseRate >= (campaign.response_rate_target ?? 0) ? 'success' : 'warning'} />
                <StatCard icon={AlertTriangle} tone="amber" title="Variances" value={responses.filter((r) => r.variance_flag).length} color="danger" />
            </div>

            {myResponses.length > 0 && (
                <div className="card mb-6">
                    <div className="card-header"><h3 className="text-sm font-semibold">My responses in this campaign</h3></div>
                    <div className="card-body space-y-2">
                        {myResponses.map((r) => (
                            <div key={r.id} className="flex items-center justify-between rounded-lg border border-gray-100 px-4 py-2">
                                <div className="text-sm">
                                    {r.control ? `${r.control.control_ref} — ${r.control.title}` : r.entity?.name ?? 'General response'}
                                </div>
                                <div className="flex items-center gap-3">
                                    <StatusBadge status={r.status} />
                                    <Link href={route('csa-responses.show', r.id)} className="btn-primary !py-1 text-xs">
                                        {['Not Started', 'In Progress', 'Returned'].includes(r.status) ? 'Respond' : 'View'}
                                    </Link>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {can.respondAnonymously && questionnaire && (
                <AnonymousRespondCard campaign={campaign} questions={questionnaire.questions ?? []} />
            )}

            {isDraft && can.update && questionnaire && (
                <QuestionnaireBuilder campaign={campaign} questionnaire={questionnaire} controls={controls} requirements={requirements} />
            )}

            {!isDraft && questionnaire && (
                <div className="card mb-6">
                    <div className="card-header"><h3 className="text-sm font-semibold">Questionnaire ({(questionnaire.questions ?? []).length} questions)</h3></div>
                    <div className="card-body">
                        <ol className="list-decimal space-y-2 pl-5 text-sm">
                            {(questionnaire.questions ?? []).map((q) => (
                                <li key={q.id}>
                                    {q.question_text}
                                    <span className="ml-2 text-xs text-gray-400">{q.response_type}{q.is_required ? ' · required' : ''}{q.triggers_exception_on ? ' · raises exception' : ''}</span>
                                </li>
                            ))}
                        </ol>
                    </div>
                </div>
            )}

            {!campaign.is_anonymous && responses.length > 0 && (
                <div className="card mb-6">
                    <div className="card-header"><h3 className="text-sm font-semibold">Responses</h3></div>
                    <DataTable
                        columns={responseColumns}
                        data={responses}
                        emptyMessage="No responses yet."
                        onRowClick={(row) => router.visit(route('csa-responses.show', row.id))}
                    />
                </div>
            )}

            {results && (
                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold">
                            Aggregate results {campaign.is_anonymous && <span className="text-xs font-normal text-gray-400">(anonymous — aggregate only)</span>}
                        </h3>
                    </div>
                    <div className="card-body space-y-4">
                        {results.questions.map((q) => (
                            <div key={q.id}>
                                <p className="text-sm font-medium text-[var(--color-text-primary)]">{q.question_text}</p>
                                {q.average !== null && <p className="text-xs text-[var(--color-text-secondary)]">Average: {q.average}</p>}
                                <div className="mt-1 flex flex-wrap gap-2">
                                    {Object.entries(q.answers).map(([value, count]) => (
                                        <span key={value} className="rounded-full bg-gray-100 px-3 py-1 text-xs">
                                            {value}: <strong>{count}</strong>
                                        </span>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            <ConfirmDialog
                show={confirm !== null}
                title={{ approve: 'Approve this campaign?', open: 'Open this campaign?', close: 'Close this campaign?' }[confirm]}
                message={{
                    approve: 'Approval is maker-checker: you confirm the scope and questionnaire are ready.',
                    open: 'Responses will be generated for every assignment and respondents will be able to begin.',
                    close: 'Respondents will no longer be able to submit.',
                }[confirm]}
                variant={confirm === 'close' ? 'warning' : 'primary'}
                onConfirm={() => {
                    router.post(route(`csa-campaigns.${confirm}`, campaign.id), {}, { onFinish: () => setConfirm(null) });
                }}
                onCancel={() => setConfirm(null)}
            />
        </AuthenticatedLayout>
    );
}

/** Inline anonymous answer sheet — nothing identifying leaves the browser. */
function AnonymousRespondCard({ campaign, questions }) {
    const [values, setValues] = useState({});
    const [processing, setProcessing] = useState(false);

    const visible = questions.filter((q) => {
        if (!q.condition?.question_id) return true;
        const answer = values[q.condition.question_id];
        return (q.condition.in ?? []).some((v) => String(v) === String(answer));
    });

    const submit = (e) => {
        e.preventDefault();
        setProcessing(true);
        router.post(
            route('surveys.respond-anonymously', campaign.id),
            {
                answers: visible.map((q) => ({ question_id: q.id, value: values[q.id] ?? null })),
                submit: true,
            },
            { onFinish: () => setProcessing(false) },
        );
    };

    return (
        <form onSubmit={submit} className="card mb-6">
            <div className="card-header">
                <h3 className="text-sm font-semibold">Respond anonymously</h3>
                <p className="text-xs text-[var(--color-text-secondary)]">Your identity is never stored — not on the response, and not in the audit trail.</p>
            </div>
            <div className="card-body space-y-4">
                {visible.map((q) => (
                    <QuestionInput key={q.id} question={q} value={values[q.id]} onChange={(v) => setValues({ ...values, [q.id]: v })} />
                ))}
                <div className="flex justify-end">
                    <button type="submit" className="btn-primary" disabled={processing}>Submit anonymously</button>
                </div>
            </div>
        </form>
    );
}

export function QuestionInput({ question, value, onChange }) {
    const base = (
        <p className="text-sm font-medium text-[var(--color-text-primary)]">
            {question.question_text}
            {question.is_required && <span className="ml-1 text-red-500">*</span>}
        </p>
    );

    const options = {
        yes_no: ['Yes', 'No'],
        yes_no_na: ['Yes', 'No', 'N/A'],
        scale_1_5: [1, 2, 3, 4, 5],
        maturity_0_5: [0, 1, 2, 3, 4, 5],
    }[question.response_type] ?? (question.options ?? []);

    return (
        <div>
            {base}
            {question.help_text && <p className="text-xs text-[var(--color-text-secondary)]">{question.help_text}</p>}
            <div className="mt-2">
                {['yes_no', 'yes_no_na', 'scale_1_5', 'maturity_0_5', 'single_select'].includes(question.response_type) && (
                    <div className="flex flex-wrap gap-2">
                        {options.map((opt) => (
                            <button
                                key={opt}
                                type="button"
                                onClick={() => onChange(opt)}
                                className={`rounded-lg border px-3 py-1.5 text-sm transition-colors duration-200 ${
                                    String(value) === String(opt)
                                        ? 'border-[var(--color-primary)] bg-[var(--color-primary)] text-white'
                                        : 'border-gray-200 bg-white hover:bg-gray-50'
                                }`}
                            >
                                {opt}
                            </button>
                        ))}
                    </div>
                )}
                {question.response_type === 'multi_select' && (
                    <div className="flex flex-wrap gap-2">
                        {options.map((opt) => {
                            const selected = Array.isArray(value) && value.includes(opt);
                            return (
                                <button
                                    key={opt}
                                    type="button"
                                    onClick={() => onChange(selected ? value.filter((v) => v !== opt) : [...(value ?? []), opt])}
                                    className={`rounded-lg border px-3 py-1.5 text-sm ${selected ? 'border-[var(--color-primary)] bg-[var(--color-primary)] text-white' : 'border-gray-200 bg-white hover:bg-gray-50'}`}
                                >
                                    {opt}
                                </button>
                            );
                        })}
                    </div>
                )}
                {question.response_type === 'free_text' && (
                    <textarea className="form-input" rows={3} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />
                )}
                {question.response_type === 'numeric' && (
                    <input type="number" className="form-input w-40" value={value ?? ''} onChange={(e) => onChange(e.target.value)} />
                )}
                {question.response_type === 'date' && (
                    <input type="date" className="form-input w-48" value={value ?? ''} onChange={(e) => onChange(e.target.value)} />
                )}
            </div>
        </div>
    );
}
