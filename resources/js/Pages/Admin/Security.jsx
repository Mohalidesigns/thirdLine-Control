import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Head, useForm } from '@inertiajs/react';

export default function Security({ policy, roles }) {
    const { data, setData, put, processing, errors } = useForm({
        mfa_enforced_roles: policy.mfa_enforced_roles ?? [],
        mfa_grace_period_days: policy.mfa_grace_period_days ?? 7,
        mfa_sms_opt_in: !!policy.mfa_sms_opt_in,
        break_glass_daily_cap: policy.break_glass_daily_cap ?? 3,
    });

    const toggleRole = (role) => {
        setData(
            'mfa_enforced_roles',
            data.mfa_enforced_roles.includes(role)
                ? data.mfa_enforced_roles.filter((r) => r !== role)
                : [...data.mfa_enforced_roles, role],
        );
    };

    return (
        <AuthenticatedLayout header="Security Policy">
            <Head title="Security Policy" />

            <PageHeader
                title="Security policy"
                subtitle="Tenant-wide authentication rules. Removing MFA enforcement from a role raises a high-severity audit event and alerts every System Administrator."
            />

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    put(route('admin.security.update'), { preserveScroll: true });
                }}
                className="max-w-2xl space-y-6"
            >
                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold text-[var(--color-text-primary)]">MFA enforcement</h3>
                    </div>
                    <div className="card-body space-y-4">
                        <div>
                            <InputLabel value="Roles that must use MFA" />
                            <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                {roles.map((role) => (
                                    <label key={role} className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            className="rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                            checked={data.mfa_enforced_roles.includes(role)}
                                            onChange={() => toggleRole(role)}
                                        />
                                        <span className="text-sm text-gray-700">{role}</span>
                                    </label>
                                ))}
                            </div>
                            <InputError message={errors.mfa_enforced_roles} className="mt-1" />
                        </div>

                        <div className="max-w-[200px]">
                            <InputLabel htmlFor="grace" value="Grace period (days)" required />
                            <TextInput
                                id="grace"
                                type="number"
                                min="0"
                                max="90"
                                value={data.mfa_grace_period_days}
                                onChange={(e) => setData('mfa_grace_period_days', Number(e.target.value))}
                            />
                            <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                                After the grace period, users in enforced roles are blocked until they enrol.
                            </p>
                            <InputError message={errors.mfa_grace_period_days} className="mt-1" />
                        </div>

                        <div>
                            <label className="flex items-start gap-2">
                                <input
                                    type="checkbox"
                                    className="mt-0.5 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                    checked={data.mfa_sms_opt_in}
                                    onChange={(e) => setData('mfa_sms_opt_in', e.target.checked)}
                                />
                                <span className="text-sm text-gray-700">
                                    Allow SMS one-time codes as an MFA backup
                                    <span className="mt-0.5 block text-xs text-[var(--color-warning)]">
                                        Warning: SMS codes are vulnerable to SIM-swap fraud, which is prevalent in this market.
                                        Authenticator apps and email OTP are strongly recommended instead.
                                    </span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold text-[var(--color-text-primary)]">Break-glass access</h3>
                    </div>
                    <div className="card-body">
                        <div className="max-w-[200px]">
                            <InputLabel htmlFor="cap" value="Break-glass logins per day" required />
                            <TextInput
                                id="cap"
                                type="number"
                                min="1"
                                max="20"
                                value={data.break_glass_daily_cap}
                                onChange={(e) => setData('break_glass_daily_cap', Number(e.target.value))}
                            />
                            <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                                Daily cap on password logins by break-glass accounts once SSO is enforced. Every use is audited.
                            </p>
                            <InputError message={errors.break_glass_daily_cap} className="mt-1" />
                        </div>
                    </div>
                </div>

                <PrimaryButton disabled={processing}>Save policy</PrimaryButton>
            </form>
        </AuthenticatedLayout>
    );
}
