import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';

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
        <GuestLayout>
            <Head title="Change your password" />

            <h1 className="mb-1 text-lg font-semibold text-[var(--color-text-primary)]">Change your password</h1>
            <p className="mb-6 text-sm text-[var(--color-text-secondary)]">
                Your account was created with a temporary password. Choose your own to continue.
            </p>

            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel htmlFor="current_password" value="Temporary password" required />
                    <TextInput
                        id="current_password"
                        type="password"
                        value={data.current_password}
                        autoComplete="current-password"
                        isFocused
                        onChange={(e) => setData('current_password', e.target.value)}
                    />
                    <InputError message={errors.current_password} className="mt-1" />
                </div>

                <div>
                    <InputLabel htmlFor="password" value="New password" required />
                    <TextInput
                        id="password"
                        type="password"
                        value={data.password}
                        autoComplete="new-password"
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors.password} className="mt-1" />
                </div>

                <div>
                    <InputLabel htmlFor="password_confirmation" value="Confirm new password" required />
                    <TextInput
                        id="password_confirmation"
                        type="password"
                        value={data.password_confirmation}
                        autoComplete="new-password"
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                    />
                    <InputError message={errors.password_confirmation} className="mt-1" />
                </div>

                <PrimaryButton type="submit" className="w-full" disabled={processing}>
                    {processing ? 'Saving…' : 'Change password'}
                </PrimaryButton>
            </form>
        </GuestLayout>
    );
}
