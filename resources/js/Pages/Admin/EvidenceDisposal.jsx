import DataTable from '@/Components/DataTable';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Plus } from 'lucide-react';

export default function EvidenceDisposal({ queued, policies = [] }) {
    const { auth } = usePage().props;
    const [showPolicy, setShowPolicy] = useState(false);

    const policyForm = useForm({
        name: '',
        evidence_class: '',
        retention_months: 72,
        disposal_action: 'Delete',
        requires_dual_approval: true,
        legal_basis_note: '',
        is_default: false,
    });

    return (
        <AuthenticatedLayout header="Evidence Disposal">
            <Head title="Evidence Disposal" />

            <PageHeader
                title="Evidence retention & disposal"
                subtitle="Items past retention expiry require two different approvers before permanent deletion"
                actions={<SecondaryButton onClick={() => setShowPolicy(true)}><Plus className="h-4 w-4" strokeWidth={2} /> Retention policy</SecondaryButton>}
            />

            <div className="card mb-6">
                <div className="card-header"><h3 className="text-sm font-semibold">Disposal queue</h3></div>
                <DataTable
                    columns={[
                        { field: 'file_name', label: 'File' },
                        { field: 'retentionPolicy', label: 'Policy', render: (row) => row.retention_policy?.name ?? '—' },
                        { field: 'retention_expiry_date', label: 'Expired', render: (row) => formatDate(row.retention_expiry_date) },
                        { field: 'uploader', label: 'Uploaded by', render: (row) => row.uploader?.name ?? '—' },
                        {
                            field: 'approvals',
                            label: 'Approvals',
                            render: (row) => (
                                <span className="badge badge-status-pending">
                                    {row.disposal_requested_by ? '1 of 2' : '0 of 2'}
                                </span>
                            ),
                        },
                        {
                            field: 'actions',
                            label: '',
                            render: (row) =>
                                row.disposal_requested_by === auth.user.id ? (
                                    <span className="text-xs text-gray-400">Awaiting second approver</span>
                                ) : (
                                    <PrimaryButton
                                        onClick={() => router.post(route('admin.evidence.approve-disposal', row.id), {}, { preserveScroll: true })}
                                    >
                                        {row.disposal_requested_by ? 'Final approval & dispose' : 'First approval'}
                                    </PrimaryButton>
                                ),
                        },
                    ]}
                    data={queued.data}
                    emptyMessage="Nothing awaiting disposal — evidence past retention expiry appears here (legal holds never do)."
                />
                <Pagination paginator={queued} />
            </div>

            <div className="card">
                <div className="card-header"><h3 className="text-sm font-semibold">Retention policies</h3></div>
                <DataTable
                    columns={[
                        { field: 'name', label: 'Policy' },
                        { field: 'evidence_class', label: 'Evidence class' },
                        { field: 'retention_months', label: 'Retention', render: (row) => `${row.retention_months} months` },
                        { field: 'disposal_action', label: 'Disposal' },
                        { field: 'requires_dual_approval', label: 'Dual approval', render: (row) => (row.requires_dual_approval ? 'Yes' : 'No') },
                        { field: 'is_default', label: 'Default', render: (row) => (row.is_default ? '✓' : '') },
                    ]}
                    data={policies}
                    emptyMessage="No retention policies defined."
                />
            </div>

            <Modal show={showPolicy} onClose={() => setShowPolicy(false)} title="New retention policy" maxWidth="lg">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        policyForm.post(route('admin.retention-policies.store'), {
                            preserveScroll: true,
                            onSuccess: () => setShowPolicy(false),
                        });
                    }}
                    className="space-y-4"
                >
                    <p className="text-xs text-gray-500">
                        Retention periods are a client configuration decision — confirm values with the Data
                        Protection Officer before go-live.
                    </p>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel value="Name" required />
                            <TextInput value={policyForm.data.name} onChange={(e) => policyForm.setData('name', e.target.value)} />
                        </div>
                        <div>
                            <InputLabel value="Evidence class" required />
                            <TextInput value={policyForm.data.evidence_class} onChange={(e) => policyForm.setData('evidence_class', e.target.value)} />
                        </div>
                        <div>
                            <InputLabel value="Retention (months)" required />
                            <TextInput type="number" min="1" value={policyForm.data.retention_months} onChange={(e) => policyForm.setData('retention_months', Number(e.target.value))} />
                        </div>
                        <div>
                            <InputLabel value="Disposal action" required />
                            <SelectInput value={policyForm.data.disposal_action} onChange={(e) => policyForm.setData('disposal_action', e.target.value)}>
                                <option value="Delete">Delete</option>
                                <option value="Anonymise">Anonymise</option>
                            </SelectInput>
                        </div>
                    </div>
                    <div>
                        <InputLabel value="Legal basis note" />
                        <TextArea rows={2} value={policyForm.data.legal_basis_note} onChange={(e) => policyForm.setData('legal_basis_note', e.target.value)} />
                    </div>
                    <label className="flex items-center gap-2 text-sm text-gray-600">
                        <input
                            type="checkbox"
                            className="rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                            checked={policyForm.data.is_default}
                            onChange={(e) => policyForm.setData('is_default', e.target.checked)}
                        />
                        Default policy for evidence with no specific class
                    </label>
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={() => setShowPolicy(false)}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={policyForm.processing}>Save policy</PrimaryButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
