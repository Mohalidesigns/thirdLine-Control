import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import StatCard from '@/Components/StatCard';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDateTime } from '@/utils';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const HEALTH_CLASSES = {
    Healthy: 'badge-low',
    Degraded: 'badge-medium',
    Failed: 'badge-critical',
    Unknown: 'badge-status-draft',
};

export default function Index({
    sources,
    filters = {},
    options = {},
    capabilities = [],
    owners = [],
    stats = {},
    can = {},
}) {
    const [creating, setCreating] = useState(false);
    const [showMatrix, setShowMatrix] = useState(false);

    const columns = [
        {
            field: 'name',
            label: 'Source',
            render: (row) => (
                <div className="max-w-xs">
                    <p className="font-medium">{row.name}</p>
                    <p className="text-xs text-gray-400">
                        {row.vendor_key} · {row.source_type} · {row.datasets_count} dataset(s)
                    </p>
                    {!row.verified_connector && (
                        <p className="mt-0.5 text-xs text-[var(--color-warning)]">Connector mapping unverified</p>
                    )}
                </div>
            ),
        },
        {
            field: 'health_status',
            label: 'Health',
            render: (row) => (
                <div>
                    <span className={`badge ${HEALTH_CLASSES[row.health_status] ?? 'badge-status-draft'}`}>
                        {row.health_status}
                    </span>
                    {row.paused_at && (
                        <p className="mt-1 text-xs text-[var(--color-error)]">
                            Paused after {row.consecutive_failures} failure(s)
                        </p>
                    )}
                </div>
            ),
        },
        {
            field: 'last_sync_at',
            label: 'Last sync',
            render: (row) => (
                <span className="text-xs">
                    {row.last_sync_at ? formatDateTime(row.last_sync_at) : 'Never'}
                    <br />
                    <span className="text-gray-400">{row.last_sync_status}</span>
                </span>
            ),
        },
        {
            field: 'approved_at',
            label: 'Approval',
            render: (row) =>
                row.is_approved ? (
                    <span className="text-xs text-[var(--color-success)]">
                        {row.approver?.name}
                        <br />
                        {formatDateTime(row.approved_at)}
                    </span>
                ) : (
                    <span className="badge badge-status-pending">Awaiting approval</span>
                ),
        },
        { field: 'owner', label: 'Owner', render: (row) => row.owner?.name ?? '—' },
    ];

    return (
        <AuthenticatedLayout header="Data Sources">
            <Head title="Data Sources" />

            <PageHeader
                title="Data sources"
                subtitle="Systems SecondLine extracts from. Credentials are encrypted at rest and never returned to the browser."
                breadcrumbs={[{ label: 'Administration' }, { label: 'Data sources' }]}
                actions={
                    <div className="flex flex-wrap gap-2">
                        <SecondaryButton onClick={() => setShowMatrix(true)}>Connector matrix</SecondaryButton>
                        {can.manage && <PrimaryButton onClick={() => setCreating(true)}>+ Register source</PrimaryButton>}
                    </div>
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard title="Registered" value={stats.total ?? 0} color="primary" />
                <StatCard title="Healthy" value={stats.healthy ?? 0} color="success" />
                <StatCard title="Failed" value={stats.failed ?? 0} color="danger" prominent={(stats.failed ?? 0) > 0} />
                <StatCard title="Awaiting approval" value={stats.awaiting_approval ?? 0} color="warning" />
            </div>

            <FilterBar
                route="admin.data-sources.index"
                resource="data-sources"
                currentFilters={filters}
                searchPlaceholder="Search sources…"
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    { name: 'health_status', type: 'select', label: 'Health', options: options.health_statuses ?? [] },
                    { name: 'system_category', type: 'select', label: 'Category', options: options.system_categories ?? [] },
                    { name: 'vendor_key', type: 'select', label: 'Vendor', options: options.vendor_keys ?? [] },
                    { name: 'pending_approval', type: 'checkbox', label: 'Awaiting approval' },
                ]}
            />

            <div className="card">
                <DataTable
                    columns={columns}
                    data={sources.data}
                    emptyMessage="No data sources registered yet."
                    onRowClick={(row) => router.visit(route('admin.data-sources.show', row.id))}
                />
                <Pagination paginator={sources} />
            </div>

            <MatrixModal show={showMatrix} capabilities={capabilities} onClose={() => setShowMatrix(false)} />
            <CreateModal show={creating} options={options} owners={owners} onClose={() => setCreating(false)} />
        </AuthenticatedLayout>
    );
}

