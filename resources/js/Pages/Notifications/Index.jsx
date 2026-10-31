import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import SecondaryButton from '@/Components/SecondaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDateTime } from '@/utils';
import { Head, Link, router } from '@inertiajs/react';

export default function Index({ notifications }) {
    // Every notification opens through the server so it is marked read; one
    // with nothing to link to returns here.
    const linkFor = (notification) => route('notifications.open', notification.id);

    return (
        <AuthenticatedLayout header="Notifications">
            <Head title="Notifications" />

            <PageHeader
                title="Notifications"
                actions={
                    <SecondaryButton onClick={() => router.post(route('notifications.read-all'), {}, { preserveScroll: true })}>
                        Mark all read
                    </SecondaryButton>
                }
            />

            <div className="card">
                {notifications.data.length === 0 ? (
                    <EmptyState title="No notifications" description="Escalations and digests will appear here." />
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {notifications.data.map((notification) => {
                            const href = linkFor(notification);
                            const body = (
                                <div className={`flex items-start justify-between gap-3 px-5 py-3.5 ${!notification.read_at ? 'bg-blue-50/50' : ''}`}>
                                    <div>
                                        <p className="text-sm text-[var(--color-text-primary)]">
                                            {notification.data?.summary ??
                                                (notification.data?.type === 'owner_digest'
                                                    ? `Digest: ${notification.data.open} open exception(s), ${notification.data.overdue} overdue`
                                                    : 'Notification')}
                                        </p>
                                        <p className="mt-0.5 text-xs text-gray-400">{formatDateTime(notification.created_at)}</p>
                                    </div>
                                    {!notification.read_at && <span className="mt-1 h-2 w-2 shrink-0 rounded-full bg-[var(--color-accent)]" />}
                                </div>
                            );

                            return (
                                <li key={notification.id}>
                                    <Link href={href} className="block hover:bg-gray-50">{body}</Link>
                                </li>
                            );
                        })}
                    </ul>
                )}
                <Pagination paginator={notifications} />
            </div>
        </AuthenticatedLayout>
    );
}
