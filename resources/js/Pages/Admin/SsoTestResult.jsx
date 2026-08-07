import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import SecondaryButton from '@/Components/SecondaryButton';
import { Head, Link } from '@inertiajs/react';

/**
 * Result of an end-to-end SSO test sign-in: the assertion was validated
 * and mapped, but no session was created.
 */
export default function SsoTestResult({ configuration, claims, resolvedRole }) {
    return (
        <AuthenticatedLayout header="SSO Test Result">
            <Head title="SSO Test Result" />

            <PageHeader
                title={`Test result — ${configuration.display_name}`}
                subtitle="The identity provider's assertion was validated successfully. No session was created."
            />

            <div className="card mb-6">
                <div className="card-header">
                    <h3 className="text-sm font-semibold text-[var(--color-text-primary)]">Mapping outcome</h3>
                    <span className="badge badge-status-active">Assertion valid</span>
                </div>
                <div className="card-body">
                    <p className="text-sm">
                        A user asserting these claims would be assigned the role{' '}
                        <span className="font-semibold text-[var(--color-primary)]">{resolvedRole}</span>.
                    </p>
                </div>
            </div>

            <div className="card mb-6">
                <div className="card-header">
                    <h3 className="text-sm font-semibold text-[var(--color-text-primary)]">Asserted claims</h3>
                </div>
                <div className="card-body overflow-x-auto">
                    <table className="data-table">
                        <tbody>
                            {Object.entries(claims).map(([key, value]) => (
                                <tr key={key}>
                                    <td className="font-mono text-xs font-semibold">{key}</td>
                                    <td className="break-all font-mono text-xs">
                                        {typeof value === 'object' ? JSON.stringify(value) : String(value)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <Link href={route('admin.sso')}>
                <SecondaryButton>Back to SSO configuration</SecondaryButton>
            </Link>
        </AuthenticatedLayout>
    );
}
