import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PasswordInput from '@/Components/PasswordInput';
import AuthSplitLayout from '@/Layouts/AuthSplitLayout';
import { Head, useForm } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

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
        <AuthSplitLayout
            title={isInvite ? `Welcome, ${name}` : 'Choose a new password'}
            subtitle={
                isInvite
                    ? `Set a password for ${email} to activate your account.`
                    : `You are changing the password for ${email}.`
            }
        >
            <Head title={isInvite ? 'Activate your account' : 'Choose a new password'} />

            <form onSubmit={submit} className="mt-8 space-y-5">
                <div>
                    <InputLabel htmlFor="password" value="New password" required />
                    <PasswordInput
                        id="password"
                        value={data.password}
                        autoComplete="new-password"
                        isFocused
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors.password} className="mt-1" />
                </div>

                <div>
                    <InputLabel htmlFor="password_confirmation" value="Confirm password" required />
                    <PasswordInput
                        id="password_confirmation"
                        value={data.password_confirmation}
                        autoComplete="new-password"
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                    />
                    <InputError message={errors.password_confirmation} className="mt-1" />
                </div>

                <button type="submit" disabled={processing} className="btn-primary group w-full py-3 text-base">
                    {processing ? 'Saving…' : isInvite ? 'Activate account' : 'Change password'}
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
