import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';

const VISIBILITY_LABELS = {
    private: 'Private to you',
    role: 'Shared with roles',
    tenant: 'Everyone in the organisation',
    public_link: 'Anyone with the link',
};

export default function Index({ dashboards = [], homes = [], can = {} }) {
    const hasShipped = dashboards.some((dashboard) => dashboard.is_system);

    return (
        <AuthenticatedLayout header="Dashboards">
            <Head title="Dashboards" />

            <PageHeader
                title="Dashboards"
                subtitle="Configure the dashboards, and choose which one each role sees on its home page."
                breadcrumbs={[{ label: 'Reporting' }, { label: 'Dashboards' }]}
                actions={
                    can.create && (
                        <Link href={route('dashboards.create')} className="btn-primary">
                            <Plus className="h-4 w-4" strokeWidth={2} /> New dashboard
                        </Link>
                    )
                }
            />

            {hasShipped && can.create && (
                <p className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
                    Shipped dashboards are read-only. Duplicate one to make a version you can edit.
                </p>
            )}

            <HomeAssignments homes={homes} dashboards={dashboards} canAssign={can.assignHome} />

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
                                {dashboard.home_for?.length > 0 && (
                                    <div className="mt-3 flex flex-wrap items-center gap-1.5">
                                        <span className="text-xs text-gray-500">Home for</span>
                                        {dashboard.home_for.map((role) => (
                                            <span key={role} className="badge badge-status-pending">{role}</span>
                                        ))}
                                    </div>
                                )}
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

/**
 * The board each role lands on at /dashboard. The landing page shows only
 * what is chosen here — it carries no configuration of its own.
 */
function HomeAssignments({ homes, dashboards, canAssign }) {
    const { errors = {} } = usePage().props;

    if (homes.length === 0) {
        return null;
    }

    const nameOf = (id) => dashboards.find((dashboard) => dashboard.id === id)?.name;

    const assign = (roleId, dashboardId) =>
        router.put(
            route('dashboards.home.update', roleId),
            { dashboard_id: dashboardId || null },
            { preserveScroll: true },
        );

    return (
        <div className="card mb-6">
            <div className="card-header">
                <div>
                    <h3 className="text-sm font-semibold text-gray-800">Home dashboard by role</h3>
                    <p className="mt-0.5 text-xs text-gray-500">
                        What each role sees when it opens Dashboard. A board must be shared with the role first.
                    </p>
                </div>
            </div>
            {errors.dashboard_id && (
                <p className="border-b border-red-100 bg-red-50 px-6 py-2 text-sm text-red-700">{errors.dashboard_id}</p>
            )}
            <div className="overflow-x-auto">
                <table className="data-table">
                    <thead>
                        <tr>
                            <th>Role</th>
                            <th>Home dashboard</th>
                        </tr>
                    </thead>
                    <tbody>
                        {homes.map((home) => (
                            <tr key={home.role_id}>
                                <td className="text-sm font-medium text-gray-800">{home.role}</td>
                                <td>
                                    {canAssign ? (
                                        <select
                                            className="form-select max-w-xs"
                                            value={home.dashboard_id ?? ''}
                                            onChange={(event) => assign(home.role_id, event.target.value)}
                                            aria-label={`Home dashboard for ${home.role}`}
                                        >
                                            <option value="">First dashboard they can open</option>
                                            {dashboards.map((dashboard) => (
                                                <option key={dashboard.id} value={dashboard.id}>
                                                    {dashboard.name}
                                                </option>
                                            ))}
                                        </select>
                                    ) : (
                                        <span className="text-sm text-gray-600">
                                            {nameOf(home.dashboard_id) ?? 'First dashboard they can open'}
                                        </span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
