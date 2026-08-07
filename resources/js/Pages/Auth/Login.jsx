import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';

export default function Login({ status, ssoConfigurations = [] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('login'), { onFinish: () => reset('password') });
    };

    return (
        <GuestLayout>
            <Head title="Log in" />

            <h1 className="mb-6 text-lg font-semibold text-[var(--color-text-primary)]">Sign in to your workspace</h1>

            {status && <div className="mb-4 text-sm font-medium text-[var(--color-success)]">{status}</div>}

            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel htmlFor="email" value="Email" required />
                    <TextInput
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        autoComplete="username"
                        isFocused
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    <InputError message={errors.email} className="mt-1" />
                </div>

                <div>
                    <InputLabel htmlFor="password" value="Password" required />
                    <TextInput
                        id="password"
                        type="password"
                        name="password"
                        value={data.password}
                        autoComplete="current-password"
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors.password} className="mt-1" />
                </div>

                <label className="flex items-center gap-2">
                    <Checkbox
                        name="remember"
                        checked={data.remember}
                        onChange={(e) => setData('remember', e.target.checked)}
                    />
                    <span className="text-sm text-gray-600">Remember me</span>
                </label>

                <PrimaryButton className="w-full" disabled={processing}>
                    Log in
                </PrimaryButton>
            </form>

            {ssoConfigurations.length > 0 && (
                <div className="mt-6">
                    <div className="relative">
                        <div className="absolute inset-0 flex items-center">
                            <div className="w-full border-t border-gray-200" />
                        </div>
                        <div className="relative flex justify-center text-xs">
                            <span className="bg-white px-2 text-[var(--color-text-secondary)]">or continue with</span>
                        </div>
                    </div>
                    <div className="mt-4 space-y-2">
                        {ssoConfigurations.map((config) => (
                            <a
                                key={config.id}
                                href={route('sso.redirect', config.id)}
                                className="btn-secondary w-full"
                            >
                                {config.display_name}
                            </a>
                        ))}
                    </div>
                </div>
            )}
        </GuestLayout>
    );
}
