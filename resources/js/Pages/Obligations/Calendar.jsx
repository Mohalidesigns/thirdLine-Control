import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import SelectInput from '@/Components/SelectInput';
import StatusBadge from '@/Components/StatusBadge';
import VerificationBadge from '@/Components/VerificationBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { daysUntil, formatDate, formatMoney } from '@/utils';
import { Head, Link, router } from '@inertiajs/react';

/**
 * Filing-density strip: one cell per day in the window, shaded by how many
 * deadlines land on it, so a compliance officer sees crunch periods before
 * they arrive.
 */
function DensityStrip({ from, to, density }) {
    const start = new Date(from);
    const end = new Date(to);
    const days = [];

    for (let cursor = new Date(start); cursor <= end; cursor.setDate(cursor.getDate() + 1)) {
        const key = cursor.toISOString().slice(0, 10);
        days.push({ key, count: density?.[key] ?? 0 });
    }

    const peak = Math.max(1, ...days.map((day) => day.count));

    return (
        <div className="flex gap-px overflow-x-auto">
            {days.map((day) => (
                <span
                    key={day.key}
                    title={`${day.key}: ${day.count} filing(s)`}
                    className="h-8 w-1.5 shrink-0 rounded-sm"
                    style={{
                        backgroundColor:
                            day.count === 0 ? 'var(--color-border, #e5e7eb)' : 'var(--color-primary)',
                        opacity: day.count === 0 ? 0.35 : 0.35 + (day.count / peak) * 0.65,
                    }}
                />
            ))}
        </div>
    );
}

function DueChip({ dueAt, status }) {
    const remaining = daysUntil(dueAt);

    if (status === 'Accepted' || status === 'Waived') {
        return <span className="text-xs text-gray-400">closed</span>;
    }

    if (remaining === null) return null;

    const tone =
        remaining < 0 ? 'badge-critical' : remaining <= 7 ? 'badge-high' : remaining <= 30 ? 'badge-medium' : 'badge-low';

    return (
        <span className={`badge ${tone}`}>
            {remaining < 0 ? `${Math.abs(remaining)}d overdue` : remaining === 0 ? 'due today' : `in ${remaining}d`}
        </span>
    );
}

