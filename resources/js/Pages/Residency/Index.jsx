import DataTable from '@/Components/DataTable';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import StatusBadge from '@/Components/StatusBadge';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDateTime } from '@/utils';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ declaration, transfers, attestations, lawfulBases = [], entities = [], can = {} }) {
    const [recording, setRecording] = useState(false);

    const transferColumns = [
        { field: 'reference', label: 'Ref', render: (r) => <span className="font-mono text-xs">{r.reference}</span> },
        {
            field: 'description',
            label: 'Transfer',
            render: (r) => (
                <div>
                    <span className="text-sm text-gray-900">{r.description}</span>
                    <div className="text-xs text-gray-500">
                        → {r.destination_country} · {r.recipient}
                        {r.contains_personal_data ? ' · personal data' : ''}
                    </div>
                </div>
            ),
        },
        {
            field: 'lawful_basis',
            label: 'Lawful basis',
            render: (r) => <span className="font-mono text-xs">{r.lawful_basis?.code ?? '—'}</span>,
        },
        {
            field: 'people',
            label: 'Recorded / authorised',
            render: (r) => (
                <span className="text-xs text-gray-600">
                    {r.recorder?.name ?? '—'}{r.authoriser ? ` / ${r.authoriser.name}` : ' / pending'}
                </span>
            ),
        },
        { field: 'status', label: 'Status', render: (r) => <StatusBadge status={r.status.replaceAll('_', ' ')} /> },
        {
            field: 'actions',
            label: '',
            render: (r) => (
                <div className="flex justify-end gap-2">
                    {can.authorise && r.status === 'pending_authorisation' && (
                        <button
                            type="button"
                            className="text-xs font-medium text-[var(--color-primary)] hover:underline"
                            onClick={() => router.post(route('residency.transfers.authorise', r.id), {}, { preserveScroll: true })}
                        >
                            Authorise
                        </button>
                    )}
                    {can.record && r.status === 'authorised' && (
                        <button
                            type="button"
                            className="text-xs font-medium text-[var(--color-primary)] hover:underline"
                            onClick={() => router.post(route('residency.transfers.complete', r.id), {}, { preserveScroll: true })}
                        >
                            Mark completed
                        </button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <AuthenticatedLayout header="Data Residency">
            <Head title="Data Residency" />

            <PageHeader
                title="Data residency"
                subtitle="Where the data lives, what lawfully left, and the signed attestation a regulator receives"
                actions={can.record && <PrimaryButton onClick={() => setRecording(true)}>Record transfer</PrimaryButton>}
            />

            <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="card">
                    <div className="card-header"><h3 className="text-sm font-semibold">Declaration</h3></div>
                    <div className="card-body space-y-2 text-sm">
                        <p>
                            Declared country:{' '}
                            <span className="font-mono text-base font-semibold text-[var(--color-primary)]">{declaration.country}</span>
                        </p>
                        <p className="text-xs text-gray-500">
                            Enforced at the filesystem, queue, backup and integration layers. The guard has no off switch;
                            changing the declaration requires re-provisioning and is audited.
                        </p>
                        <table className="mt-2 w-full text-xs">
                            <tbody>
                                {Object.entries(declaration.disk_regions).map(([disk, region]) => (
                                    <tr key={disk}>
                                        <td className="py-1 text-gray-500">disk: {disk}</td>
                                        <td className="py-1 text-right font-mono">{region}</td>
                                    </tr>
                                ))}
                                {Object.entries(declaration.queue_regions).map(([queue, region]) => (
                                    <tr key={queue}>
                                        <td className="py-1 text-gray-500">queue: {queue}</td>
                                        <td className="py-1 text-right font-mono">{region}</td>
                                    </tr>
                                ))}
                                <tr>
                                    <td className="py-1 text-gray-500">backup disk</td>
                                    <td className="py-1 text-right font-mono">{declaration.backup_disk}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div className="card lg:col-span-2">
                    <div className="card-header flex items-center justify-between">
                        <h3 className="text-sm font-semibold">Residency attestations</h3>
                        {can.attest && <AttestationForm />}
                    </div>
                    <div className="card-body p-0">
                        <DataTable
                            columns={[
                                { field: 'reference', label: 'Ref', render: (r) => <span className="font-mono text-xs">{r.reference}</span> },
                                { field: 'declared_country', label: 'Country' },
                                { field: 'period', label: 'Period', render: (r) => `${r.period_start?.slice(0, 10)} — ${r.period_end?.slice(0, 10)}` },
                                { field: 'generated', label: 'Generated', render: (r) => `${formatDateTime(r.generated_at)} · ${r.generator?.name ?? ''}` },
                                { field: 'checksum', label: 'SHA-256', render: (r) => <span className="font-mono text-xs">{r.checksum?.slice(0, 12)}…</span> },
                                {
                                    field: 'actions',
                                    label: '',
                                    render: (r) => (
                                        <a className="text-xs font-medium text-[var(--color-primary)] hover:underline" href={route('residency.attestations.download', r.id)}>
                                            PDF
                                        </a>
                                    ),
                                },
                            ]}
                            data={attestations.data}
                            emptyMessage="No attestations generated yet."
                        />
                    </div>
                </div>
            </div>

            <div className="card">
                <div className="card-header">
                    <h3 className="text-sm font-semibold">Cross-border transfer register</h3>
                </div>
                <div className="card-body p-0">
                    <DataTable columns={transferColumns} data={transfers.data} emptyMessage="Nothing has crossed the border — which is exactly what the attestation will say." />
                </div>
                <div className="card-body border-t border-gray-100 py-3">
                    <Pagination paginator={transfers} />
                </div>
            </div>

            <Modal show={recording} onClose={() => setRecording(false)} title="Record a cross-border transfer">
                <TransferForm lawfulBases={lawfulBases} entities={entities} onDone={() => setRecording(false)} />
            </Modal>
        </AuthenticatedLayout>
    );
}

function AttestationForm() {
    const form = useForm({
        period_start: new Date(new Date().getFullYear(), 0, 1).toISOString().slice(0, 10),
        period_end: new Date().toISOString().slice(0, 10),
    });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(route('residency.attestations.store'), { preserveScroll: true });
            }}
            className="flex items-end gap-2"
        >
            <TextInput type="date" value={form.data.period_start} onChange={(e) => form.setData('period_start', e.target.value)} className="text-xs" />
            <TextInput type="date" value={form.data.period_end} onChange={(e) => form.setData('period_end', e.target.value)} className="text-xs" />
            <SecondaryButton disabled={form.processing}>Generate & sign</SecondaryButton>
        </form>
    );
}

function TransferForm({ lawfulBases, entities, onDone }) {
    const form = useForm({
        entity_id: '',
        direction: 'outbound',
        data_categories: '',
        contains_personal_data: false,
        description: '',
        destination_country: '',
        recipient: '',
        lawful_basis_id: '',
        lawful_basis_note: '',
        volume_records: '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            entity_id: data.entity_id || null,
            volume_records: data.volume_records === '' ? null : data.volume_records,
            data_categories: data.data_categories.split(',').map((s) => s.trim()).filter(Boolean),
        })).post(route('residency.transfers.store'), { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="space-y-4 p-6">
            <p className="text-sm text-gray-600">
                A transfer without a usable lawful basis is blocked — not warned about. Recording and authorising are two
                different people's acts.
            </p>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel value="Entity" />
                    <SelectInput value={form.data.entity_id} onChange={(e) => form.setData('entity_id', e.target.value)}>
                        <option value="">— tenant-wide —</option>
                        {entities.map((en) => <option key={en.id} value={en.id}>{en.legal_name}</option>)}
                    </SelectInput>
                </div>
                <div>
                    <InputLabel value="Direction" required />
                    <SelectInput value={form.data.direction} onChange={(e) => form.setData('direction', e.target.value)}>
                        <option value="outbound">Outbound</option>
                        <option value="inbound">Inbound</option>
                    </SelectInput>
                </div>
                <div className="sm:col-span-2">
                    <InputLabel value="Description" required />
                    <TextArea rows={2} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} className="w-full" />
                    <InputError message={form.errors.description} />
                </div>
                <div>
                    <InputLabel value="Data categories (comma-separated)" required />
                    <TextInput value={form.data.data_categories} onChange={(e) => form.setData('data_categories', e.target.value)} className="w-full" placeholder="e.g. staff directory, control results" />
                    <InputError message={form.errors.data_categories} />
                </div>
                <div>
                    <InputLabel value="Destination country (ISO-2)" required />
                    <TextInput value={form.data.destination_country} maxLength={2} onChange={(e) => form.setData('destination_country', e.target.value.toUpperCase())} className="w-full" />
                    <InputError message={form.errors.destination_country} />
                </div>
                <div>
                    <InputLabel value="Recipient" required />
                    <TextInput value={form.data.recipient} onChange={(e) => form.setData('recipient', e.target.value)} className="w-full" />
                    <InputError message={form.errors.recipient} />
                </div>
                <div>
                    <InputLabel value="Lawful basis (NDPA Part VIII)" required />
                    <SelectInput value={form.data.lawful_basis_id} onChange={(e) => form.setData('lawful_basis_id', e.target.value)}>
                        <option value="">— select —</option>
                        {lawfulBases.map((b) => (
                            <option key={b.id} value={b.id}>
                                {b.code} — {b.name}{b.verification_status !== 'verified' ? ' (unverified)' : ''}
                            </option>
                        ))}
                    </SelectInput>
                    <InputError message={form.errors.lawful_basis_id} />
                </div>
                <div>
                    <InputLabel value="Records (volume)" />
                    <TextInput type="number" min="0" value={form.data.volume_records} onChange={(e) => form.setData('volume_records', e.target.value)} className="w-full" />
                </div>
                <div className="sm:col-span-2">
                    <InputLabel value="Basis note (adequacy / instrument reference)" />
                    <TextArea rows={2} value={form.data.lawful_basis_note} onChange={(e) => form.setData('lawful_basis_note', e.target.value)} className="w-full" />
                </div>
                <label className="flex items-center gap-2 text-sm text-gray-600 sm:col-span-2">
                    <input
                        type="checkbox"
                        className="rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                        checked={form.data.contains_personal_data}
                        onChange={(e) => form.setData('contains_personal_data', e.target.checked)}
                    />
                    Contains personal data
                </label>
            </div>
            <div className="flex justify-end gap-3">
                <SecondaryButton type="button" onClick={onDone}>Cancel</SecondaryButton>
                <PrimaryButton disabled={form.processing}>Record — pending authorisation</PrimaryButton>
            </div>
        </form>
    );
}
