import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Suspense, lazy, useEffect, useRef, useState } from 'react';

// The chart layer is a separate chunk: a viewer who never opens a dashboard
// never downloads it, which is what keeps the rest of the app inside the
// performance budget on a mid-range Android over 4G (R6).
const Widget = lazy(() => import('@/Components/Charts/Widget'));

export default function Show({ dashboard, widgets = [], available = [], can = {} }) {
    const { lowBandwidth } = usePage().props;

    return (
        <AuthenticatedLayout header={dashboard.name}>
            <Head title={dashboard.name} />

            <PageHeader
                title={dashboard.name}
                subtitle={dashboard.description}
                actions={
                    <>
                        {available.length > 1 && (
                            <select
                                className="form-select w-56"
                                value={dashboard.slug}
                                onChange={(event) => router.visit(route('dashboards.show', event.target.value))}
                                aria-label="Switch dashboard"
                            >
                                {available.map((item) => (
                                    <option key={item.slug} value={item.slug}>
                                        {item.name}
                                    </option>
                                ))}
                            </select>
                        )}
                        <Link href={route('dashboards.index')} className="btn-secondary">
                            All dashboards
                        </Link>
                        {can.update && (
                            <Link href={route('dashboards.edit', dashboard.slug)} className="btn-primary">
                                Edit layout
                            </Link>
                        )}
                        {!can.update && can.duplicate && (
                            <Link
                                href={route('dashboards.duplicate', dashboard.slug)}
                                method="post"
                                as="button"
                                className="btn-primary"
                            >
                                Duplicate to edit
                            </Link>
                        )}
                    </>
                }
            />

            {dashboard.is_system && can.manage && (
                <p className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
                    This is a shipped dashboard and is read-only. Duplicate it to make a version you can edit.
                </p>
            )}

            {widgets.length === 0 ? (
                <div className="card">
                    <div className="card-body text-center">
                        <p className="text-sm text-[var(--color-text-secondary)]">
                            This dashboard has no widgets yet.
                        </p>
                        {can.update && (
                            <Link href={route('dashboards.edit', dashboard.slug)} className="btn-primary mt-4">
                                Add the first widget
                            </Link>
                        )}
                    </div>
                </div>
            ) : (
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
                    {widgets.map((widget, index) => (
                        <div
                            key={widget.id}
                            className="dash-tile"
                            style={{ '--tile-span': widget.position.w }}
                        >
                            <Deferred eager={index < 4 || lowBandwidth}>
                                <Suspense fallback={<Skeleton title={widget.title} />}>
                                    <Widget widget={widget} />
                                </Suspense>
                            </Deferred>
                        </div>
                    ))}
                </div>
            )}
        </AuthenticatedLayout>
    );
}

/**
 * Tiles below the fold render when they scroll into view. In low-bandwidth
 * mode everything renders eagerly instead — the observer costs frames, and
 * the point of that mode is to spend fewer of them.
 */
function Deferred({ eager, children }) {
    const [visible, setVisible] = useState(Boolean(eager));
    const ref = useRef(null);

    useEffect(() => {
        if (visible || !ref.current || typeof IntersectionObserver === 'undefined') {
            setVisible(true);

            return undefined;
        }

        const observer = new IntersectionObserver(
            ([entry]) => entry.isIntersecting && setVisible(true),
            { rootMargin: '200px' },
        );

        observer.observe(ref.current);

        return () => observer.disconnect();
    }, [visible]);

    return <div ref={ref}>{visible ? children : <Skeleton />}</div>;
}

function Skeleton({ title }) {
    return (
        <div className="card h-full">
            <div className="card-body">
                <p className="text-sm font-semibold text-[var(--color-text-primary)]">{title ?? ' '}</p>
                <div className="mt-3 h-24 rounded bg-gray-50" aria-hidden="true" />
            </div>
        </div>
    );
}