export default function Calendar({
    view = 'quarter',
    window: period = {},
    instances = [],
    density = {},
    filters = {},
    regulators = [],
    entities = [],
    owners = [],
    statuses = [],
    exposure = {},
}) {
    const setFilter = (name, value) =>
        router.get(route('obligations.calendar'), { ...filters, view, [name]: value || undefined }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });

    const shift = (direction) => {
        const anchor = new Date(period.anchor);
        const step = view === 'month' ? 1 : view === 'quarter' ? 3 : 12;
        anchor.setMonth(anchor.getMonth() + direction * step);

        router.get(route('obligations.calendar'), {
            ...filters,
            view,
            date: anchor.toISOString().slice(0, 10),
        });
    };

    const byMonth = instances.reduce((groups, instance) => {
        const key = new Date(instance.due_at).toLocaleDateString('en-NG', { month: 'long', year: 'numeric' });
        (groups[key] ??= []).push(instance);
        return groups;
    }, {});

    const exposureEntries = Object.entries(exposure);

    return (
        <AuthenticatedLayout header="Compliance Calendar">
            <Head title="Compliance Calendar" />

            <PageHeader
                title="Compliance Calendar"
                subtitle={`${formatDate(period.from)} — ${formatDate(period.to)}`}
                actions={
                    <>
                        <button type="button" onClick={() => shift(-1)} className="btn-secondary" aria-label="Previous period">
                            ←
                        </button>
                        <SelectInput
                            value={view}
                            onChange={(e) => setFilter('view', e.target.value)}
                            className="w-32"
                            aria-label="Calendar view"
                        >
                            <option value="month">Month</option>
                            <option value="quarter">Quarter</option>
                            <option value="year">Year</option>
                        </SelectInput>
                        <button type="button" onClick={() => shift(1)} className="btn-secondary" aria-label="Next period">
                            →
                        </button>
                        <Link href={route('obligations.instances.index')} className="btn-secondary">
                            List view
                        </Link>
                    </>
                }
            />

            {exposureEntries.length > 0 && (
                <div className="mb-4 rounded-lg border-l-4 border-l-[var(--color-error)] bg-red-50 px-4 py-3 text-sm">
                    <span className="font-semibold">Live penalty exposure: </span>
                    {exposureEntries.map(([currency, money]) => (
                        <span key={currency} className="mr-4">
                            {formatMoney(money)}
                        </span>
                    ))}
                </div>
            )}

            <div className="mb-6 card">
                <div className="card-body">
                    <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                        Filing density
                    </p>
                    <DensityStrip from={period.from} to={period.to} density={density} />
                </div>
            </div>

            <div className="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <SelectInput
                    value={filters.regulator_id ?? ''}
                    onChange={(e) => setFilter('regulator_id', e.target.value)}
                    aria-label="Filter by regulator"
                >
                    <option value="">All regulators</option>
                    {regulators.map((regulator) => (
                        <option key={regulator.id} value={regulator.id}>
                            {regulator.name}
                        </option>
                    ))}
                </SelectInput>

                <SelectInput
                    value={filters.entity_id ?? ''}
                    onChange={(e) => setFilter('entity_id', e.target.value)}
                    aria-label="Filter by entity"
                >
                    <option value="">All entities</option>
                    {entities.map((entity) => (
                        <option key={entity.id} value={entity.id}>
                            {entity.name}
                        </option>
                    ))}
                </SelectInput>

                <SelectInput
                    value={filters.owner_id ?? ''}
                    onChange={(e) => setFilter('owner_id', e.target.value)}
                    aria-label="Filter by owner"
                >
                    <option value="">All owners</option>
                    {owners.map((owner) => (
                        <option key={owner.id} value={owner.id}>
                            {owner.name}
                        </option>
                    ))}
                </SelectInput>

                <SelectInput
                    value={filters.status ?? ''}
                    onChange={(e) => setFilter('status', e.target.value)}
                    aria-label="Filter by status"
                >
                    <option value="">All statuses</option>
                    {statuses.map((status) => (
                        <option key={status} value={status}>
                            {status}
                        </option>
                    ))}
                </SelectInput>
            </div>

            {instances.length === 0 ? (
                <div className="card">
                    <EmptyState
                        title="Nothing falls due in this period"
                        description="Assign obligations to an entity, or move to another period."
                    />
                </div>
            ) : (
                <div className="space-y-6">
                    {Object.entries(byMonth).map(([month, monthInstances]) => (
                        <div key={month} className="card">
                            <div className="card-header flex items-center justify-between">
                                <h2 className="text-sm font-semibold">{month}</h2>
                                <span className="text-xs text-gray-400">{monthInstances.length} filing(s)</span>
                            </div>
                            <ul className="divide-y divide-gray-100">
                                {monthInstances.map((instance) => (
                                    <li key={instance.id}>
                                        <Link
                                            href={route('obligations.instances.show', instance.id)}
                                            className="flex flex-col gap-2 px-4 py-3 hover:bg-gray-50 sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">
                                                        {instance.obligation?.obligation_ref}
                                                    </span>
                                                    <span className="text-sm font-medium">{instance.obligation?.title}</span>
                                                    {instance.obligation?.verification_status !== 'verified' && (
                                                        <VerificationBadge status={instance.obligation?.verification_status} />
                                                    )}
                                                </div>
                                                <p className="mt-0.5 text-xs text-gray-400">
                                                    {instance.obligation?.regulator?.name} · {instance.assignment?.entity?.name} ·{' '}
                                                    {instance.assignment?.owner?.name ?? 'Unassigned'} · {instance.period_label}
                                                </p>
                                            </div>
                                            <div className="flex shrink-0 flex-wrap items-center gap-2">
                                                <span className="text-xs text-gray-500">{formatDate(instance.due_at)}</span>
                                                <DueChip dueAt={instance.due_at} status={instance.status} />
                                                <StatusBadge status={instance.status} />
                                            </div>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
            )}
        </AuthenticatedLayout>
    );
}
