import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

const VISIBILITY_LABELS = {
    private: 'Private to you',
    role: 'Shared with roles',
    tenant: 'Everyone in the organisation',
    public_link: 'Anyone with the link',
};

export default function Index({ dashboards = [], can = {} }) {
    return (
        <AuthenticatedLayout header="Dashboards">
            <Head title="Dashboards" />

            <PageHeader
                title="Dashboards"
                subtitle="Shipped boards, and the ones your organisation has built."
                actions={
                    can.create && (
                        <Link href={route('dashboards.create')} className="btn-primary">
                            + New dashboard
                        </Link>
                    )
                }
            />

            {dashboards.length === 0 ? (
                <EmptyState
                    title="No dashboards yet"
                    description="Create one, or ask an administrator to share a shipped board with your role."
                />
            ) : (
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {dashboards.map((dashboard) => (
                        <div key={dashboard.id} className="card flex h-full flex-col">
                            <div className="card-body flex-1">
                                <div className="flex items-start justify-between gap-2">
                                    <h3 className="text-sm font-semibold text-[var(--color-text-primary)]">
                                        {dashboard.name}
                                    </h3>
                                    {dashboard.is_system && (
                                        <span className="badge badge-status-active shrink-0">Shipped</span>
                                    )}
                                </div>
                                <p className="mt-2 text-xs text-[var(--color-text-secondary)]">
                                    {dashboard.description}
                                </p>
                                <dl className="mt-4 space-y-1 text-xs text-[var(--color-text-secondary)]">
                                    <div className="flex justify-between gap-2">
                                        <dt>Visibility</dt>
                                        <dd>{VISIBILITY_LABELS[dashboard.visibility]}</dd>
                                    </div>
                                    <div className="flex justify-between gap-2">
                                        <dt>Widgets</dt>
                                        <dd className="tabular-nums">{dashboard.widgets_count}</dd>
                                    </div>
                                    <div className="flex justify-between gap-2">
                                        <dt>Owner</dt>
                                        <dd>{dashboard.owner?.name ?? 'Shipped with the platform'}</dd>
                                    </div>
                                </dl>
                            </div>
                            <div className="flex items-center gap-3 border-t border-gray-100 px-5 py-3 text-sm">
                                <Link
                                    href={route('dashboards.show', dashboard.slug)}
                                    className="font-medium text-[var(--color-primary)] hover:underline"
                                >
                                    Open
                                </Link>
                                {dashboard.can.update && (
                                    <Link
                                        href={route('dashboards.edit', dashboard.slug)}
                                        className="text-[var(--color-text-secondary)] hover:underline"
                                    >
                                        Edit
                                    </Link>
                                )}
                                {dashboard.can.duplicate && (
                                    <button
                                        type="button"
                                        onClick={() => router.post(route('dashboards.duplicate', dashboard.slug))}
                                        className="text-[var(--color-text-secondary)] hover:underline"
                                    >
                                        Duplicate
                                    </button>
                                )}
                                {dashboard.can.delete && (
                                    <button
                                        type="button"
                                        onClick={() => router.delete(route('dashboards.destroy', dashboard.slug))}
                                        className="ms-auto text-[var(--color-error)] hover:underline"
                                    >
                                        Delete
                                    </button>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </AuthenticatedLayout>
    );
}
