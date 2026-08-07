import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';

export default function MfaChallenge({ emailOtpSent }) {
    const { data, setData, post, processing, errors, reset } = useForm({ code: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('mfa.verify'), { onFinish: () => reset('code') });
    };

    return (
        <GuestLayout>
            <Head title="Two-factor verification" />

            <h1 className="mb-2 text-lg font-semibold text-[var(--color-text-primary)]">Two-factor verification</h1>
            <p className="mb-6 text-sm text-[var(--color-text-secondary)]">
                Enter the 6-digit code from your authenticator app, or use a recovery code.
            </p>

            {emailOtpSent && (
                <div className="mb-4 rounded-lg bg-blue-50 p-3 text-sm text-blue-800">
                    A one-time code has been emailed to you. It expires in 10 minutes.
                </div>
            )}

            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel htmlFor="code" value="Verification code" required />
                    <TextInput
                        id="code"
                        name="code"
                        value={data.code}
                        autoComplete="one-time-code"
                        isFocused
                        placeholder="123456 or XXXXX-XXXXX"
                        onChange={(e) => setData('code', e.target.value)}
                    />
                    <InputError message={errors.code} className="mt-1" />
                </div>

                <PrimaryButton className="w-full" disabled={processing}>
                    Verify
                </PrimaryButton>
            </form>

            <div className="mt-6 flex items-center justify-between text-sm">
                <Link
                    href={route('mfa.email-otp')}
                    method="post"
                    as="button"
                    className="font-medium text-[var(--color-primary)] underline-offset-2 hover:underline"
                >
                    Email me a backup code
                </Link>
                <Link
                    href={route('logout')}
                    method="post"
                    as="button"
                    className="text-[var(--color-text-secondary)] underline-offset-2 hover:underline"
                >
                    Log out
                </Link>
            </div>
        </GuestLayout>
    );
}
