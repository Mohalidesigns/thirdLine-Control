import ConfirmDialog from '@/Components/ConfirmDialog';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import SeverityBadge from '@/Components/SeverityBadge';
import StatusBadge from '@/Components/StatusBadge';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Plus } from 'lucide-react';

const EMPTY_FINDING = {
    control_id: '',
    observation: '',
    severity: 'Medium',
    risk_implication: '',
    recommendation: '',
    management_response: '',
    agreed_action: '',
    responsible_party_id: '',
    target_date: '',
};

export default function Show({ spotCheck, scopedControls = [], allControls = [], users = [] }) {
    const [showFinding, setShowFinding] = useState(false);
    const [editingFinding, setEditingFinding] = useState(null);
    const [showComplete, setShowComplete] = useState(false);

    const findingForm = useForm({ ...EMPTY_FINDING });
    const inProgress = spotCheck.status === 'In Progress';

    const openNew = () => {
        findingForm.setDefaults({ ...EMPTY_FINDING });
        Object.entries(EMPTY_FINDING).forEach(([key, value]) => findingForm.setData(key, value));
        setEditingFinding(null);
        setShowFinding(true);
    };

    const openEdit = (finding) => {
        Object.keys(EMPTY_FINDING).forEach((key) => findingForm.setData(key, finding[key] ?? EMPTY_FINDING[key]));
        setEditingFinding(finding);
        setShowFinding(true);
    };

    const submitFinding = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setShowFinding(false) };
        if (editingFinding) {
            findingForm.put(route('spot-checks.findings.update', [spotCheck.id, editingFinding.id]), options);
        } else {
            findingForm.post(route('spot-checks.findings.store', spotCheck.id), options);
        }
    };

    return (
        <AuthenticatedLayout header={spotCheck.reference}>
            <Head title={spotCheck.reference} />

            <PageHeader
                title={
                    <span className="flex items-center gap-3">
                        {spotCheck.title} <StatusBadge status={spotCheck.status} />
                        {spotCheck.is_surprise && <span className="badge badge-status-pending">Unannounced</span>}
                    </span>
                }
                subtitle={
                    <span>
                        <span className="font-mono">{spotCheck.reference}</span> · {formatDate(spotCheck.date_conducted)} · {spotCheck.conductor?.name}
                    </span>
                }
                breadcrumbs={[{ label: 'Spot Checks', href: route('spot-checks.index') }, { label: spotCheck.reference }]}
                actions={
                    <>
                        {inProgress && (
                            <>
                                <PrimaryButton onClick={openNew}><Plus className="h-4 w-4" strokeWidth={2} /> Capture finding</PrimaryButton>
                                <SecondaryButton onClick={() => setShowComplete(true)}>Complete check</SecondaryButton>
                            </>
                        )}
                        {['Completed', 'Report Issued'].includes(spotCheck.status) && (
                            <a href={route('reports.spot-check', spotCheck.id)} className="btn-secondary">
                                Download report (PDF)
                            </a>
                        )}
                        {spotCheck.status === 'Completed' && (
                            <PrimaryButton onClick={() => router.post(route('spot-checks.issue-report', spotCheck.id))}>
                                Issue report
                            </PrimaryButton>
                        )}
                    </>
                }
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <div className="card">
                        <div className="card-header">
                            <h3 className="text-sm font-semibold">Findings ({spotCheck.findings?.length ?? 0})</h3>
                        </div>
                        <div className="card-body space-y-4">
                            {(spotCheck.findings ?? []).length === 0 && (
                                <p className="text-sm text-gray-400">No findings captured yet.</p>
                            )}
                            {(spotCheck.findings ?? []).map((finding, index) => (
                                <div key={finding.id} className="rounded-lg border border-gray-100 p-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <p className="text-sm font-medium text-[var(--color-text-primary)]">
                                            {index + 1}. {finding.observation}
                                        </p>
                                        <div className="flex shrink-0 items-center gap-2">
                                            <SeverityBadge severity={finding.severity} />
                                            {inProgress && (
                                                <button type="button" className="text-xs text-[var(--color-primary)] hover:underline" onClick={() => openEdit(finding)}>
                                                    Edit
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                    <div className="mt-2 space-y-1 text-xs text-gray-500">
                                        {finding.control && <p>Control: <span className="font-mono">{finding.control.control_ref}</span> {finding.control.title}</p>}
                                        {finding.risk_implication && <p>Risk implication: {finding.risk_implication}</p>}
                                        {finding.recommendation && <p>Recommendation: {finding.recommendation}</p>}
                                        {finding.management_response && (
                                            <p className="text-[var(--color-info)]">Management response: {finding.management_response}</p>
                                        )}
                                        {finding.agreed_action && <p>Agreed action: {finding.agreed_action} {finding.target_date && `(by ${formatDate(finding.target_date)})`}</p>}
                                        {finding.responsible_party && <p>Responsible: {finding.responsible_party.name}</p>}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>

                <div className="space-y-6">
                    <div className="card">
                        <div className="card-body space-y-2 text-sm">
                            <p><span className="text-gray-500">Unit:</span> {spotCheck.unit?.name ?? '—'}</p>
                            <p><span className="text-gray-500">Process:</span> {spotCheck.process?.name ?? '—'}</p>
                            <p><span className="text-gray-500">Scope:</span> {spotCheck.scope_description || '—'}</p>
                            {spotCheck.report_issued_at && (
                                <p><span className="text-gray-500">Report issued:</span> {formatDate(spotCheck.report_issued_at)}</p>
                            )}
                        </div>
                    </div>

                    {scopedControls.length > 0 && (
                        <div className="card">
                            <div className="card-header"><h3 className="text-sm font-semibold">Controls in scope</h3></div>
                            <div className="card-body space-y-1.5">
                                {scopedControls.map((control) => (
                                    <p key={control.id} className="text-sm">
                                        <span className="font-mono text-xs text-gray-500">{control.control_ref}</span> {control.title}
                                    </p>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>

            <Modal show={showFinding} onClose={() => setShowFinding(false)} title={editingFinding ? 'Edit finding' : 'Capture finding'} maxWidth="lg">
                <form onSubmit={submitFinding} className="space-y-4">
                    <div>
                        <InputLabel value="Observation" required />
                        <TextArea value={findingForm.data.observation} onChange={(e) => findingForm.setData('observation', e.target.value)} />
                        <InputError message={findingForm.errors.observation} className="mt-1" />
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel value="Severity" required />
                            <SelectInput value={findingForm.data.severity} onChange={(e) => findingForm.setData('severity', e.target.value)}>
                                {['Critical', 'High', 'Medium', 'Low'].map((s) => <option key={s} value={s}>{s}</option>)}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Related control" />
                            <SelectInput value={findingForm.data.control_id ?? ''} onChange={(e) => findingForm.setData('control_id', e.target.value)}>
                                <option value="">— None —</option>
                                {allControls.map((control) => (
                                    <option key={control.id} value={control.id}>{control.control_ref} — {control.title}</option>
                                ))}
                            </SelectInput>
                        </div>
                    </div>
                    <div>
                        <InputLabel value="Risk implication" />
                        <TextArea rows={2} value={findingForm.data.risk_implication ?? ''} onChange={(e) => findingForm.setData('risk_implication', e.target.value)} />
                    </div>
                    <div>
                        <InputLabel value="Recommendation" />
                        <TextArea rows={2} value={findingForm.data.recommendation ?? ''} onChange={(e) => findingForm.setData('recommendation', e.target.value)} />
                    </div>
                    <div>
                        <InputLabel value="Management response" />
                        <TextArea rows={2} value={findingForm.data.management_response ?? ''} onChange={(e) => findingForm.setData('management_response', e.target.value)} />
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel value="Agreed action" />
                            <TextInput value={findingForm.data.agreed_action ?? ''} onChange={(e) => findingForm.setData('agreed_action', e.target.value)} />
                        </div>
                        <div>
                            <InputLabel value="Target date" />
                            <TextInput type="date" value={findingForm.data.target_date ?? ''} onChange={(e) => findingForm.setData('target_date', e.target.value)} />
                        </div>
                    </div>
                    <div>
                        <InputLabel value="Responsible party" />
                        <SelectInput value={findingForm.data.responsible_party_id ?? ''} onChange={(e) => findingForm.setData('responsible_party_id', e.target.value)}>
                            <option value="">— Select —</option>
                            {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                        </SelectInput>
                    </div>
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={() => setShowFinding(false)}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={findingForm.processing}>{editingFinding ? 'Save' : 'Capture'}</PrimaryButton>
                    </div>
                </form>
            </Modal>

            <ConfirmDialog
                show={showComplete}
                title="Complete this spot check?"
                message={`All ${spotCheck.findings?.length ?? 0} finding(s) will flow into the exception tracker for remediation. Capture management responses before completing.`}
                confirmLabel="Complete"
                variant="primary"
                onConfirm={() => {
                    router.post(route('spot-checks.complete', spotCheck.id));
                    setShowComplete(false);
                }}
                onCancel={() => setShowComplete(false)}
            />
        </AuthenticatedLayout>
    );
}
