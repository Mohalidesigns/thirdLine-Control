import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

export default function Submitted({ token = null, caseRef = null }) {
    const [copied, setCopied] = useState(false);

    const copy = () => {
        navigator.clipboard?.writeText(token).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        });
    };

    return (
        <GuestLayout>
            <Head title="Report received" />

            <div className="w-full max-w-xl">
                <div className="card">
                    <div className="card-body space-y-4 text-center">
                        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-green-100">
                            <svg
                                className="h-6 w-6 text-[var(--color-success)]"
                                fill="none"
                                viewBox="0 0 24 24"
                                strokeWidth={2}
                                stroke="currentColor"
                            >
                                <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                            </svg>
                        </div>

                        <h1 className="text-xl font-bold text-[var(--color-text-primary)]">Your report has been received</h1>

                        {token ? (
                            <>
                                <p className="text-sm text-[var(--color-text-secondary)]">
                                    This is your reference token. It is shown once and cannot be reissued — copy it
                                    somewhere safe now. It is the only way to check progress or add more information,
                                    and it does not identify you.
                                </p>

                                <div className="rounded-lg border-2 border-dashed border-[var(--color-accent)] bg-amber-50 p-4">
                                    <p className="font-mono text-xl font-bold tracking-wider text-[var(--color-primary)]">
                                        {token}
                                    </p>
                                </div>

                                <button type="button" className="btn-secondary" onClick={copy}>
                                    {copied ? 'Copied' : 'Copy token'}
                                </button>
                            </>
                        ) : (
                            <p className="text-sm text-[var(--color-text-secondary)]">
                                Your report has been recorded against your account
                                {caseRef ? ` as ${caseRef}` : ''}. You can follow it from the case list.
                            </p>
                        )}

                        <div className="pt-2">
                            <Link href={route('whistleblowing.status')} className="text-sm text-[var(--color-primary)] hover:underline">
                                Check the status of a report
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </GuestLayout>
    );
}
