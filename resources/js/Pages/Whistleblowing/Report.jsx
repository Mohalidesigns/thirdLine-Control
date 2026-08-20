import InputError from '@/Components/InputError';
import RichTextViewer from '@/Components/RichTextViewer';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * Public Speak-Up intake (11.4 + CR).
 *
 * Two clearly labelled routes:
 *
 *  - Confidential: technical data about the connection and device is
 *    captured for false-report prevention, disclosed by a non-dismissible
 *    notice the reporter must acknowledge before the form will submit.
 *    This route is never called "anonymous" — a channel that logs an IP
 *    is not anonymous and must not claim to be.
 *  - Anonymous (where the organisation keeps it enabled): no identifying
 *    field, no technical capture. The client_meta payload is only ever
 *    attached on the confidential route.
 */
export default function Report({
    concernTypes = [],
    metadataCapture = false,
    anonymousMode = true,
    noticeVersion = 1,
    noticeRich = null,
    noticeText = null,
    retentionMonths = 24,
}) {
    const legacyMode = !metadataCapture;
    const defaultMode = legacyMode || !anonymousMode ? (legacyMode ? 'anonymous' : 'confidential') : 'confidential';

    const [clientMeta, setClientMeta] = useState({});

    const { data, setData, post, processing, errors, transform } = useForm({
        title: '',
        description: '',
        concern_type: '',
        entity_hint: '',
        mode: defaultMode,
        anonymous: defaultMode === 'anonymous',
        notice_acknowledged: false,
        client_meta: {},
    });

    useEffect(() => {
        try {
            setClientMeta({
                platform: navigator.platform ?? null,
                screen_resolution: `${window.screen?.width ?? 0}x${window.screen?.height ?? 0}`,
                color_depth: window.screen?.colorDepth ?? null,
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone ?? null,
                timezone_offset: -new Date().getTimezoneOffset(),
                locale: (navigator.languages ?? [navigator.language]).filter(Boolean).join(',') || null,
                hardware_concurrency: navigator.hardwareConcurrency ?? null,
                device_memory: navigator.deviceMemory ?? null,
                touch_support: (navigator.maxTouchPoints ?? 0) > 0,
            });
        } catch {
            setClientMeta({});
        }
    }, []);

    const anonymous = data.mode === 'anonymous';

    const submit = (e) => {
        e.preventDefault();
        transform((form) => ({
            ...form,
            anonymous: form.mode === 'anonymous',
            // The anonymous route sends nothing about the device — the
            // server discards it anyway; not collecting is cleaner still.
            client_meta: form.mode === 'anonymous' ? {} : clientMeta,
        }));
        post(route('whistleblowing.store'));
    };

    return (
        <GuestLayout>
            <Head title="Speak Up" />

            <div className="w-full max-w-2xl">
                <div className="mb-4 text-center">
                    <h1 className="text-2xl font-bold text-white">Speak Up</h1>
                    <p className="mt-1 text-sm text-white/70">
                        Raise a concern about fraud, corruption, a regulatory breach or misconduct.
                    </p>
                </div>

                <form onSubmit={submit} className="card">
                    <div className="card-body space-y-4">
                        {legacyMode ? (
                            <div className="rounded-lg bg-gray-50 p-4 text-sm text-[var(--color-text-secondary)]">
                                <p className="font-semibold text-[var(--color-text-primary)]">Your anonymity</p>
                                <p className="mt-1">
                                    If you leave “Report anonymously” ticked, nothing that identifies you is recorded — no
                                    name, no account, no address. You will be given a reference token once, and it is the
                                    only way to follow the case or add to it. Keep it somewhere safe: it cannot be reissued.
                                </p>
                            </div>
                        ) : (
                            <div className="space-y-2">
                                <p className="form-label">How would you like to report?</p>
                                <label
                                    className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm ${
                                        !anonymous ? 'border-[var(--color-primary)] bg-blue-50/50' : 'border-gray-200'
                                    }`}
                                >
                                    <input
                                        type="radio"
                                        name="mode"
                                        className="mt-0.5"
                                        checked={!anonymous}
                                        onChange={() => setData('mode', 'confidential')}
                                    />
                                    <span>
                                        <span className="font-semibold">Confidential</span>
                                        <span className="block text-xs text-[var(--color-text-secondary)]">
                                            Your report is restricted to the investigating team. Technical data about your
                                            connection and device is recorded, as set out in the notice below.
                                        </span>
                                    </span>
                                </label>

                                {anonymousMode && (
                                    <label
                                        className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm ${
                                            anonymous ? 'border-[var(--color-primary)] bg-blue-50/50' : 'border-gray-200'
                                        }`}
                                    >
                                        <input
                                            type="radio"
                                            name="mode"
                                            className="mt-0.5"
                                            checked={anonymous}
                                            onChange={() => setData('mode', 'anonymous')}
                                        />
                                        <span>
                                            <span className="font-semibold">Anonymous</span>
                                            <span className="block text-xs text-[var(--color-text-secondary)]">
                                                Nothing that identifies you is recorded — no name, no account, no address,
                                                and no technical data about your connection or device.
                                            </span>
                                        </span>
                                    </label>
                                )}
                            </div>
                        )}

                        <div>
                            <label className="form-label">
                                What is your concern about? <span className="text-red-500">*</span>
                            </label>
                            <input
                                className="form-input"
                                value={data.title}
                                onChange={(e) => setData('title', e.target.value)}
                                placeholder="A short summary"
                            />
                            <InputError message={errors.title} className="mt-1" />
                        </div>

                        <div>
                            <label className="form-label">Type of concern</label>
                            <select
                                className="form-select"
                                value={data.concern_type}
                                onChange={(e) => setData('concern_type', e.target.value)}
                            >
                                <option value="">Prefer not to say</option>
                                {concernTypes.map((type) => (
                                    <option key={type} value={type}>
                                        {type}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="form-label">
                                Tell us what happened <span className="text-red-500">*</span>
                            </label>
                            <textarea
                                className="form-input"
                                rows={8}
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                placeholder={
                                    anonymous
                                        ? 'What happened, when, and who was involved. Please do not include your own name if you want to stay anonymous.'
                                        : 'What happened, when, and who was involved.'
                                }
                            />
                            <InputError message={errors.description} className="mt-1" />
                        </div>

                        {!legacyMode && !anonymous && (
                            <div className="rounded-lg border border-[var(--color-primary)]/30 bg-gray-50 p-4 text-sm">
                                <p className="font-semibold text-[var(--color-text-primary)]">
                                    What we collect with a confidential report, and why
                                </p>
                                {noticeRich || noticeText ? (
                                    noticeRich ? (
                                        <RichTextViewer value={noticeRich} className="mt-2" />
                                    ) : (
                                        <p className="mt-2 whitespace-pre-line text-[var(--color-text-secondary)]">{noticeText}</p>
                                    )
                                ) : (
                                    <div className="mt-2 space-y-2 text-[var(--color-text-secondary)]">
                                        <p>
                                            When you submit, we record technical data about this submission: your IP address
                                            and network details, your browser and device characteristics, your time zone and
                                            language, and — if you are signed in to a staff account — your staff identity.
                                        </p>
                                        <p>
                                            <span className="font-medium text-[var(--color-text-primary)]">Why:</span> to
                                            protect the channel against false or malicious reports and to preserve the
                                            integrity of investigations. It is never used to discourage genuine reporting.
                                        </p>
                                        <p>
                                            <span className="font-medium text-[var(--color-text-primary)]">Who can see it:</span>{' '}
                                            investigating officers see only non-identifying indicators (device type, browser,
                                            coarse location). Identifying data — your IP address, network, or identity — is
                                            held in a restricted vault and opens only with a written justification approved by
                                            a second authorised officer. Every such access is permanently logged and
                                            independently auditable.
                                        </p>
                                        <p>
                                            <span className="font-medium text-[var(--color-text-primary)]">How long:</span> up
                                            to {retentionMonths} months after your case closes, then it is permanently
                                            deleted. Under the Nigeria Data Protection Act you have rights of access,
                                            correction and erasure, and may complain to the NDPC.
                                        </p>
                                    </div>
                                )}

                                <label className="mt-3 flex items-start gap-2 border-t border-gray-200 pt-3">
                                    <input
                                        type="checkbox"
                                        className="mt-0.5"
                                        checked={data.notice_acknowledged}
                                        onChange={(e) => setData('notice_acknowledged', e.target.checked)}
                                    />
                                    <span className="text-[var(--color-text-primary)]">
                                        I have read and understood what technical data is collected with a confidential
                                        report and how it is protected.
                                    </span>
                                </label>
                                <InputError message={errors.notice_acknowledged} className="mt-1" />
                            </div>
                        )}

                        {legacyMode && (
                            <label className="flex items-start gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    className="mt-0.5"
                                    checked={data.mode === 'anonymous'}
                                    onChange={(e) => setData('mode', e.target.checked ? 'anonymous' : 'confidential')}
                                />
                                <span>
                                    Report anonymously
                                    <span className="block text-xs text-gray-400">
                                        Untick only if you are happy for your identity to be recorded on the case.
                                    </span>
                                </span>
                            </label>
                        )}

                        <InputError message={errors.mode} className="mt-1" />

                        <div className="flex items-center justify-between">
                            <Link href={route('whistleblowing.status')} className="text-sm text-[var(--color-primary)] hover:underline">
                                I already have a token
                            </Link>
                            <button
                                type="submit"
                                className="btn-primary"
                                disabled={processing || (!legacyMode && !anonymous && !data.notice_acknowledged)}
                            >
                                {processing ? 'Submitting…' : 'Submit report'}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </GuestLayout>
    );
}
