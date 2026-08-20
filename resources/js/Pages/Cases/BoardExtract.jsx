import PageHeader from '@/Components/PageHeader';
import StatCard from '@/Components/StatCard';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { AlertCircle, EyeOff, Gauge, Timer, TrendingUp } from 'lucide-react';

function Breakdown({ title, data = {} }) {
    const total = Object.values(data).reduce((sum, n) => sum + n, 0);

    return (
        <div className="card">
            <div className="card-header">{title}</div>
            <div className="card-body space-y-2">
                {Object.entries(data).length === 0 && <p className="text-sm text-gray-400">Nothing in this period.</p>}
                {Object.entries(data).map(([label, count]) => (
                    <div key={label}>
                        <div className="flex items-center justify-between text-sm">
                            <span className="capitalize">{label.replace(/_/g, ' ')}</span>
                            <span className="tabular-nums font-semibold">{count}</span>
                        </div>
                        <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100">
                            <div
                                className="h-full rounded-full bg-[var(--color-primary)]"
                                style={{ width: `${total > 0 ? (count / total) * 100 : 0}%` }}
                            />
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

export default function BoardExtract({ extract = {}, filters = {} }) {
    return (
        <AuthenticatedLayout header="Case board extract">
            <Head title="Case board extract" />

            <PageHeader
                title="Case board extract"
                subtitle="Counts and outcomes only — no titles, no subjects, no notes"
                breadcrumbs={[{ label: 'Cases', href: route('cases.index') }, { label: 'Board extract' }]}
                actions={
                    <Link href={route('cases.index')} className="btn-secondary">
                        Case list
                    </Link>
                }
            />

            <div className="card mb-6">
                <div className="card-body grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label className="form-label">From</label>
                        <input
                            type="date"
                            className="form-input"
                            defaultValue={filters.from ?? ''}
                            onChange={(e) =>
                                router.get(route('cases.board-extract'), { ...filters, from: e.target.value }, { preserveState: true })
                            }
                        />
                    </div>
                    <div>
                        <label className="form-label">To</label>
                        <input
                            type="date"
                            className="form-input"
                            defaultValue={filters.to ?? ''}
                            onChange={(e) =>
                                router.get(route('cases.board-extract'), { ...filters, to: e.target.value }, { preserveState: true })
                            }
                        />
                    </div>
                </div>
            </div>

            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-5">
                <StatCard icon={Gauge} title="Cases" value={extract.total ?? 0} color="primary" />
                <StatCard icon={AlertCircle} title="Open" value={extract.open ?? 0} color="warning" />
                <StatCard icon={EyeOff} title="Anonymous" value={extract.anonymous ?? 0} color="info" />
                <StatCard icon={TrendingUp}
                    title="Substantiation rate"
                    value={extract.substantiation_rate === null || extract.substantiation_rate === undefined ? '—' : `${extract.substantiation_rate}%`}
                    color="accent"
                />
                <StatCard icon={Timer}
                    title="Median days to close"
                    value={extract.median_days_to_close ?? '—'}
                    color="success"
                />
            </div>

            <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
                <Breakdown title="By type" data={extract.by_type ?? {}} />
                <Breakdown title="By status" data={extract.by_status ?? {}} />
                <Breakdown title="By severity" data={extract.by_severity ?? {}} />
            </div>

            <p className="mt-6 text-xs text-gray-400">
                A board paper that names a whistleblower is a breach, not a report — this extract carries no case
                identifiers of any kind.
            </p>
        </AuthenticatedLayout>
    );
}
