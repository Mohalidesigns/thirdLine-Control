import DataTable from '@/Components/DataTable';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDateTime } from '@/utils';
import { Head, router, useForm } from '@inertiajs/react';

export default function Integrations({ configs = [], syncLogs = [], newKey = null }) {
    const existing = configs.find((c) => c.target_system === 'ThirdLine');

    const form = useForm({
        target_system: 'ThirdLine',
        base_url: existing?.base_url ?? '',
        outbound_key: '',
        sync_direction: existing?.sync_direction ?? 'Push',
        master_system: existing?.master_system ?? 'SecondLine',
        is_active: existing?.is_active ?? false,
    });

    return (
        <AuthenticatedLayout header="Integrations">
            <Head title="Integrations" />

            <PageHeader
                title="ThirdLine & NexusRisk integration"
                subtitle="A control is defined once and consumed everywhere — configure the sync direction per tenant"
            />

            {newKey && (
                <div className="mb-6 rounded-xl border border-green-200 bg-green-50 p-4">
                    <p className="text-sm font-semibold text-green-800">Inbound API key (shown once — copy it now):</p>
                    <p className="mt-1 select-all font-mono text-sm text-green-900">{newKey}</p>
                    <p className="mt-1 text-xs text-green-700">
                        ThirdLine authenticates with this key via the <span className="font-mono">X-Api-Key</span> header on /api/v1.
                    </p>
                </div>
            )}

            <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(route('admin.integrations.store'), { preserveScroll: true });
                    }}
                    className="card"
                >
                    <div className="card-header"><h3 className="text-sm font-semibold">Configuration</h3></div>
                    <div className="card-body space-y-4">
                        <div>
                            <InputLabel value="Target system" required />
                            <SelectInput value={form.data.target_system} onChange={(e) => form.setData('target_system', e.target.value)}>
                                <option value="ThirdLine">ThirdLine — Internal Audit</option>
                                <option value="NexusRisk">NexusRisk IRM</option>
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Base URL" />
                            <TextInput value={form.data.base_url} onChange={(e) => form.setData('base_url', e.target.value)} placeholder="https://thirdline.client.example" />
                        </div>
                        <div>
                            <InputLabel value="Outbound API key (for calls to the target)" />
                            <TextInput type="password" value={form.data.outbound_key} onChange={(e) => form.setData('outbound_key', e.target.value)} placeholder="Stored encrypted; leave blank to keep current" />
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <InputLabel value="Sync direction" required />
                                <SelectInput value={form.data.sync_direction} onChange={(e) => form.setData('sync_direction', e.target.value)}>
                                    <option value="Push">Push — SecondLine as master</option>
                                    <option value="Pull">Pull — ThirdLine as master</option>
                                    <option value="Bidirectional">Bidirectional</option>
                                </SelectInput>
                            </div>
                            <div>
                                <InputLabel value="Conflict master" required />
                                <SelectInput value={form.data.master_system} onChange={(e) => form.setData('master_system', e.target.value)}>
                                    <option value="SecondLine">SecondLine</option>
                                    <option value="ThirdLine">ThirdLine</option>
                                    <option value="PerCategory">Per control category</option>
                                </SelectInput>
                            </div>
                        </div>
                        <label className="flex items-center gap-2 text-sm text-gray-600">
                            <input
                                type="checkbox"
                                className="rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                checked={form.data.is_active}
                                onChange={(e) => form.setData('is_active', e.target.checked)}
                            />
                            Integration active
                        </label>
                        <div className="flex justify-end">
                            <PrimaryButton disabled={form.processing}>Save & rotate inbound key</PrimaryButton>
                        </div>
                    </div>
                </form>

                <div className="card">
                    <div className="card-header"><h3 className="text-sm font-semibold">Contract</h3></div>
                    <div className="card-body space-y-2 text-sm text-gray-600">
                        <p className="font-mono text-xs">GET  /api/v1/controls?since=…</p>
                        <p className="font-mono text-xs">POST /api/v1/controls</p>
                        <p className="font-mono text-xs">GET  /api/v1/test-results?since=…</p>
                        <p className="font-mono text-xs">GET  /api/v1/exceptions?status=open</p>
                        <p className="pt-2">
                            Every payload carries <span className="font-mono text-xs">tenant_id</span>,{' '}
                            <span className="font-mono text-xs">external_ref</span>,{' '}
                            <span className="font-mono text-xs">source_system</span>,{' '}
                            <span className="font-mono text-xs">version_no</span> and{' '}
                            <span className="font-mono text-xs">last_approved_at</span>. Writes require an{' '}
                            <span className="font-mono text-xs">Idempotency-Key</span> header. The full OpenAPI 3.1
                            specification is at <span className="font-mono text-xs">docs/openapi.yaml</span>.
                        </p>
                    </div>
                </div>
            </div>

            <div className="card">
                <div className="card-header"><h3 className="text-sm font-semibold">Sync log</h3></div>
                <DataTable
                    columns={[
                        { field: 'created_at', label: 'When', render: (row) => formatDateTime(row.created_at) },
                        { field: 'direction', label: 'Direction' },
                        { field: 'entity_type', label: 'Entity' },
                        { field: 'external_ref', label: 'External ref', render: (row) => <span className="font-mono text-xs">{row.external_ref ?? '—'}</span> },
                        {
                            field: 'status',
                            label: 'Status',
                            render: (row) => (
                                <span className={`badge ${row.status === 'Success' ? 'badge-status-active' : row.status === 'Failed' ? 'badge-status-overdue' : 'badge-status-pending'}`}>
                                    {row.status}
                                </span>
                            ),
                        },
                        { field: 'error_message', label: 'Detail', render: (row) => <span className="text-xs text-gray-500">{row.error_message ?? '—'}</span> },
                        {
                            field: 'actions',
                            label: '',
                            render: (row) =>
                                row.status === 'Failed' && row.direction === 'outbound' ? (
                                    <button
                                        type="button"
                                        className="text-xs text-[var(--color-primary)] hover:underline"
                                        onClick={() => router.post(route('admin.integrations.replay', row.id), {}, { preserveScroll: true })}
                                    >
                                        Replay
                                    </button>
                                ) : null,
                        },
                    ]}
                    data={syncLogs}
                    emptyMessage="No synchronisation events yet — they appear when controls, test results, or exceptions publish."
                />
            </div>
        </AuthenticatedLayout>
    );
}
