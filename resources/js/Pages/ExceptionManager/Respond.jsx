import EvidencePanel from '@/Components/EvidencePanel';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import SeverityBadge from '@/Components/SeverityBadge';
import StatusBadge from '@/Components/StatusBadge';
import RichTextEditor from '@/Components/RichTextEditor';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDateTime } from '@/utils';
import { Head, useForm } from '@inertiajs/react';

const POSITIONS = ['Accepted', 'Partially Accepted', 'Disputed', 'Already Remediated', 'More Information Required'];
const ROOT_CAUSE_CATEGORIES = ['people', 'process', 'technology', 'governance', 'external'];

/**
 * CR1.3 — the authenticated departmental response form. Opening this page
 * recorded the acknowledgement; what is submitted here is on the record.
 */
export default function Respond({ escalation, draft = null, evidence = [] }) {
    const form = useForm({
        position: draft?.position ?? '',
        management_comment: draft?.management_comment ?? '',
        management_comment_rich: draft?.management_comment_rich ?? null,
        root_cause: draft?.root_cause ?? '',
        root_cause_rich: draft?.root_cause_rich ?? null,
        root_cause_category: draft?.root_cause_category ?? '',
        agreed_action_plan: draft?.agreed_action_plan ?? '',
        agreed_action_plan_rich: draft?.agreed_action_plan_rich ?? null,
        proposed_target_date: draft?.proposed_target_date?.slice(0, 10) ?? '',
    });

    const needsRootCause = ['root_cause', 'both'].includes(escalation.required_response) && form.data.position !== 'Disputed';
    const needsActionPlan = ['action_plan', 'both'].includes(escalation.required_response) && form.data.position !== 'Disputed';

    const saveDraft = () => form.post(route('exception-manager.respond.draft', escalation.id), { preserveScroll: true });
    const submit = (e) => {
        e.preventDefault();
        form.post(route('exception-manager.respond.submit', escalation.id));
    };

    return (
        <AuthenticatedLayout header={`Respond — ${escalation.reference}`}>
            <Head title={`Respond — ${escalation.reference}`} />

            <PageHeader
                title={
                    <span className="flex flex-wrap items-center gap-2">
                        Departmental response
                        <SeverityBadge severity={escalation.severity} />
                        <StatusBadge status={escalation.status} />
                        {escalation.round_no > 1 && <span className="badge badge-high">Round {escalation.round_no}</span>}
                    </span>
                }
                subtitle={
                    <span>
                        <span className="font-mono">{escalation.reference}</span> · exception{' '}
                        <span className="font-mono">{escalation.exception?.reference}</span> · respond by{' '}
                        {formatDateTime(escalation.response_due_at)}
                    </span>
                }
                breadcrumbs={[
                    { label: 'Exception Manager', href: route('exception-manager.index') },
                    { label: escalation.reference, href: route('exception-manager.show', escalation.id) },
                    { label: 'Respond' },
                ]}
            />

            <div className="mx-auto max-w-4xl space-y-6">
                <div className="card">
                    <div className="card-header"><h3 className="text-sm font-semibold">What is being asked of your department</h3></div>
                    <div className="card-body space-y-3 text-sm">
                        <p className="font-medium">{escalation.exception?.title}</p>
                        {escalation.exception?.description && <p className="text-gray-600">{escalation.exception.description}</p>}
                        <div className="rounded-lg border border-blue-100 bg-blue-50 p-3">
                            <p className="whitespace-pre-line text-blue-900">{escalation.issue_note}</p>
                            <p className="mt-2 text-xs text-blue-700">
                                Issued by {escalation.issuer?.name} · required: {String(escalation.required_response).replace('_', ' ')}
                            </p>
                        </div>
                        {(escalation.responses ?? []).filter((r) => r.review_status === 'Rejected').map((r) => (
                            <div key={r.id} className="rounded-lg border border-red-200 bg-red-50 p-3">
                                <p className="text-xs font-semibold uppercase text-red-800">Round {r.round_no} was rejected</p>
                                <p className="mt-1 text-red-900">{r.rejection_reason}</p>
                            </div>
                        ))}
                    </div>
                </div>

                <form onSubmit={submit} className="card">
                    <div className="card-header"><h3 className="text-sm font-semibold">Your response — round {escalation.round_no}</h3></div>
                    <div className="card-body space-y-4">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel value="Position" required />
                                <SelectInput value={form.data.position} onChange={(e) => form.setData('position', e.target.value)}>
                                    <option value="">— Select —</option>
                                    {POSITIONS.map((p) => <option key={p} value={p}>{p}</option>)}
                                </SelectInput>
                                <InputError message={form.errors.position} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="Root cause category" />
                                <SelectInput value={form.data.root_cause_category} onChange={(e) => form.setData('root_cause_category', e.target.value)}>
                                    <option value="">— Select —</option>
                                    {ROOT_CAUSE_CATEGORIES.map((c) => <option key={c} value={c}>{c}</option>)}
                                </SelectInput>
                                <InputError message={form.errors.root_cause_category} className="mt-1" />
                            </div>
                        </div>

                        <div>
                            <InputLabel value={form.data.position === 'Disputed' ? 'Rebuttal — why is this exception disputed?' : 'Management comment'} required />
                            <RichTextEditor
                                value={form.data.management_comment_rich ?? form.data.management_comment}
                                onChange={(doc, plain) => form.setData((d) => ({ ...d, management_comment: plain, management_comment_rich: doc }))}
                                tools="default"
                                minHeight={130}
                            />
                            <InputError message={form.errors.management_comment} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel value={`Root cause${needsRootCause ? '' : ' (optional)'}`} required={needsRootCause} />
                            <RichTextEditor
                                value={form.data.root_cause_rich ?? form.data.root_cause}
                                onChange={(doc, plain) => form.setData((d) => ({ ...d, root_cause: plain, root_cause_rich: doc }))}
                                tools="minimal"
                                minHeight={100}
                            />
                            <InputError message={form.errors.root_cause} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel value={`Agreed action plan${needsActionPlan ? '' : ' (optional)'}`} required={needsActionPlan} />
                            <RichTextEditor
                                value={form.data.agreed_action_plan_rich ?? form.data.agreed_action_plan}
                                onChange={(doc, plain) => form.setData((d) => ({ ...d, agreed_action_plan: plain, agreed_action_plan_rich: doc }))}
                                tools="default"
                                minHeight={100}
                            />
                            <InputError message={form.errors.agreed_action_plan} className="mt-1" />
                        </div>

                        <div className="max-w-xs">
                            <InputLabel value="Proposed target date" />
                            <TextInput
                                type="date"
                                value={form.data.proposed_target_date}
                                onChange={(e) => form.setData('proposed_target_date', e.target.value)}
                            />
                            <InputError message={form.errors.proposed_target_date} className="mt-1" />
                        </div>

                        {form.data.position === 'Disputed' && (
                            <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                                A dispute needs a substantive rebuttal and supporting evidence — upload the evidence below before submitting.
                            </p>
                        )}
                    </div>
                    <div className="flex justify-end gap-2 border-t border-gray-100 px-6 py-4">
                        <SecondaryButton onClick={saveDraft} disabled={form.processing}>Save draft</SecondaryButton>
                        <PrimaryButton disabled={form.processing || !form.data.position || !form.data.management_comment.trim()}>
                            Submit response
                        </PrimaryButton>
                    </div>
                </form>

                <EvidencePanel
                    linkedType="exception-escalation"
                    linkedId={escalation.id}
                    evidence={evidence}
                    canUpload
                />
            </div>
        </AuthenticatedLayout>
    );
}
