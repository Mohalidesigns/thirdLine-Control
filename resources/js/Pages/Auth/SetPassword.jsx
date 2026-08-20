import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';

/**
 * The signed set-password screen shared by invitations and admin-triggered
 * resets. Posts back to the same signed URL it was opened from.
 */
export default function SetPassword({ name, email, isInvite, action }) {
    const { data, setData, post, processing, errors } = useForm({
        password: '',
        password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(action);
    };

    return (
        <GuestLayout>
            <Head title={isInvite ? 'Activate your account' : 'Choose a new password'} />

            <h1 className="mb-1 text-lg font-semibold text-[var(--color-text-primary)]">
                {isInvite ? `Welcome, ${name}` : 'Choose a new password'}
            </h1>
            <p className="mb-6 text-sm text-[var(--color-text-secondary)]">
                {isInvite
                    ? `Set a password for ${email} to activate your account.`
                    : `You are changing the password for ${email}.`}
            </p>

            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel htmlFor="password" value="New password" required />
                    <TextInput
                        id="password"
                        type="password"
                        value={data.password}
                        autoComplete="new-password"
                        isFocused
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors.password} className="mt-1" />
                </div>

                <div>
                    <InputLabel htmlFor="password_confirmation" value="Confirm password" required />
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
                    {processing ? 'Saving…' : isInvite ? 'Activate account' : 'Change password'}
                </PrimaryButton>
            </form>
        </GuestLayout>
    );
}
