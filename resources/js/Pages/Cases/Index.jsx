import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import RichTextEditor from '@/Components/RichTextEditor';
import SeverityBadge from '@/Components/SeverityBadge';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AlertCircle, Clock, EyeOff, Gavel } from 'lucide-react';

export default function Index({
    cases,
    filters = {},
    statuses = [],
    types = [],
    severities = [],
    confidentialityLevels = [],
    entities = [],
    investigators = [],
    stats = {},
    summary = {},
}) {
    const { auth } = usePage().props;
    const canCreate = auth?.permissions?.includes('create cases');
    const canReport = auth?.permissions?.includes('report cases');
    const [opening, setOpening] = useState(false);

    const form = useForm({
        case_type: 'investigation',
        title: '',
        description: '',
        confidentiality: 'Restricted',
        severity: 'Medium',
        is_anonymous: false,
        entity_id: '',
        lead_investigator_id: '',
        access_user_ids: [],
    
        description_rich: null,
});

    const submit = (e) => {
        e.preventDefault();
        form.post(route('cases.store'), { onSuccess: () => setOpening(false) });
    };

    const columns = [
        {
            field: 'case_ref',
            label: 'Ref',
            render: (row) => (
                <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{row.case_ref}</span>
            ),
        },
        {
            field: 'title',
            label: 'Case',
            render: (row) => (
                <div className="max-w-sm">
                    <p className="font-medium text-[var(--color-text-primary)]">{row.title}</p>
                    <p className="text-xs capitalize text-gray-400">
                        {row.case_type?.replace(/_/g, ' ')}
                        {row.is_anonymous ? ' · anonymous' : ''}
                        {row.was_anonymised ? ' · anonymised at intake' : ''}
                    </p>
                </div>
            ),
        },
        {
            field: 'confidentiality',
            label: 'Confidentiality',
            render: (row) => <span className="badge badge-status-draft">{row.confidentiality}</span>,
        },
        { field: 'severity', label: 'Severity', render: (row) => <SeverityBadge severity={row.severity} /> },
        { field: 'leadInvestigator', label: 'Lead', render: (row) => row.lead_investigator?.name ?? '—' },
        {
            field: 'received_at',
            label: 'Received',
            render: (row) => <span className="text-xs">{formatDate(row.received_at)}</span>,
        },
        {
            // Spec §5.4 — how long the concern has been sitting.
            field: 'age',
            label: 'Age',
            width: '6rem',
            render: (row) => {
                const days = Math.max(0, Math.floor((Date.now() - new Date(row.received_at).getTime()) / 86400000));

                return <span className="tabular-nums text-xs">{days}d</span>;
            },
        },
        {
            // Spec §5.4 — whether a case exists, never what is in it. An
            // officer who may see this submission is not thereby on the
            // investigation, so this is a marker and not a link.
            field: 'investigation_count',
            label: 'Investigation',
            width: '8rem',
            render: (row) =>
                row.investigation_count > 0 ? (
                    <span className="inline-flex items-center gap-1 text-xs font-medium text-[var(--color-primary)]">
                        <Gavel className="h-3.5 w-3.5" aria-hidden="true" />
                        Raised
                    </span>
                ) : (
                    <span className="text-xs text-gray-400">—</span>
                ),
        },
        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
    ];

    return (
        <AuthenticatedLayout header="Cases">
            <Head title="Cases" />

            <PageHeader
                title="Cases & Investigations"
                subtitle="Whistleblowing, fraud, disciplinary and regulatory enquiries — visible only to the people named on each case"
                actions={
                    <>
                        {canReport && (
                            <Link href={route('cases.board-extract')} className="btn-secondary">
                                Board extract
                            </Link>
                        )}
                        {canCreate && (
                            <button className="btn-primary" onClick={() => setOpening(true)}>
                                Open a case
                            </button>
                        )}
                    </>
                }
            />

            <div className="mb-6 rounded-lg border-l-4 border-l-[var(--color-accent)] bg-amber-50 p-4 text-sm">
                <p className="font-semibold text-[var(--color-text-primary)]">Allowlist access</p>
                <p className="mt-1 text-[var(--color-text-secondary)]">
                    This list shows only the cases you are explicitly named on. The single exception is the
                    System Administrator&apos;s read-only oversight sight — acting on a case still requires being
                    named on it, and every time a case file is opened it is logged.
                </p>
            </div>

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard icon={AlertCircle} title="Open" value={stats.open ?? 0} color="primary" />
                <StatCard icon={Clock} title="Under investigation" value={stats.under_investigation ?? 0} color="warning" />
                <StatCard icon={EyeOff} title="Anonymous reports" value={stats.anonymous ?? 0} color="info" />
            </div>

            {/* Spec §5.4 — the Speak Up summary. Four numbers and two
                breakdowns, kept deliberately small: a whistleblowing
                register that needs a wall of charts is not being read. */}
            <div className="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="card lg:col-span-1">
                    <div className="card-header"><h3 className="text-sm font-semibold">Handling</h3></div>
                    <div className="card-body grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <p className="text-xs uppercase tracking-wide text-gray-400">Awaiting screening</p>
                            <p className="mt-1 text-xl font-semibold tabular-nums">{summary.awaiting_screening ?? 0}</p>
                        </div>
                        <div>
                            <p className="text-xs uppercase tracking-wide text-gray-400">Avg days to screen</p>
                            <p className="mt-1 text-xl font-semibold tabular-nums">
                                {summary.avg_days_to_screen ?? '—'}
                            </p>
                        </div>
                        <div>
                            <p className="text-xs uppercase tracking-wide text-gray-400">Substantiated</p>
                            <p className="mt-1 text-xl font-semibold tabular-nums">{summary.substantiated ?? 0}</p>
                        </div>
                        <div>
                            <p className="text-xs uppercase tracking-wide text-gray-400">Total</p>
                            <p className="mt-1 text-xl font-semibold tabular-nums">{summary.total ?? 0}</p>
                        </div>
                    </div>
                </div>
                <Breakdown title="By status" data={summary.by_status} />
                <Breakdown title="By category" data={summary.by_category} />
            </div>

            <FilterBar
                route="cases.index"
                resource="cases"
                currentFilters={filters}
                searchPlaceholder="Search cases…"
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    { name: 'status', type: 'select', label: 'Status', options: statuses },
                    { name: 'case_type', type: 'select', label: 'Type', options: types },
                    { name: 'severity', type: 'select', label: 'Severity', options: severities },
                    { name: 'open_only', type: 'checkbox', label: 'Open only' },
                ]}
            />

            <div className="card">
                <DataTable
                    columns={columns}
                    data={cases.data}
                    emptyMessage="You are not named on any case matching these filters."
                    onRowClick={(row) => router.visit(route('cases.show', row.id))}
                />
                <Pagination paginator={cases} />
            </div>

            <Modal show={opening} onClose={() => setOpening(false)} maxWidth="lg">
                <form onSubmit={submit} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold">Open a case</h2>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="form-label">Type</label>
                            <select
                                className="form-select"
                                value={form.data.case_type}
                                onChange={(e) => form.setData('case_type', e.target.value)}
                            >
                                {types.map((type) => (
                                    <option key={type} value={type}>
                                        {type.replace(/_/g, ' ')}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="form-label">Severity</label>
                            <select
                                className="form-select"
                                value={form.data.severity}
                                onChange={(e) => form.setData('severity', e.target.value)}
                            >
                                {severities.map((severity) => (
                                    <option key={severity} value={severity}>
                                        {severity}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="form-label">Confidentiality</label>
                            <select
                                className="form-select"
                                value={form.data.confidentiality}
                                onChange={(e) => form.setData('confidentiality', e.target.value)}
                            >
                                {confidentialityLevels.map((level) => (
                                    <option key={level} value={level}>
                                        {level}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="form-label">Lead investigator</label>
                            <select
                                className="form-select"
                                value={form.data.lead_investigator_id}
                                onChange={(e) => form.setData('lead_investigator_id', e.target.value)}
                            >
                                <option value="">—</option>
                                {investigators.map((user) => (
                                    <option key={user.id} value={user.id}>
                                        {user.name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="col-span-2">
                            <label className="form-label">Title</label>
                            <input
                                className="form-input"
                                value={form.data.title}
                                onChange={(e) => form.setData('title', e.target.value)}
                            />
                        </div>

                        <div className="col-span-2">
                            <label className="form-label">Description</label>
                            <RichTextEditor
                                value={form.data.description_rich ?? form.data.description}
                                onChange={(doc, plain) => form.setData((d) => ({ ...d, description: plain, description_rich: doc }))}
                                tools="minimal"
                                minHeight={104}
                            />
                        </div>

                        <div className="col-span-2">
                            <label className="form-label">Entity</label>
                            <select
                                className="form-select"
                                value={form.data.entity_id}
                                onChange={(e) => form.setData('entity_id', e.target.value)}
                            >
                                <option value="">—</option>
                                {entities.map((entity) => (
                                    <option key={entity.id} value={entity.id}>
                                        {entity.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div>
                        <label className="form-label">Who may see this case</label>
                        <div className="max-h-40 space-y-1 overflow-y-auto rounded-lg border border-gray-200 p-3">
                            {investigators.map((user) => (
                                <label key={user.id} className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={form.data.access_user_ids.includes(user.id)}
                                        onChange={() =>
                                            form.setData(
                                                'access_user_ids',
                                                form.data.access_user_ids.includes(user.id)
                                                    ? form.data.access_user_ids.filter((id) => id !== user.id)
                                                    : [...form.data.access_user_ids, user.id],
                                            )
                                        }
                                    />
                                    {user.name}
                                </label>
                            ))}
                        </div>
                        <p className="mt-1 text-xs text-gray-400">
                            Nobody outside this list can open the case, whatever their role.
                        </p>
                    </div>

                    <label className="flex items-start gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="mt-0.5"
                            checked={form.data.is_anonymous}
                            onChange={(e) => form.setData('is_anonymous', e.target.checked)}
                        />
                        <span>
                            Anonymous report
                            <span className="block text-xs text-gray-400">
                                No reporter identity is stored at all, and the case can never be de-anonymised.
                            </span>
                        </span>
                    </label>

                    <div className="flex justify-end gap-2">
                        <button type="button" className="btn-secondary" onClick={() => setOpening(false)}>
                            Cancel
                        </button>
                        <button type="submit" className="btn-primary" disabled={form.processing}>
                            Open case
                        </button>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}

/**
 * One labelled bar list. Percentages are of the viewer's own caseload, not
 * the register's — the allowlist scope decides what is counted.
 */
function Breakdown({ title, data }) {
    const entries = Object.entries(data ?? {});
    const total = entries.reduce((sum, [, n]) => sum + n, 0);

    return (
        <div className="card">
            <div className="card-header"><h3 className="text-sm font-semibold">{title}</h3></div>
            <div className="card-body space-y-2">
                {entries.length === 0 && <p className="text-sm text-[var(--color-text-secondary)]">Nothing to show.</p>}
                {entries.map(([label, count]) => (
                    <div key={label}>
                        <div className="flex items-center justify-between text-xs">
                            <span className="capitalize">{String(label).replace(/_/g, ' ')}</span>
                            <span className="tabular-nums text-[var(--color-text-secondary)]">{count}</span>
                        </div>
                        <div className="mt-1 h-1.5 rounded-full bg-gray-100">
                            <div
                                className="h-1.5 rounded-full bg-[var(--color-primary)]"
                                style={{ width: total ? `${Math.round((count / total) * 100)}%` : '0%' }}
                            />
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
