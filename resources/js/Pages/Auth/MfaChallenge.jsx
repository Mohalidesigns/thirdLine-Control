import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import AuthSplitLayout from '@/Layouts/AuthSplitLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowRight, KeyRound } from 'lucide-react';

export default function MfaChallenge({ emailOtpSent }) {
    const { data, setData, post, processing, errors, reset } = useForm({ code: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('mfa.verify'), { onFinish: () => reset('code') });
    };

    return (
        <AuthSplitLayout
            title="Two-factor verification"
            subtitle="Enter the 6-digit code from your authenticator app, or use a recovery code."
        >
            <Head title="Two-factor verification" />

            {emailOtpSent && (
                <div className="mt-6 rounded-lg bg-[var(--color-info)]/10 px-4 py-3 text-sm font-medium text-[var(--color-info)]">
                    A one-time code has been emailed to you. It expires in 10 minutes.
                </div>
            )}

            <form onSubmit={submit} className="mt-8 space-y-5">
                <div>
                    <InputLabel htmlFor="code" value="Verification code" required />
                    <div className="relative">
                        <KeyRound
                            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--color-text-secondary)]"
                            aria-hidden="true"
                        />
                        <TextInput
                            id="code"
                            name="code"
                            value={data.code}
                            autoComplete="one-time-code"
                            placeholder="123456 or XXXXX-XXXXX"
                            isFocused
                            className="py-2.5 pl-10"
                            onChange={(e) => setData('code', e.target.value)}
                        />
                    </div>
                    <InputError message={errors.code} className="mt-1" />
                </div>

                <button type="submit" disabled={processing} className="btn-primary group w-full py-3 text-base">
                    Verify
                    <ArrowRight
                        className="h-4 w-4 transition-transform group-hover:translate-x-0.5"
                        aria-hidden="true"
                    />
                </button>
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
        </AuthSplitLayout>
    );
}
