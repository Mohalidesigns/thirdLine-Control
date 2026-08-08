import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

/**
 * The exportable AI activity log (§C.8).
 *
 * Every call appears here, including the ones that never reached the model —
 * a budget stop, a rate limit and a schema rejection are separate rows with
 * their own statuses, because "the model was never asked" and "the model was
 * asked and we ignored it" are different facts a governance review needs to
 * tell apart (R3).
 */

const STATUS_TONE = {
    Completed: 'bg-emerald-50 text-emerald-800',
    Failed: 'bg-red-50 text-red-800',
    'Rejected by Schema': 'bg-amber-50 text-amber-800',
    'Budget Exceeded': 'bg-orange-50 text-orange-800',
    'Rate Limited': 'bg-orange-50 text-orange-800',
    Unavailable: 'bg-gray-100 text-gray-700',
    Pending: 'bg-blue-50 text-blue-800',
};

const DECISION_TONE = {
    Accepted: 'bg-emerald-50 text-emerald-800',
    Edited: 'bg-indigo-50 text-indigo-800',
    Rejected: 'bg-red-50 text-red-800',
    Pending: 'bg-gray-100 text-gray-600',
};

function Chip({ label, tone }) {
    return (
        <span className={`inline-block whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold ${tone}`}>
            {label}
        </span>
    );
}

export default function AiLog({ interactions, filters, options, can }) {
    return (
        <AuthenticatedLayout header="AI Activity Log">
            <Head title="AI Activity Log" />

            <PageHeader
                title="AI activity log"
                subtitle="Every call, including the ones that never reached the model. Append-only."
                breadcrumbs={[{ label: 'AI Governance', href: route('admin.ai.index') }, { label: 'Activity log' }]}
                actions={
                    can.export && (
                        <a
                            href={route('admin.ai.log.export', filters)}
                            className="btn-secondary"
                            title="Exporting is itself recorded in the audit trail."
                        >
                            Export CSV
                        </a>
                    )
                }
            />

            <FilterBar
                route="admin.ai.log"
                resource="ai-log"
                currentFilters={filters}
                filters={[
                    {
                        type: 'select',
                        name: 'capability',
                        label: 'Capability',
                        options: options.capabilities,
                    },
                    {
                        type: 'select',
                        name: 'status',
                        label: 'Status',
                        options: options.statuses.map((s) => ({ value: s, label: s })),
                    },
                    {
                        type: 'select',
                        name: 'decision',
                        label: 'Human decision',
                        options: options.decisions.map((d) => ({ value: d, label: d })),
                    },
                ]}
            />

            <div className="card mt-4">
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>User</th>
                                <th>Capability</th>
                                <th>Model</th>
                                <th>Status</th>
                                <th>Decision</th>
                                <th className="text-right">Grounding</th>
                                <th className="text-right">Tokens</th>
                                <th className="text-right">Latency</th>
                            </tr>
                        </thead>
                        <tbody>
                            {interactions.data.length === 0 ? (
                                <tr>
                                    <td colSpan={9} className="py-10 text-center text-sm text-gray-400">
                                        No AI activity matches these filters.
                                    </td>
                                </tr>
                            ) : (
                                interactions.data.map((row) => (
                                    <tr key={row.id}>
                                        <td className="whitespace-nowrap font-mono text-[11px] text-gray-500">
                                            {new Date(row.created_at).toLocaleString('en-GB')}
                                        </td>
                                        <td className="whitespace-nowrap">{row.user}</td>
                                        <td>
                                            <span className="text-sm">{row.capability_label ?? row.capability}</span>
                                            {row.subject && (
                                                <p className="font-mono text-[11px] text-gray-400">{row.subject}</p>
                                            )}
                                        </td>
                                        <td className="whitespace-nowrap font-mono text-[11px]">
                                            {row.model ?? '—'}
                                            {row.prompt_version && (
                                                <span className="text-gray-400"> · v{row.prompt_version}</span>
                                            )}
                                        </td>
                                        <td>
                                            <Chip
                                                label={row.status}
                                                tone={STATUS_TONE[row.status] ?? 'bg-gray-100 text-gray-700'}
                                            />
                                            {row.error && (
                                                <p className="mt-1 max-w-xs text-[11px] text-gray-400">{row.error}</p>
                                            )}
                                        </td>
                                        <td>
                                            <Chip
                                                label={row.decision}
                                                tone={DECISION_TONE[row.decision] ?? 'bg-gray-100 text-gray-600'}
                                            />
                                            {row.decided_by && (
                                                <p className="mt-0.5 text-[11px] text-gray-400">{row.decided_by}</p>
                                            )}
                                            {row.rejection_reason && (
                                                <p className="mt-0.5 max-w-xs text-[11px] text-gray-500">
                                                    {row.rejection_reason}
                                                </p>
                                            )}
                                        </td>
                                        <td className="whitespace-nowrap text-right text-[11px] text-gray-500">
                                            {row.citations}/{row.context_records} cited
                                            {row.confidence != null && (
                                                <p>{Math.round(row.confidence * 100)}% confidence</p>
                                            )}
                                        </td>
                                        <td className="whitespace-nowrap text-right text-[11px]">
                                            {row.tokens.toLocaleString()}
                                        </td>
                                        <td className="whitespace-nowrap text-right text-[11px] text-gray-500">
                                            {row.latency_ms ? `${row.latency_ms}ms` : '—'}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination paginator={interactions} />
            </div>

            <p className="mt-4 text-xs text-[var(--color-text-secondary)]">
                Inputs are stored post-redaction only, and retrieved context is recorded as record identifiers
                rather than content. Verbatim model output ages out on its retention window; the interaction
                record itself never does.{' '}
                <Link href={route('admin.ai.index')} className="text-[var(--color-primary)] hover:underline">
                    Back to AI governance
                </Link>
            </p>
        </AuthenticatedLayout>
    );
}
