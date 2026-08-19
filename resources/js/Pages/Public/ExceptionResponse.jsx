import InputError from '@/Components/InputError';
import GuestLayout from '@/Layouts/GuestLayout';
import { formatDateTime } from '@/utils';
import { Head, useForm, usePage } from '@inertiajs/react';

const POSITIONS = ['Accepted', 'Partially Accepted', 'Disputed', 'Already Remediated', 'More Information Required'];
const ROOT_CAUSE_CATEGORIES = ['people', 'process', 'technology', 'governance', 'external'];

/**
 * CR1.3 / R-H — the secure-link answer form for a respondent outside the
 * system. Branded, minimal, shows only this one exception, no navigation
 * into the app.
 */
export default function ExceptionResponse({ escalation, token, submitted = false }) {
    const { flash } = usePage().props;

    const form = useForm({
        responder_name: escalation.external_name ?? '',
        responder_email: '',
        position: '',
        management_comment: '',
        root_cause: '',
        root_cause_category: '',
        agreed_action_plan: '',
        proposed_target_date: '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.post(route('public.exception-response.submit', { token }));
    };

    const needsRootCause = ['root_cause', 'both'].includes(escalation.required_response) && form.data.position !== 'Disputed';
    const needsActionPlan = ['action_plan', 'both'].includes(escalation.required_response) && form.data.position !== 'Disputed';

    return (
        <GuestLayout>
            <Head title={`Respond — ${escalation.reference}`} />

            <div className="w-full max-w-2xl space-y-4">
                <div className="text-center">
                    <h1 className="text-2xl font-bold text-white">Exception response</h1>
                    <p className="mt-1 text-sm text-white/70">
                        A control exception has been formally issued to {escalation.external_organisation ?? escalation.external_name ?? 'your organisation'}.
                    </p>
                </div>

                <div className="card">
                    <div className="card-body space-y-3 text-sm">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{escalation.reference}</span>
                            <span className={`badge badge-${String(escalation.severity).toLowerCase()}`}>{escalation.severity}</span>
                        </div>
                        <p className="font-medium">{escalation.exception?.title}</p>
                        <div className="rounded-lg bg-gray-50 p-3">
                            <p className="whitespace-pre-line">{escalation.issue_note}</p>
                        </div>
                        <p className="text-xs text-gray-400">
                            Round {escalation.round_no} · respond by {formatDateTime(escalation.response_due_at)}
                        </p>
                    </div>
                </div>

                {submitted || flash?.success ? (
                    <div className="card">
                        <div className="card-body text-center">
                            <p className="text-lg font-semibold text-[var(--color-success)]">Response received</p>
                            <p className="mt-1 text-sm text-gray-500">
                                Your answer is on the record with the control function. This link has now been used and cannot accept another submission.
                            </p>
                        </div>
                    </div>
                ) : (
                    <form onSubmit={submit} className="card">
                        <div className="card-body space-y-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label className="form-label">Your name <span className="text-red-500">*</span></label>
                                    <input className="form-input" value={form.data.responder_name} onChange={(e) => form.setData('responder_name', e.target.value)} />
                                    <InputError message={form.errors.responder_name} className="mt-1" />
                                </div>
                                <div>
                                    <label className="form-label">Your email <span className="text-red-500">*</span></label>
                                    <input className="form-input" type="email" value={form.data.responder_email} onChange={(e) => form.setData('responder_email', e.target.value)} />
                                    <InputError message={form.errors.responder_email} className="mt-1" />
                                </div>
                            </div>

                            <div>
                                <label className="form-label">Position <span className="text-red-500">*</span></label>
                                <select className="form-select" value={form.data.position} onChange={(e) => form.setData('position', e.target.value)}>
                                    <option value="">— Select —</option>
                                    {POSITIONS.map((p) => <option key={p} value={p}>{p}</option>)}
                                </select>
                                <InputError message={form.errors.position} className="mt-1" />
                            </div>

                            <div>
                                <label className="form-label">
                                    {form.data.position === 'Disputed' ? 'Rebuttal' : 'Management comment'} <span className="text-red-500">*</span>
                                </label>
                                <textarea className="form-input" rows="4" value={form.data.management_comment} onChange={(e) => form.setData('management_comment', e.target.value)} />
                                <InputError message={form.errors.management_comment} className="mt-1" />
                            </div>

                            <div>
                                <label className="form-label">Root cause {needsRootCause && <span className="text-red-500">*</span>}</label>
                                <textarea className="form-input" rows="3" value={form.data.root_cause} onChange={(e) => form.setData('root_cause', e.target.value)} />
                                <InputError message={form.errors.root_cause} className="mt-1" />
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label className="form-label">Root cause category</label>
                                    <select className="form-select" value={form.data.root_cause_category} onChange={(e) => form.setData('root_cause_category', e.target.value)}>
                                        <option value="">— Select —</option>
                                        {ROOT_CAUSE_CATEGORIES.map((c) => <option key={c} value={c}>{c}</option>)}
                                    </select>
                                </div>
                                <div>
                                    <label className="form-label">Proposed target date</label>
                                    <input className="form-input" type="date" value={form.data.proposed_target_date} onChange={(e) => form.setData('proposed_target_date', e.target.value)} />
                                    <InputError message={form.errors.proposed_target_date} className="mt-1" />
                                </div>
                            </div>

                            <div>
                                <label className="form-label">Agreed action plan {needsActionPlan && <span className="text-red-500">*</span>}</label>
                                <textarea className="form-input" rows="3" value={form.data.agreed_action_plan} onChange={(e) => form.setData('agreed_action_plan', e.target.value)} />
                                <InputError message={form.errors.agreed_action_plan} className="mt-1" />
                            </div>

                            <button type="submit" className="btn-primary w-full" disabled={form.processing}>
                                Submit response on the record
                            </button>
                            <p className="text-center text-xs text-gray-400">
                                This link answers this one exception only. Once submitted, your response cannot be edited.
                            </p>
                        </div>
                    </form>
                )}
            </div>
        </GuestLayout>
    );
}
