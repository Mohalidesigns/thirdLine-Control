import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, router } from '@inertiajs/react';

function Toggle({ checked, onChange, disabled = false }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)] focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 ${
                checked ? 'bg-[var(--color-success)]' : 'bg-gray-300'
            }`}
        >
            <span
                className={`inline-block h-4 w-4 transform rounded-full bg-white transition-transform duration-200 ${
                    checked ? 'translate-x-6' : 'translate-x-1'
                }`}
            />
        </button>
    );
}

export default function FeatureFlags({ flags }) {
    const setFlag = (key, scope, current, next) => {
        router.put(
            route('admin.feature-flags.update', key),
            {
                scope,
                is_enabled: next,
                rollout_percentage: current?.rollout_percentage ?? 100,
            },
            { preserveScroll: true },
        );
    };

    return (
        <AuthenticatedLayout header="Feature Flags">
            <Head title="Feature Flags" />

            <PageHeader
                title="Feature flags"
                subtitle="Modules ship dark and are enabled here. A tenant override wins over the global default."
            />

            <div className="card overflow-x-auto">
                <table className="data-table">
                    <thead>
                        <tr>
                            <th>Feature</th>
                            <th>Description</th>
                            <th>Global default</th>
                            <th>Tenant override</th>
                            <th>Effective</th>
                        </tr>
                    </thead>
                    <tbody>
                        {flags.map((flag) => {
                            const effective = flag.tenant ?? flag.global;
                            return (
                                <tr key={flag.key}>
                                    <td className="font-mono text-xs font-semibold">{flag.key}</td>
                                    <td className="max-w-md text-xs text-[var(--color-text-secondary)]">
                                        {flag.description}
                                    </td>
                                    <td>
                                        <Toggle
                                            checked={!!flag.global?.is_enabled}
                                            onChange={(next) => setFlag(flag.key, 'global', flag.global, next)}
                                        />
                                    </td>
                                    <td>
                                        <div className="flex items-center gap-2">
                                            <Toggle
                                                checked={flag.tenant ? !!flag.tenant.is_enabled : !!flag.global?.is_enabled}
                                                onChange={(next) => setFlag(flag.key, 'tenant', flag.tenant, next)}
                                            />
                                            {!flag.tenant && (
                                                <span className="text-xs text-[var(--color-text-secondary)]">inherits</span>
                                            )}
                                        </div>
                                    </td>
                                    <td>
                                        <span className={`badge ${effective?.is_enabled ? 'badge-status-active' : 'badge-status-draft'}`}>
                                            {effective?.is_enabled ? 'Enabled' : 'Disabled'}
                                            {effective?.is_enabled && effective.rollout_percentage < 100
                                                ? ` · ${effective.rollout_percentage}%`
                                                : ''}
                                        </span>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
