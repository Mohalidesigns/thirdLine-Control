import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import SeverityBadge from '@/Components/SeverityBadge';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, BarChart3, Clock, EyeOff, Gavel, Plus } from 'lucide-react';

const humanise = (value) => (value ? value.replace(/_/g, ' ') : '—');

/**
 * CR-04 — the investigation register.
 *
 * Every row here is a case the viewer may open: the model's visibility
 * scope filters the query, not this component, so there is no such thing
 * as a row that renders and then 403s.
 */
export default function Index({ investigations, filters = {}, options = {}, stats = {}, can = {} }) {
    const columns = [
        {
            field: 'reference',
            label: 'Ref',
            width: '9rem',
            render: (row) => (
                <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{row.reference}</span>
            ),
        },
        {
            field: 'title',
            label: 'Investigation',
            render: (row) => (
                <div className="max-w-sm">
                    <p className="font-medium text-[var(--color-text-primary)]">{row.title}</p>
                    <p className="text-xs capitalize text-gray-400">
                        {humanise(row.category)}
                        {row.is_confidential ? ' · confidential' : ''}
                        {row.is_archived ? ' · archived' : ''}
                    </p>
                </div>
            ),
        },
        { field: 'priority', label: 'Priority', width: '7rem', render: (row) => <SeverityBadge severity={row.priority} /> },
        {
            field: 'risk_rating',
            label: 'Risk',
            width: '7rem',
            render: (row) => (row.risk_rating ? <StatusBadge status={row.risk_rating} /> : <span className="text-gray-400" title="Rated at completion">Not rated</span>),
        },
        { field: 'lead', label: 'Lead', width: '11rem', render: (row) => row.lead_investigator?.name ?? '—' },
        { field: 'entity', label: 'Control entity', width: '12rem', render: (row) => row.control_entity?.name ?? '—' },
        {
            field: 'counts',
            label: 'Subjects / findings',
            width: '9rem',
            render: (row) => (
                <span className="tabular-nums text-xs text-[var(--color-text-secondary)]">
                    {row.subjects_count ?? 0} / {row.findings_count ?? 0}
                </span>
            ),
        },
        { field: 'reported_date', label: 'Reported', width: '8rem', render: (row) => <span className="text-xs">{formatDate(row.reported_date)}</span> },
        { field: 'status', label: 'Status', width: '10rem', render: (row) => <StatusBadge status={humanise(row.status)} /> },
    ];

    return (
        <AuthenticatedLayout header="Investigations">
            <Head title="Investigations" />

            <PageHeader
                title="Investigations"
                subtitle="Fraud, staff misconduct, asset misappropriation and conflicts of interest — the casework, its subjects and what was done about them"
                icon={Gavel}
                actions={
                    <>
                        {can.view_dashboard && (
                            <Link href={route('investigations.dashboard')} className="btn-secondary">
                                <BarChart3 className="me-1.5 h-4 w-4" aria-hidden="true" />
                                Dashboard
                            </Link>
                        )}
                        {can.create && (
                            <Link href={route('investigations.create')} className="btn-primary">
                                <Plus className="me-1.5 h-4 w-4" aria-hidden="true" />
                                New investigation
                            </Link>
                        )}
                    </>
                }
            />

            <div className="mb-6 rounded-lg border-l-4 border-l-[var(--color-accent)] bg-amber-50 p-4 text-sm">
                <p className="font-semibold text-[var(--color-text-primary)]">Who sees what</p>
                <p className="mt-1 text-[var(--color-text-secondary)]">
                    This list shows the investigations you are on, plus — if you hold the oversight permission — the
                    ordinary ones. A confidential investigation opens only to its lead, its team and the named
                    confidential authority, and every time a confidential file is opened it is logged on the case
                    timeline.
                </p>
            </div>

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-4">
                <StatCard icon={Gavel} title="Open" value={stats.open ?? 0} tone="blue" />
                <StatCard icon={Clock} title="Under investigation" value={stats.under_investigation ?? 0} tone="amber" />
                <StatCard icon={AlertTriangle} title="Past target date" value={stats.overdue ?? 0} tone="red" />
                <StatCard icon={EyeOff} title="Confidential" value={stats.confidential ?? 0} tone="violet" />
            </div>

            <FilterBar
                route="investigations.index"
                resource="investigations"
                currentFilters={filters}
                searchPlaceholder="Search by title or reference…"
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    { name: 'status', type: 'select', label: 'Status', options: options.statuses ?? [] },
                    { name: 'category', type: 'select', label: 'Category', options: options.categories ?? [] },
                    { name: 'priority', type: 'select', label: 'Priority', options: options.priorities ?? [] },
                    { name: 'risk_rating', type: 'select', label: 'Risk rating', options: options.riskRatings ?? [] },
                    { name: 'open_only', type: 'checkbox', label: 'Open only' },
                    { name: 'mine', type: 'checkbox', label: 'I lead' },
                    { name: 'include_archived', type: 'checkbox', label: 'Include archived' },
                ]}
            />

            <div className="card">
                <DataTable
                    columns={columns}
                    data={investigations?.data ?? []}
                    emptyMessage="No investigation you can see matches these filters."
                    onRowClick={(row) => router.visit(route('investigations.show', row.id))}
                />
                <Pagination paginator={investigations} />
            </div>
        </AuthenticatedLayout>
    );
}
