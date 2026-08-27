import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PasswordInput from '@/Components/PasswordInput';
import TextInput from '@/Components/TextInput';
import AuthSplitLayout from '@/Layouts/AuthSplitLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, Mail } from 'lucide-react';

export default function Login({ status, ssoConfigurations = [] }) {
    const branding = usePage().props.branding;

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
        <AuthSplitLayout title="Welcome back" subtitle="Sign in to your account to continue">
            <Head title="Log in" />

            {status && (
                <div className="mt-6 rounded-lg bg-[var(--color-success)]/10 px-4 py-3 text-sm font-medium text-[var(--color-success)]">
                    {status}
                </div>
            )}

            <form onSubmit={submit} className="mt-8 space-y-5">
                <div>
                    <InputLabel htmlFor="email" value="Email address" />
                    <div className="relative">
                        <Mail
                            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--color-text-secondary)]"
                            aria-hidden="true"
                        />
                        <TextInput
                            id="email"
                            type="email"
                            name="email"
                            value={data.email}
                            autoComplete="username"
                            placeholder="you@company.com"
                            isFocused
                            className="py-2.5 pl-10"
                            onChange={(e) => setData('email', e.target.value)}
                        />
                    </div>
                    <InputError message={errors.email} className="mt-1" />
                </div>

                <div>
                    <div className="flex items-baseline justify-between">
                        <InputLabel htmlFor="password" value="Password" />
                        {branding?.support_email && (
                            <a
                                href={`mailto:${branding.support_email}?subject=Password%20reset%20request`}
                                className="mb-1 text-sm font-medium text-[var(--color-primary)] hover:underline"
                            >
                                Forgot password?
                            </a>
                        )}
                    </div>
                    <PasswordInput
                        id="password"
                        name="password"
                        value={data.password}
                        autoComplete="current-password"
                        placeholder="Enter your password"
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
                    <span className="text-sm text-[var(--color-text-primary)]">Keep me signed in</span>
                </label>

                <button type="submit" disabled={processing} className="btn-primary group w-full py-3 text-base">
                    Sign in
                    <ArrowRight
                        className="h-4 w-4 transition-transform group-hover:translate-x-0.5"
                        aria-hidden="true"
                    />
                </button>
            </form>

            {ssoConfigurations.length > 0 && (
                <div className="mt-6">
                    <div className="relative">
                        <div className="absolute inset-0 flex items-center">
                            <div className="w-full border-t border-gray-200" />
                        </div>
                        <div className="relative flex justify-center text-xs">
                            <span className="bg-[var(--color-bg)] px-2 text-[var(--color-text-secondary)]">
                                or continue with
                            </span>
                        </div>
                    </div>
                    <div className="mt-4 space-y-2">
                        {ssoConfigurations.map((config) => (
                            <a key={config.id} href={route('sso.redirect', config.id)} className="btn-secondary w-full">
                                {config.display_name}
                            </a>
                        ))}
                    </div>
                </div>
            )}
        </AuthSplitLayout>
    );
}
