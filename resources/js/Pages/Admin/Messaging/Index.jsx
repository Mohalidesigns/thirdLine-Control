import DataTable from '@/Components/DataTable';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDateTime, formatMoney } from '@/utils';
import { Head, router } from '@inertiajs/react';

/**
 * Omnichannel messaging admin (15.3/15.4): template catalogue with Meta
 * approval states, channel configuration status, delivery log and
 * per-channel cost tracking.
 */
const APPROVAL_BADGE = {
    draft: 'Draft',
    pending: 'Pending Approval',
    approved: 'Approved',
    rejected: 'Rejected',
};

export default function Index({ whatsappTemplates, smsTemplates, logs, costs, channels, filters = {} }) {
    const logColumns = [
        { field: 'created_at', label: 'When', render: (r) => formatDateTime(r.created_at) },
        { field: 'channel', label: 'Channel', render: (r) => <span className="uppercase">{r.channel}</span> },
        { field: 'direction', label: 'Dir', render: (r) => (r.direction === 'in' ? '←' : '→') },
        { field: 'template_key', label: 'Template', render: (r) => r.template_key ?? '—' },
        {
            field: 'recipient_masked',
            label: 'Recipient',
            render: (r) => (r.was_anonymised ? <em className="text-gray-400">anonymised</em> : (r.recipient_masked ?? r.user?.name ?? '—')),
        },
        { field: 'status', label: 'Status', render: (r) => <StatusBadge status={r.status} /> },
        {
            field: 'cost_minor',
            label: 'Cost',
            render: (r) => (r.cost_minor > 0 ? formatMoney(r.cost_minor, r.cost_currency) : '—'),
        },
        {
            field: 'error',
            label: 'Detail',
            render: (r) => <span className="block max-w-xs truncate text-xs text-gray-500" title={r.error}>{r.error ?? r.window ?? '—'}</span>,
        },
    ];

    return (
        <AuthenticatedLayout header="Messaging">
            <Head title="Messaging" />
            <PageHeader
                title="Omnichannel messaging"
                subtitle="WhatsApp, SMS, push and USSD — templates, delivery log and cost tracking. Messages carry notifications and links only, never case content."
                actions={
                    <PrimaryButton onClick={() => router.post(route('admin.messaging.sync-templates'))}>
                        Sync template statuses from Meta
                    </PrimaryButton>
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                {Object.entries(channels).map(([channel, configured]) => (
                    <StatCard
                        key={channel}
                        title={channel.toUpperCase()}
                        value={configured ? 'Configured' : 'Dormant'}
                        color={configured ? 'success' : 'primary'}
                        subtitle={configured ? null : 'Set credentials in .env to activate'}
                    />
                ))}
            </div>

            <div className="card mb-6">
                <h2 className="mb-2 text-sm font-semibold text-gray-700">WhatsApp templates (Meta approval required before sending)</h2>
                <DataTable
                    columns={[
                        { field: 'key', label: 'Key', render: (t) => <span className="font-mono text-xs">{t.key}</span> },
                        { field: 'body', label: 'Body', render: (t) => <span className="block max-w-md truncate text-xs" title={t.body}>{t.body}</span> },
                        { field: 'language', label: 'Lang' },
                        { field: 'category', label: 'Category' },
                        { field: 'approval_status', label: 'Meta status', render: (t) => <StatusBadge status={APPROVAL_BADGE[t.approval_status] ?? t.approval_status} /> },
                        { field: 'synced_at', label: 'Synced', render: (t) => (t.synced_at ? formatDateTime(t.synced_at) : 'never') },
                    ]}
                    data={whatsappTemplates}
                    emptyMessage="No WhatsApp templates seeded."
                />
            </div>

            <div className="card mb-6">
                <h2 className="mb-2 text-sm font-semibold text-gray-700">SMS templates</h2>
                <DataTable
                    columns={[
                        { field: 'key', label: 'Key', render: (t) => <span className="font-mono text-xs">{t.key}</span> },
                        { field: 'name', label: 'Name' },
                        { field: 'body', label: 'Body', render: (t) => <span className="block max-w-md truncate text-xs" title={t.body}>{t.body}</span> },
                        { field: 'is_active', label: 'Active', render: (t) => (t.is_active ? 'Yes' : 'No') },
                    ]}
                    data={smsTemplates}
                    emptyMessage="No SMS templates seeded."
                />
            </div>

            {costs.length > 0 && (
                <div className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {costs.map((row) => (
                        <StatCard
                            key={`${row.channel}-${row.cost_currency}`}
                            title={`${row.channel.toUpperCase()} spend`}
                            value={formatMoney(row.total_minor, row.cost_currency)}
                            subtitle={`${row.messages} message(s)`}
                        />
                    ))}
                </div>
            )}

            <div className="card">
                <div className="mb-2 flex items-center justify-between">
                    <h2 className="text-sm font-semibold text-gray-700">Delivery log</h2>
                    <select
                        className="filter-bar-select"
                        value={filters.channel ?? ''}
                        onChange={(e) =>
                            router.get(route('admin.messaging'), e.target.value ? { channel: e.target.value } : {}, { preserveState: true })
                        }
                    >
                        <option value="">All channels</option>
                        {['whatsapp', 'sms', 'push', 'ussd'].map((channel) => (
                            <option key={channel} value={channel}>{channel}</option>
                        ))}
                    </select>
                </div>
                <DataTable columns={logColumns} data={logs.data} emptyMessage="No messages logged yet." />
                <Pagination paginator={logs} />
            </div>
        </AuthenticatedLayout>
    );
}
