import PageHeader from '@/Components/PageHeader';
import RichTextEditor from '@/Components/RichTextEditor';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';

/**
 * Speak Up settings (CR §7). Metadata capture, the genuinely anonymous
 * route, retention, reveal reason codes and the versioned NDPA notice.
 * Reveal approvers are named by granting the approve permission on the
 * Roles screen — the platform enforces the permission, not the job title.
 */
export default function SpeakUp({ settings = {}, approverRoles = [], recordsOfProcessing = {} }) {
    const { data, setData, put, processing, errors } = useForm({
        metadata_capture: settings.metadata_capture ?? true,
        anonymous_mode: settings.anonymous_mode ?? true,
        retention_months: settings.retention_months ?? 24,
        reason_codes: Object.values(settings.reason_codes ?? {}),
        notice_rich: settings.notice_rich ?? null,
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('admin.speak-up.update'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header="Speak Up settings">
            <Head title="Speak Up settings" />

            <PageHeader
                title="Speak Up"
                subtitle="Reporter metadata capture, the anonymous route, retention and the NDPA collection notice."
            />

            <form onSubmit={submit} className="grid max-w-5xl grid-cols-1 gap-6 lg:grid-cols-2">
                <div className="space-y-6">
                    <div className="card">
                        <div className="card-header">Capture</div>
                        <div className="card-body space-y-4 text-sm">
                            <label className="flex items-start gap-3">
                                <input
                                    type="checkbox"
                                    className="mt-0.5"
                                    checked={data.metadata_capture}
                                    onChange={(e) => setData('metadata_capture', e.target.checked)}
                                />
                                <span>
                                    <span className="font-medium">Capture reporter technical metadata</span>
                                    <span className="block text-xs text-[var(--color-text-secondary)]">
                                        On the confidential route only, disclosed by the notice and acknowledged before
                                        submission. When off, the module behaves as the original anonymous-only channel.
                                    </span>
                                </span>
                            </label>

                            <label className="flex items-start gap-3">
                                <input
                                    type="checkbox"
                                    className="mt-0.5"
                                    checked={data.anonymous_mode}
                                    onChange={(e) => setData('anonymous_mode', e.target.checked)}
                                />
                                <span>
                                    <span className="font-medium">Offer a genuinely anonymous route</span>
                                    <span className="block text-xs text-[var(--color-text-secondary)]">
                                        A separate, clearly labelled mode that captures no metadata at all.
                                    </span>
                                </span>
                            </label>

                            <div>
                                <label className="form-label">Metadata retention (months from case closure)</label>
                                <input
                                    type="number"
                                    min={1}
                                    max={120}
                                    className="form-input w-32"
                                    value={data.retention_months}
                                    onChange={(e) => setData('retention_months', Number(e.target.value))}
                                />
                                <p className="mt-1 text-xs text-red-600">{errors.retention_months}</p>
                                <p className="mt-1 text-xs text-gray-400">
                                    Independent of, and shorter than, retention on the report itself. Expired rows are
                                    hard-deleted nightly; cases under legal hold are skipped.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">Reveal reason codes</div>
                        <div className="card-body space-y-2 text-sm">
                            {data.reason_codes.map((code, index) => (
                                <div key={index} className="flex gap-2">
                                    <input
                                        className="form-input"
                                        value={code}
                                        onChange={(e) =>
                                            setData(
                                                'reason_codes',
                                                data.reason_codes.map((c, i) => (i === index ? e.target.value : c)),
                                            )
                                        }
                                    />
                                    <button
                                        type="button"
                                        className="text-xs text-[var(--color-error)] hover:underline"
                                        onClick={() =>
                                            setData(
                                                'reason_codes',
                                                data.reason_codes.filter((_, i) => i !== index),
                                            )
                                        }
                                    >
                                        Remove
                                    </button>
                                </div>
                            ))}
                            <button
                                type="button"
                                className="btn-secondary"
                                onClick={() => setData('reason_codes', [...data.reason_codes, ''])}
                            >
                                Add reason code
                            </button>
                            <p className="text-xs text-red-600">{errors.reason_codes}</p>
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">Reveal approvers</div>
                        <div className="card-body text-sm">
                            <p className="text-[var(--color-text-secondary)]">
                                Roles currently holding <code className="text-xs">speak_up.metadata.approve_reveal</code>:
                            </p>
                            <ul className="mt-2 list-inside list-disc">
                                {approverRoles.map((role) => (
                                    <li key={role}>{role}</li>
                                ))}
                            </ul>
                            <p className="mt-2 text-xs text-gray-400">
                                Assign or change approvers on the Roles & Permissions screen. Nobody can approve their own
                                request, whatever roles they hold.
                            </p>
                        </div>
                    </div>
                </div>

                <div className="space-y-6">
                    <div className="card">
                        <div className="card-header">Collection notice (versioned — currently v{settings.notice_version ?? 1})</div>
                        <div className="card-body space-y-2">
                            <RichTextEditor
                                value={data.notice_rich}
                                onChange={(doc) => setData('notice_rich', doc)}
                                tools="default"
                                minHeight={220}
                            />
                            <p className="text-xs text-gray-400">
                                Shown, non-dismissible, above the submit button on the confidential route. Saving new wording
                                bumps the version; each report stores the version its reporter acknowledged. Leave empty to
                                use the built-in wording.
                            </p>
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">Records of processing (NDPA)</div>
                        <div className="card-body space-y-3 text-sm">
                            <div>
                                <p className="text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">Purpose</p>
                                <p className="mt-0.5">{recordsOfProcessing.purpose}</p>
                            </div>
                            <div>
                                <p className="text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">Lawful basis</p>
                                <p className="mt-0.5">{recordsOfProcessing.lawful_basis}</p>
                            </div>
                            <div>
                                <p className="text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">Retention</p>
                                <p className="mt-0.5">{recordsOfProcessing.retention}</p>
                            </div>
                        </div>
                    </div>

                    <div className="flex justify-end">
                        <button type="submit" className="btn-primary" disabled={processing}>
                            {processing ? 'Saving…' : 'Save settings'}
                        </button>
                    </div>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
