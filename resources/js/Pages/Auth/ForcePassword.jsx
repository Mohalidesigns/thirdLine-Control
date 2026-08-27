import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PasswordInput from '@/Components/PasswordInput';
import AuthSplitLayout from '@/Layouts/AuthSplitLayout';
import { Head, useForm } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

/** Forced first-login change for accounts created with a temporary password. */
export default function ForcePassword() {
    const { data, setData, post, processing, errors, reset } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('password.force.update'), { onError: () => reset('current_password') });
    };

    return (
        <AuthSplitLayout
            title="Change your password"
            subtitle="Your account was created with a temporary password. Choose your own to continue."
        >
            <Head title="Change your password" />

            <form onSubmit={submit} className="mt-8 space-y-5">
                <div>
                    <InputLabel htmlFor="current_password" value="Temporary password" required />
                    <PasswordInput
                        id="current_password"
                        value={data.current_password}
                        autoComplete="current-password"
                        isFocused
                        onChange={(e) => setData('current_password', e.target.value)}
                    />
                    <InputError message={errors.current_password} className="mt-1" />
                </div>

                <div>
                    <InputLabel htmlFor="password" value="New password" required />
                    <PasswordInput
                        id="password"
                        value={data.password}
                        autoComplete="new-password"
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors.password} className="mt-1" />
                </div>

                <div>
                    <InputLabel htmlFor="password_confirmation" value="Confirm new password" required />
                    <PasswordInput
                        id="password_confirmation"
                        value={data.password_confirmation}
                        autoComplete="new-password"
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                    />
                    <InputError message={errors.password_confirmation} className="mt-1" />
                </div>

                <button type="submit" disabled={processing} className="btn-primary group w-full py-3 text-base">
                    {processing ? 'Saving…' : 'Change password'}
                    {!processing && (
                        <ArrowRight
                            className="h-4 w-4 transition-transform group-hover:translate-x-0.5"
                            aria-hidden="true"
                        />
                    )}
                </button>
            </form>
        </AuthSplitLayout>
    );
}
