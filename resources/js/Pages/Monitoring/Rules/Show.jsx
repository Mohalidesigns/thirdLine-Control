import ConfirmDialog from '@/Components/ConfirmDialog';
import DataTable from '@/Components/DataTable';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import TextArea from '@/Components/TextArea';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDateTime, formatNumber } from '@/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const HEALTH_CLASSES = {
    Healthy: 'badge-low',
    Degraded: 'badge-medium',
    Failed: 'badge-critical',
    Unknown: 'badge-status-draft',
};

export default function Show({ rule, datasets = [], schema = null, runs, falsePositives = {}, versions = [], can = {} }) {
    const [rejecting, setRejecting] = useState(false);
    const [retiring, setRetiring] = useState(false);
    const [confirmApprove, setConfirmApprove] = useState(false);

    const post = (name, data = {}) => router.post(route(name, rule.id), data, { preserveScroll: true });

    const runColumns = [
        {
            field: 'run_ref',
            label: 'Run',
            render: (row) => (
                <Link href={route('monitoring-runs.show', row.id)} className="font-mono text-xs font-semibold text-[var(--color-primary)] hover:underline">
                    {row.run_ref}
                </Link>
            ),
        },
        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
        {
            field: 'records',
            label: 'Records',
            render: (row) => (
                <span className="tabular-nums">
                    {formatNumber(row.records_failed)} failed of {formatNumber(row.records_evaluated)}
                </span>
            ),
        },
        {
            field: 'exception_rate',
            label: 'Rate',
            render: (row) => <span className="tabular-nums">{(row.exception_rate * 100).toFixed(2)}%</span>,
        },
        {
            field: 'test_instance',
            label: 'Test instance',
            render: (row) =>
                row.test_instance ? (
                    <Link href={route('test-instances.show', row.test_instance.id)} className="text-xs text-[var(--color-primary)] hover:underline">
                        {row.test_instance.reference}
                    </Link>
                ) : (
                    <span className="text-xs text-gray-400">—</span>
                ),
        },
        {
            field: 'started_at',
            label: 'Started',
            render: (row) => <span className="text-xs">{formatDateTime(row.started_at)}</span>,
        },
    ];

    return (
        <AuthenticatedLayout header={rule.name}>
            <Head title={`${rule.rule_ref} — ${rule.name}`} />

            <PageHeader
                title={rule.name}
                subtitle={rule.description}
                breadcrumbs={[
                    { label: 'Continuous monitoring', href: route('monitoring.dashboard') },
                    { label: 'Rules', href: route('monitoring-rules.index') },
                    { label: rule.rule_ref },
                ]}
                actions={
                    <div className="flex flex-wrap gap-2">
                        {can.submit && rule.status !== 'Pending Approval' && (
                            <SecondaryButton onClick={() => post('monitoring-rules.submit')}>Submit for approval</SecondaryButton>
                        )}
                        {can.approve && (
                            <>
                                <PrimaryButton onClick={() => setConfirmApprove(true)}>Approve</PrimaryButton>
                                <SecondaryButton onClick={() => setRejecting(true)}>Reject</SecondaryButton>
                            </>
                        )}
                        {can.run && (
                            <>
                                <SecondaryButton onClick={() => post('monitoring-rules.run', { capture: false })}>
                                    Run on stored data
                                </SecondaryButton>
                                <PrimaryButton onClick={() => post('monitoring-rules.run', { capture: true })}>
                                    Extract &amp; run
                                </PrimaryButton>
                            </>
                        )}
                        {can.manage && rule.status === 'Active' && (
                            <SecondaryButton onClick={() => post('monitoring-rules.pause', { paused: true })}>Pause</SecondaryButton>
                        )}
                        {can.manage && rule.status === 'Paused' && (
                            <SecondaryButton onClick={() => post('monitoring-rules.pause', { paused: false })}>Resume</SecondaryButton>
                        )}
                        {can.retire && rule.status !== 'Retired' && (
                            <SecondaryButton onClick={() => setRetiring(true)}>Retire</SecondaryButton>
                        )}
                    </div>
                }
            />

            {rule.status === 'Pending Approval' && (
                <div className="mb-6 rounded-lg border-l-4 border-l-[var(--color-warning)] bg-amber-50 px-4 py-3 text-sm">
                    <p className="font-semibold text-[var(--color-warning)]">Awaiting a second person</p>
                    <p className="mt-1 text-[var(--color-text-secondary)]">
                        This rule will not run until someone other than {rule.creator?.name ?? 'its author'} approves it. Version{' '}
                        {rule.version_no}.
                    </p>
                </div>
            )}

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard title="Status" value={rule.status} color={rule.status === 'Active' ? 'success' : 'warning'} />
                <StatCard title="Severity" value={rule.severity} color="danger" />
                <StatCard
                    title="False-positive rate"
                    value={`${((falsePositives.rate ?? 0) * 100).toFixed(1)}%`}
                    subtitle={`${falsePositives.false_positives ?? 0} of ${falsePositives.total ?? 0} findings dismissed`}
                    color={(falsePositives.rate ?? 0) > 0.3 ? 'warning' : 'info'}
                />
                <StatCard
                    title="Next run"
                    value={rule.next_run_at ? formatDateTime(rule.next_run_at) : '—'}
                    subtitle={rule.last_run_at ? `Last ran ${formatDateTime(rule.last_run_at)}` : 'Never run'}
                    color="primary"
                />
            </div>

            {(falsePositives.rate ?? 0) > 0.3 && (falsePositives.total ?? 0) >= 10 && (
                <div className="mb-6 rounded-lg border-l-4 border-l-[var(--color-warning)] bg-amber-50 px-4 py-3 text-sm">
                    <p className="font-semibold text-[var(--color-warning)]">This rule needs tuning</p>
                    <p className="mt-1 text-[var(--color-text-secondary)]">
                        More than three in ten findings are being dismissed as false positives. Editing what the rule tests sends it
                        back for re-approval, which is the point — a tuning change is a change to the control test.
                    </p>
                </div>
            )}

            <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="card lg:col-span-2">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">Definition</h2>
                        {schema && <p className="mt-1 text-xs text-[var(--color-text-secondary)]">{schema.description}</p>}
                    </div>
                    <div className="card-body">
                        <pre className="overflow-x-auto rounded-lg bg-gray-50 p-3 font-mono text-xs">
                            {JSON.stringify(rule.rule_definition, null, 2)}
                        </pre>
                        <dl className="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                            <div>
                                <dt className="text-xs uppercase tracking-wide text-gray-400">Type</dt>
                                <dd className="font-medium">{rule.rule_type}</dd>
                            </div>
                            <div>
                                <dt className="text-xs uppercase tracking-wide text-gray-400">Frequency</dt>
                                <dd className="font-medium">{rule.frequency}</dd>
                            </div>
                            <div>
                                <dt className="text-xs uppercase tracking-wide text-gray-400">Sampling</dt>
                                <dd className="font-medium">{rule.sample_config?.method ?? 'full'}</dd>
                            </div>
                            <div>
                                <dt className="text-xs uppercase tracking-wide text-gray-400">Version</dt>
                                <dd className="font-medium">v{rule.version_no}</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <div className="card">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">Provenance</h2>
                    </div>
                    <div className="card-body space-y-3 text-sm">
                        <Row label="Control">
                            {rule.control ? (
                                <Link href={route('controls.show', rule.control.id)} className="text-[var(--color-primary)] hover:underline">
                                    {rule.control.control_ref}
                                </Link>
                            ) : (
                                <span className="text-gray-400">Not linked</span>
                            )}
                        </Row>
                        <Row label="Owner">{rule.owner?.name ?? '—'}</Row>
                        <Row label="Author">{rule.creator?.name ?? '—'}</Row>
                        <Row label="Approved by">
                            {rule.approver ? `${rule.approver.name} · ${formatDateTime(rule.approved_at)}` : 'Not approved'}
                        </Row>
                        <Row label="Auto-exception">{rule.auto_create_exception ? 'Yes' : 'No'}</Row>

                        <div className="border-t border-gray-100 pt-3">
                            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">Datasets</p>
                            {datasets.length === 0 ? (
                                <p className="text-xs text-gray-400">None resolved.</p>
                            ) : (
                                <ul className="space-y-2">
                                    {datasets.map((dataset) => (
                                        <li key={dataset.id} className="text-xs">
                                            <span className="font-medium">{dataset.name}</span>
                                            <span className="text-gray-400"> · {dataset.source}</span>
                                            <br />
                                            <span className={`badge ${HEALTH_CLASSES[dataset.health] ?? 'badge-status-draft'}`}>
                                                {dataset.health ?? 'Unknown'}
                                            </span>
                                            {dataset.pii_classification !== 'none' && (
                                                <span className="ml-1 badge badge-status-draft">{dataset.pii_classification}</span>
                                            )}
                                            <span className="ml-1 font-mono text-gray-400">{dataset.table_name}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            <div className="card mb-6">
                <div className="card-header">
                    <h2 className="text-sm font-semibold">Runs</h2>
                </div>
                <DataTable columns={runColumns} data={runs.data} emptyMessage="This rule has not run yet." />
                <Pagination paginator={runs} />
            </div>

            {versions.length > 1 && (
                <div className="card">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">Version history</h2>
                    </div>
                    <div className="card-body">
                        <ul className="divide-y divide-gray-100 text-sm">
                            {versions.map((version) => (
                                <li key={version.id} className="flex items-center justify-between py-2">
                                    <span>
                                        v{version.version_no}
                                        {version.change_reason && (
                                            <span className="text-gray-400"> — {version.change_reason}</span>
                                        )}
                                    </span>
                                    <span className="text-xs text-gray-400">
                                        {version.author?.name ?? 'System'} · {formatDateTime(version.created_at)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            )}

            <ConfirmDialog
                show={confirmApprove}
                title="Approve this rule?"
                message={`${rule.rule_ref} will become active and run on its ${rule.frequency.toLowerCase()} schedule. Findings will raise exceptions on ${rule.control?.control_ref ?? 'no linked control'}.`}
                variant="primary"
                onConfirm={() => {
                    setConfirmApprove(false);
                    post('monitoring-rules.approve');
                }}
                onCancel={() => setConfirmApprove(false)}
            />

            <ReasonModal
                show={rejecting}
                title="Reject this rule"
                label="Why is it going back to draft?"
                routeName="monitoring-rules.reject"
                ruleId={rule.id}
                onClose={() => setRejecting(false)}
            />

            <ReasonModal
                show={retiring}
                title="Retire this rule"
                label="Why is it being retired?"
                routeName="monitoring-rules.retire"
                ruleId={rule.id}
                onClose={() => setRetiring(false)}
            />
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

function ReasonModal({ show, title, label, routeName, ruleId, onClose }) {
    const form = useForm({ reason: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(route(routeName, ruleId), { onSuccess: onClose, preserveScroll: true });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="lg" title={title}>
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel value={label} required />
                    <TextArea rows={4} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    {form.errors.reason && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.reason}</p>}
                </div>
                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Confirm</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