function MatrixModal({ show, capabilities, onClose }) {
    return (
        <Modal show={show} onClose={onClose} maxWidth="4xl" title="Connector capability matrix">
            <div className="space-y-4">
                <p className="text-sm text-[var(--color-text-secondary)]">
                    A connector marked <strong>unverified</strong> ships with a documented field mapping that has not been
                    exercised against a live environment. Confirm each mapping against your own instance before approving a rule
                    that depends on it.
                </p>
                <div className="max-h-[60vh] overflow-y-auto">
                    {capabilities.map((capability) => (
                        <div key={capability.label} className="border-b border-gray-100 py-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-medium">{capability.label}</span>
                                <span className={`badge ${capability.verified ? 'badge-status-active' : 'badge-medium'}`}>
                                    {capability.verified ? 'Verified' : 'Unverified transport'}
                                </span>
                                <span className="badge badge-status-draft">{capability.transport}</span>
                            </div>
                            {capability.documentation && (
                                <p className="mt-1 text-xs text-[var(--color-text-secondary)]">{capability.documentation}</p>
                            )}
                            <div className="mt-2 grid grid-cols-1 gap-2 text-xs sm:grid-cols-2">
                                <div>
                                    <p className="font-semibold text-gray-500">Supports</p>
                                    <ul className="text-gray-400">
                                        <li>Auth: {capability.auth_types.join(', ') || 'none'}</li>
                                        <li>Incremental: {capability.supports_incremental ? 'yes' : 'no'}</li>
                                        <li>Pagination: {capability.supports_pagination ? 'yes' : 'no'}</li>
                                        <li>Discovery: {capability.supports_discovery ? 'yes' : 'no'}</li>
                                        {capability.datasets.length > 0 && <li>Datasets: {capability.datasets.join(', ')}</li>}
                                    </ul>
                                </div>
                                <div>
                                    <p className="font-semibold text-gray-500">Does not cover</p>
                                    <ul className="list-disc pl-4 text-gray-400">
                                        {(capability.not_covered ?? []).map((item) => (
                                            <li key={item}>{item}</li>
                                        ))}
                                    </ul>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
                <div className="flex justify-end">
                    <SecondaryButton onClick={onClose}>Close</SecondaryButton>
                </div>
            </div>
        </Modal>
    );
}

function CreateModal({ show, options, owners, onClose }) {
    const form = useForm({
        name: '',
        source_type: 'rest_api',
        system_category: 'core_banking',
        vendor_key: 'custom',
        auth_type: 'api_key',
        connection_config_text: '{\n  "base_url": "https://core.bank.internal/api"\n}',
        credentials: '',
        timezone: 'Africa/Lagos',
        failure_threshold: 5,
        rate_limit_per_minute: 60,
        timeout_seconds: 30,
        data_residency_note: '',
        owner_id: '',
    });

    const [configError, setConfigError] = useState(null);

    const submit = (event) => {
        event.preventDefault();

        let config;
        try {
            config = JSON.parse(form.data.connection_config_text || '{}');
        } catch (e) {
            setConfigError('The connection configuration is not valid JSON.');
            return;
        }

        setConfigError(null);
        form.transform(({ connection_config_text, ...rest }) => ({ ...rest, connection_config: config }));
        form.post(route('admin.data-sources.store'), { onSuccess: onClose });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="4xl" title="Register a data source">
            <form onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel value="Name" required />
                        <TextInput
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            placeholder="Finacle production (read-only)"
                        />
                        {form.errors.name && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.name}</p>}
                    </div>
                    <div>
                        <InputLabel value="Owner" />
                        <SelectInput value={form.data.owner_id} onChange={(e) => form.setData('owner_id', e.target.value)}>
                            <option value="">Unassigned</option>
                            {owners.map((owner) => (
                                <option key={owner.id} value={owner.id}>
                                    {owner.name}
                                </option>
                            ))}
                        </SelectInput>
                        <p className="mt-1 text-xs text-gray-400">The owner is notified if the circuit breaker trips.</p>
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
                    {[
                        ['vendor_key', 'Vendor', options.vendor_keys],
                        ['source_type', 'Transport', options.source_types],
                        ['system_category', 'Category', options.system_categories],
                        ['auth_type', 'Authentication', options.auth_types],
                    ].map(([field, label, list]) => (
                        <div key={field}>
                            <InputLabel value={label} required />
                            <SelectInput value={form.data[field]} onChange={(e) => form.setData(field, e.target.value)}>
                                {(list ?? []).map((value) => (
                                    <option key={value} value={value}>
                                        {value}
                                    </option>
                                ))}
                            </SelectInput>
                        </div>
                    ))}
                </div>

                <div>
                    <InputLabel value="Connection configuration (JSON)" />
                    <textarea
                        className="form-input font-mono text-xs"
                        rows={5}
                        value={form.data.connection_config_text}
                        onChange={(e) => form.setData('connection_config_text', e.target.value)}
                    />
                    <p className="mt-1 text-xs text-gray-400">
                        Encrypted at rest and never returned to the browser. Put no secrets here — use the credentials field.
                    </p>
                    {configError && <p className="mt-1 text-xs text-[var(--color-error)]">{configError}</p>}
                </div>

                <div>
                    <InputLabel value="Credentials (write-only)" />
                    <TextInput
                        type="password"
                        autoComplete="new-password"
                        value={form.data.credentials}
                        onChange={(e) => form.setData('credentials', e.target.value)}
                        placeholder='{"username":"svc_secondline","password":"…"}'
                    />
                    <p className="mt-1 text-xs text-gray-400">
                        Stored encrypted. Once saved it is never displayed again — leave blank on an edit to keep it.
                    </p>
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
                    <div>
                        <InputLabel value="Timezone" />
                        <TextInput value={form.data.timezone} onChange={(e) => form.setData('timezone', e.target.value)} />
                    </div>
                    <div>
                        <InputLabel value="Failure threshold" />
                        <TextInput
                            type="number"
                            value={form.data.failure_threshold}
                            onChange={(e) => form.setData('failure_threshold', e.target.value)}
                        />
                        <p className="mt-1 text-xs text-gray-400">Consecutive failures before the breaker pauses the source.</p>
                    </div>
                    <div>
                        <InputLabel value="Rate limit / minute" />
                        <TextInput
                            type="number"
                            value={form.data.rate_limit_per_minute}
                            onChange={(e) => form.setData('rate_limit_per_minute', e.target.value)}
                        />
                    </div>
                    <div>
                        <InputLabel value="Timeout (seconds)" />
                        <TextInput
                            type="number"
                            value={form.data.timeout_seconds}
                            onChange={(e) => form.setData('timeout_seconds', e.target.value)}
                        />
                    </div>
                </div>

                <div>
                    <InputLabel value="Data residency note" />
                    <TextArea
                        rows={2}
                        value={form.data.data_residency_note}
                        onChange={(e) => form.setData('data_residency_note', e.target.value)}
                        placeholder="Where the extracted data is stored, and under whose authority."
                    />
                </div>

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Register (inactive until approved)</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
