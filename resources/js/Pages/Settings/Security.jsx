import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import DangerButton from '@/Components/DangerButton';
import TextInput from '@/Components/TextInput';
import { Head, useForm } from '@inertiajs/react';

export default function Security({ mfaEnabled, mfaEnforced, enrolment, recoveryCodes }) {
    const confirmForm = useForm({ code: '' });
    const disableForm = useForm({ password: '' });

    return (
        <AuthenticatedLayout header="Security">
            <Head title="Security" />

            <PageHeader
                title="Security settings"
                subtitle="Multi-factor authentication protects your account with a second step at sign-in."
            />

            {recoveryCodes && (
                <div className="card mb-6 border-[var(--color-accent)]">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold text-[var(--color-text-primary)]">
                            Recovery codes — save these now
                        </h3>
                    </div>
                    <div className="card-body">
                        <p className="mb-3 text-sm text-[var(--color-error)]">
                            Each code works once. They are shown only this once — store them in a safe place.
                        </p>
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            {recoveryCodes.map((code) => (
                                <code key={code} className="rounded bg-gray-100 px-2 py-1 text-center font-mono text-xs">
                                    {code}
                                </code>
                            ))}
                        </div>
                    </div>
                </div>
            )}

            {mfaEnabled ? (
                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold text-[var(--color-text-primary)]">
                            Multi-factor authentication
                        </h3>
                        <span className="badge badge-status-active">Enabled</span>
                    </div>
                    <div className="card-body">
                        {mfaEnforced ? (
                            <p className="text-sm text-[var(--color-text-secondary)]">
                                MFA is enforced for your role by your organisation's policy and cannot be disabled.
                            </p>
                        ) : (
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    disableForm.delete(route('mfa.disable'), {
                                        onFinish: () => disableForm.reset('password'),
                                    });
                                }}
                                className="max-w-sm space-y-3"
                            >
                                <p className="text-sm text-[var(--color-text-secondary)]">
                                    Confirm your password to disable MFA.
                                </p>
                                <div>
                                    <InputLabel htmlFor="password" value="Current password" required />
                                    <TextInput
                                        id="password"
                                        type="password"
                                        value={disableForm.data.password}
                                        autoComplete="current-password"
                                        onChange={(e) => disableForm.setData('password', e.target.value)}
                                    />
                                    <InputError message={disableForm.errors.password} className="mt-1" />
                                </div>
                                <DangerButton disabled={disableForm.processing}>Disable MFA</DangerButton>
                            </form>
                        )}
                    </div>
                </div>
            ) : (
                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold text-[var(--color-text-primary)]">
                            Enable multi-factor authentication
                        </h3>
                        <span className="badge badge-status-draft">Not enabled</span>
                    </div>
                    <div className="card-body">
                        <div className="flex flex-col gap-6 sm:flex-row">
                            <div className="shrink-0">
                                {enrolment?.qr_svg && (
                                    <div
                                        className="inline-block rounded-lg border border-gray-200 p-2"
                                        dangerouslySetInnerHTML={{ __html: enrolment.qr_svg }}
                                    />
                                )}
                                <p className="mt-2 max-w-[220px] break-all text-center font-mono text-[10px] text-[var(--color-text-secondary)]">
                                    {enrolment?.secret}
                                </p>
                            </div>
                            <div className="flex-1">
                                <ol className="mb-4 list-decimal space-y-1 ps-5 text-sm text-[var(--color-text-secondary)]">
                                    <li>Scan the QR code with Google Authenticator, Microsoft Authenticator, or any TOTP app.</li>
                                    <li>Enter the 6-digit code the app shows to confirm.</li>
                                </ol>
                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        confirmForm.post(route('mfa.confirm'), {
                                            onFinish: () => confirmForm.reset('code'),
                                        });
                                    }}
                                    className="max-w-xs space-y-3"
                                >
                                    <div>
                                        <InputLabel htmlFor="code" value="Code from your app" required />
                                        <TextInput
                                            id="code"
                                            value={confirmForm.data.code}
                                            autoComplete="one-time-code"
                                            placeholder="123456"
                                            onChange={(e) => confirmForm.setData('code', e.target.value)}
                                        />
                                        <InputError message={confirmForm.errors.code} className="mt-1" />
                                    </div>
                                    <PrimaryButton disabled={confirmForm.processing}>Confirm & enable</PrimaryButton>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
