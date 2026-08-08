import ConfirmDialog from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate, formatDateTime, formatNumber } from '@/utils';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const HEALTH_CLASSES = {
    Healthy: 'badge-low',
    Degraded: 'badge-medium',
    Failed: 'badge-critical',
    Unknown: 'badge-status-draft',
};

const PII_CLASSES = {
    none: 'badge-status-draft',
    internal: 'badge-status-draft',
    personal: 'badge-medium',
    sensitive_personal: 'badge-critical',
};

export default function Show({
    source,
    capabilities = {},
    residency = {},
    datasets = [],
    snapshots = [],
    syncLogs = [],
    can = {},
}) {
    const [addingDataset, setAddingDataset] = useState(false);
    const [authorising, setAuthorising] = useState(null);
    const [confirmApprove, setConfirmApprove] = useState(false);

    const post = (name, params = {}) => router.post(route(name, params), {}, { preserveScroll: true });

    return (
        <AuthenticatedLayout header={source.name}>
            <Head title={source.name} />

            <PageHeader
                title={source.name}
                subtitle={`${capabilities.label ?? source.vendor_key} · ${source.source_type}`}
                breadcrumbs={[
                    { label: 'Administration' },
                    { label: 'Data sources', href: route('admin.data-sources.index') },
                    { label: source.name },
                ]}
                actions={
                    <div className="flex flex-wrap gap-2">
                        {can.test && (
                            <SecondaryButton onClick={() => post('admin.data-sources.test', source.id)}>
                                Test connection
                            </SecondaryButton>
                        )}
                        {can.manage && source.paused_at && (
                            <SecondaryButton onClick={() => post('admin.data-sources.resume', source.id)}>
                                Resume
                            </SecondaryButton>
                        )}
                        {can.approve && !source.is_approved && (
                            <PrimaryButton onClick={() => setConfirmApprove(true)}>Approve &amp; activate</PrimaryButton>
                        )}
                    </div>
                }
            />

            {!capabilities.verified && (
                <div className="mb-6 rounded-lg border-l-4 border-l-[var(--color-warning)] bg-amber-50 px-4 py-3 text-sm">
                    <p className="font-semibold text-[var(--color-warning)]">This connector's transport is unverified</p>
                    <p className="mt-1 text-[var(--color-text-secondary)]">
                        The field mapping below is written from vendor documentation and has not been exercised against a live
                        installation. Confirm every field against this institution's own instance before approving a monitoring
                        rule that reads from it.
                    </p>
                </div>
            )}

            {source.paused_at && (
                <div className="mb-6 rounded-lg border-l-4 border-l-[var(--color-error)] bg-red-50 px-4 py-3 text-sm">
                    <p className="font-semibold text-[var(--color-error)]">Paused by the circuit breaker</p>
                    <p className="mt-1 text-[var(--color-text-secondary)]">{source.paused_reason}</p>
                    <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                        Every monitoring rule reading from this source is dormant, so the controls it tests are unmonitored.
                    </p>
                </div>
            )}

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard title="Health" value={source.health_status} color={source.health_status === 'Healthy' ? 'success' : 'danger'} />
                <StatCard
                    title="Last sync"
                    value={source.last_sync_status}
                    subtitle={source.last_sync_at ? formatDateTime(source.last_sync_at) : 'Never run'}
                    color="info"
                />
                <StatCard
                    title="Consecutive failures"
                    value={`${source.consecutive_failures} / ${source.failure_threshold}`}
                    subtitle="Breaker trips at the threshold"
                    color={source.consecutive_failures > 0 ? 'warning' : 'success'}
                />
                <StatCard
                    title="Data plane"
                    value={residency.country ?? '—'}
                    subtitle="Snapshots are written under this country's path"
                    color="primary"
                />
            </div>

            <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="card">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">Configuration</h2>
                    </div>
                    <div className="card-body space-y-2 text-sm">
                        <Row label="Vendor">{source.vendor_key}</Row>
                        <Row label="Transport">{source.source_type}</Row>
                        <Row label="Category">{source.system_category}</Row>
                        <Row label="Authentication">{source.auth_type}</Row>
                        <Row label="Credentials">{source.masked_credentials ?? 'None stored'}</Row>
                        <Row label="Rate limit">{source.rate_limit_per_minute}/min</Row>
                        <Row label="Timeout">{source.timeout_seconds}s</Row>
                        <Row label="Timezone">{source.timezone}</Row>
                        <Row label="Owner">{source.owner?.name ?? '—'}</Row>
                        <Row label="Registered by">{source.creator?.name ?? '—'}</Row>
                        <Row label="Approved by">
                            {source.approver ? `${source.approver.name} · ${formatDate(source.approved_at)}` : 'Not approved'}
                        </Row>
                        {residency.note && (
                            <p className="mt-2 rounded-lg bg-gray-50 p-2 text-xs text-[var(--color-text-secondary)]">
                                {residency.note}
                            </p>
                        )}
                    </div>
                </div>

                <div className="card lg:col-span-2">
                    <div className="card-header flex items-center justify-between">
                        <h2 className="text-sm font-semibold">Datasets</h2>
                        {can.manage && <SecondaryButton onClick={() => setAddingDataset(true)}>+ Add dataset</SecondaryButton>}
                    </div>
                    <div className="overflow-x-auto">
                        {datasets.length === 0 ? (
                            <EmptyState
                                title="No datasets yet."
                                description="A dataset declares what to extract, how long to keep it, and which fields carry personal data."
                            />
                        ) : (
                            <table className="data-table">
                                <thead>
                                    <tr>
                                        <th>Dataset</th>
                                        <th>Classification</th>
                                        <th>Retention</th>
                                        <th>Last rows</th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody>
                                    {datasets.map((dataset) => (
                                        <tr key={dataset.id}>
                                            <td>
                                                <p className="font-medium">{dataset.name}</p>
                                                <p className="text-xs text-gray-400">{dataset.extraction_summary}</p>
                                                {!dataset.is_active && (
                                                    <span className="badge badge-status-draft mt-1">Inactive</span>
                                                )}
                                            </td>
                                            <td>
                                                <span className={`badge ${PII_CLASSES[dataset.pii_classification]}`}>
                                                    {dataset.pii_classification}
                                                </span>
                                                {dataset.requires_hashing && (
                                                    <p className="mt-1 text-xs text-[var(--color-warning)]">
                                                        Hashed at ingestion
                                                    </p>
                                                )}
                                                {dataset.dpo_authorised_at && (
                                                    <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                                                        Retention authorised by {dataset.dpo_authoriser}
                                                    </p>
                                                )}
                                                {(dataset.pii_fields ?? []).length > 0 && (
                                                    <p className="mt-1 font-mono text-[10px] text-gray-400">
                                                        {dataset.pii_fields.join(', ')}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="text-xs">{dataset.retention_days} days</td>
                                            <td className="text-xs tabular-nums">
                                                {dataset.row_count_last != null ? formatNumber(dataset.row_count_last) : '—'}
                                            </td>
                                            <td>
                                                <div className="flex flex-wrap gap-1">
                                                    {can.manage && (
                                                        <SecondaryButton
                                                            onClick={() =>
                                                                post('admin.data-sources.datasets.capture', [
                                                                    source.id,
                                                                    dataset.id,
                                                                ])
                                                            }
                                                        >
                                                            Capture
                                                        </SecondaryButton>
                                                    )}
                                                    {can.authorise_pii &&
                                                        dataset.pii_classification === 'sensitive_personal' &&
                                                        (dataset.dpo_authorised_at ? (
                                                            <SecondaryButton
                                                                onClick={() =>
                                                                    router.delete(
                                                                        route(
                                                                            'admin.data-sources.datasets.revoke-retention',
                                                                            [source.id, dataset.id],
                                                                        ),
                                                                        { preserveScroll: true },
                                                                    )
                                                                }
                                                            >
                                                                Revoke
                                                            </SecondaryButton>
                                                        ) : (
                                                            <SecondaryButton onClick={() => setAuthorising(dataset)}>
                                                                Authorise retention
                                                            </SecondaryButton>
                                                        ))}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                </div>
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div className="card">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">Recent snapshots</h2>
                    </div>
                    <div className="overflow-x-auto">
                        {snapshots.length === 0 ? (
                            <EmptyState title="Nothing captured yet." />
                        ) : (
                            <table className="data-table">
                                <thead>
                                    <tr>
                                        <th>Snapshot</th>
                                        <th>Rows</th>
                                        <th>Status</th>
                                        <th>Expires</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {snapshots.map((snapshot) => (
                                        <tr key={snapshot.id}>
                                            <td>
                                                <p className="font-mono text-xs font-semibold">{snapshot.snapshot_ref}</p>
                                                <p className="text-xs text-gray-400">
                                                    {snapshot.dataset?.name} · {formatDateTime(snapshot.captured_at)}
                                                </p>
                                                {snapshot.error_message && (
                                                    <p className="text-xs text-[var(--color-error)]">{snapshot.error_message}</p>
                                                )}
                                            </td>
                                            <td className="text-xs tabular-nums">{formatNumber(snapshot.row_count)}</td>
                                            <td>
                                                <StatusBadge status={snapshot.status} />
                                                {snapshot.legal_hold && (
                                                    <span className="badge badge-critical ml-1">Legal hold</span>
                                                )}
                                            </td>
                                            <td className="text-xs">
                                                {snapshot.expires_at ? formatDate(snapshot.expires_at) : '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                </div>

                <div className="card">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">Sync log</h2>
                    </div>
                    <div className="card-body">
                        {syncLogs.length === 0 ? (
                            <p className="text-sm text-gray-400">No extraction attempts recorded.</p>
                        ) : (
                            <ul className="divide-y divide-gray-100 text-sm">
                                {syncLogs.map((log) => (
                                    <li key={log.id} className="py-2">
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="text-xs font-medium">
                                                {log.entity_type.replace('ccm:', '')}
                                                {log.payload?.dataset && (
                                                    <span className="text-gray-400"> · {log.payload.dataset}</span>
                                                )}
                                            </span>
                                            <StatusBadge status={log.status === 'Success' ? 'Completed' : 'Open'} />
                                        </div>
                                        <p className="text-xs text-gray-400">
                                            {formatDateTime(log.attempted_at)}
                                            {log.payload?.rows != null && ` · ${formatNumber(log.payload.rows)} row(s)`}
                                        </p>
                                        {log.error_message && (
                                            <p className="mt-0.5 text-xs text-[var(--color-error)]">{log.error_message}</p>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            </div>

            <ConfirmDialog
                show={confirmApprove}
                title="Approve this data source?"
                message={`${source.name} will become active and start extracting on the nightly sweep. Approving is recorded against you, and you cannot approve a source you registered or own.`}
                variant="primary"
                onConfirm={() => {
                    setConfirmApprove(false);
                    post('admin.data-sources.approve', source.id);
                }}
                onCancel={() => setConfirmApprove(false)}
            />

            <DatasetModal show={addingDataset} sourceId={source.id} onClose={() => setAddingDataset(false)} />
            <AuthoriseModal dataset={authorising} sourceId={source.id} onClose={() => setAuthorising(null)} />
        </AuthenticatedLayout>
    );
}

function Row({ label, children }) {
    return (
        <div className="flex items-start justify-between gap-3">
            <span className="text-[var(--color-text-secondary)]">{label}</span>
            <span className="text-right font-medium">{children}</span>
        </div>
    );
}

function DatasetModal({ show, sourceId, onClose }) {
    const form = useForm({
        name: '',
        description: '',
        extraction_config_text: '{\n  "table": "gl_postings",\n  "columns": ["id", "amount", "maker", "checker"]\n}',
        primary_key_fields: '',
        incremental_field: '',
        retention_days: 365,
        pii_classification: 'none',
        pii_fields: '',
        is_active: true,
    });

    const [configError, setConfigError] = useState(null);

    const submit = (event) => {
        event.preventDefault();

        let config;
        try {
            config = JSON.parse(form.data.extraction_config_text || '{}');
        } catch (e) {
            setConfigError('The extraction configuration is not valid JSON.');
            return;
        }

        setConfigError(null);
        form.transform(({ extraction_config_text, primary_key_fields, pii_fields, ...rest }) => ({
            ...rest,
            extraction_config: config,
            primary_key_fields: primary_key_fields ? primary_key_fields.split(',').map((f) => f.trim()).filter(Boolean) : [],
            pii_fields: pii_fields ? pii_fields.split(',').map((f) => f.trim()).filter(Boolean) : [],
        }));
        form.post(route('admin.data-sources.datasets.store', sourceId), { onSuccess: onClose, preserveScroll: true });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl" title="Add dataset">
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel value="Name" required />
                    <TextInput value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    {form.errors.name && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.name}</p>}
                </div>

                <div>
                    <InputLabel value="Extraction configuration (JSON)" />
                    <textarea
                        className="form-input font-mono text-xs"
                        rows={5}
                        value={form.data.extraction_config_text}
                        onChange={(e) => form.setData('extraction_config_text', e.target.value)}
                    />
                    {configError && <p className="mt-1 text-xs text-[var(--color-error)]">{configError}</p>}
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <InputLabel value="Primary key fields" />
                        <TextInput
                            value={form.data.primary_key_fields}
                            onChange={(e) => form.setData('primary_key_fields', e.target.value)}
                            placeholder="id, branch"
                        />
                    </div>
                    <div>
                        <InputLabel value="Incremental field" />
                        <TextInput
                            value={form.data.incremental_field}
                            onChange={(e) => form.setData('incremental_field', e.target.value)}
                            placeholder="posted_at"
                        />
                    </div>
                    <div>
                        <InputLabel value="Retention (days)" required />
                        <TextInput
                            type="number"
                            value={form.data.retention_days}
                            onChange={(e) => form.setData('retention_days', e.target.value)}
                        />
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel value="PII classification" required />
                        <SelectInput
                            value={form.data.pii_classification}
                            onChange={(e) => form.setData('pii_classification', e.target.value)}
                        >
                            {['none', 'internal', 'personal', 'sensitive_personal'].map((value) => (
                                <option key={value} value={value}>
                                    {value}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Fields carrying personal data" />
                        <TextInput
                            value={form.data.pii_fields}
                            onChange={(e) => form.setData('pii_fields', e.target.value)}
                            placeholder="bvn, account_number, customer_name"
                        />
                        {form.errors.pii_fields && (
                            <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.pii_fields}</p>
                        )}
                    </div>
                </div>

                {form.data.pii_classification === 'sensitive_personal' && (
                    <p className="rounded-lg bg-amber-50 p-2 text-xs text-[var(--color-warning)]">
                        These fields will be replaced with a keyed hash at ingestion unless the DPO explicitly authorises
                        retaining them in clear. The hash is stable, so a subject still joins across snapshots.
                    </p>
                )}

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Add dataset</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

function AuthoriseModal({ dataset, sourceId, onClose }) {
    const form = useForm({ note: '' });

    if (!dataset) return null;

    const submit = (event) => {
        event.preventDefault();
        form.post(route('admin.data-sources.datasets.authorise-retention', [sourceId, dataset.id]), {
            onSuccess: onClose,
            preserveScroll: true,
        });
    };

    return (
        <Modal show={!!dataset} onClose={onClose} maxWidth="lg" title="Authorise retention of sensitive personal data">
            <form onSubmit={submit} className="space-y-4">
                <p className="rounded-lg bg-amber-50 p-3 text-sm text-[var(--color-text-secondary)]">
                    Without this authorisation, <strong>{(dataset.pii_fields ?? []).join(', ') || 'the declared fields'}</strong>{' '}
                    are hashed at ingestion on <strong>{dataset.name}</strong> and cannot be recovered. Authorising retains them
                    in clear in the snapshot store from the next capture onwards.
                </p>
                <div>
                    <InputLabel value="Lawful basis and justification" required />
                    <TextArea
                        rows={4}
                        value={form.data.note}
                        onChange={(e) => form.setData('note', e.target.value)}
                        placeholder="Why the control cannot operate on pseudonymised values, and under which NDPA lawful basis the data is retained."
                    />
                    {form.errors.note && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.note}</p>}
                </div>
                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Authorise</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
