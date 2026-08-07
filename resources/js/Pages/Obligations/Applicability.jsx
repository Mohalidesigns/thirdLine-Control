import DataTable from '@/Components/DataTable';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectInput from '@/Components/SelectInput';
import VerificationBadge from '@/Components/VerificationBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Applicability({ entity, obligations = [], users = [], can = {} }) {
    const unassigned = obligations.filter((obligation) => !obligation.is_assigned);
    const [selected, setSelected] = useState([]);

    const form = useForm({ obligation_ids: [], owner_id: '', reviewer_id: '' });

    const toggle = (id) =>
        setSelected((current) => (current.includes(id) ? current.filter((x) => x !== id) : [...current, id]));

    const assign = (event) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, obligation_ids: selected })).post(
            route('entities.obligations.bulk', entity.id),
            { onSuccess: () => setSelected([]) },
        );
    };

    const columns = [
        {
            field: 'select',
            label: '',
            render: (row) =>
                row.is_assigned ? (
                    <span className="text-xs text-gray-400">assigned</span>
                ) : (
                    <input
                        type="checkbox"
                        checked={selected.includes(row.id)}
                        onChange={() => toggle(row.id)}
                        aria-label={`Select ${row.obligation_ref}`}
                    />
                ),
        },
        {
            field: 'obligation_ref',
            label: 'Reference',
            render: (row) => <span className="font-mono text-xs font-semibold">{row.obligation_ref}</span>,
        },
        { field: 'title', label: 'Obligation' },
        { field: 'regulator', label: 'Regulator' },
        { field: 'obligation_type', label: 'Type' },
        { field: 'frequency', label: 'Frequency', render: (row) => row.frequency.replace('_', ' ') },
        {
            field: 'verification_status',
            label: 'Verification',
            render: (row) => <VerificationBadge status={row.verification_status} />,
        },
    ];

    return (
        <AuthenticatedLayout header={`${entity.name} — applicable obligations`}>
            <Head title={`${entity.name} — applicable obligations`} />

            <PageHeader
                breadcrumbs={[{ label: 'Obligations', href: route('obligations.index') }, { label: entity.name }]}
                title={`What binds ${entity.name}`}
                subtitle={`Entity type: ${entity.entity_type ?? 'not set'} · licences: ${(entity.licence_categories ?? []).join(', ') || 'none recorded'} · ${entity.jurisdiction ?? 'jurisdiction not set'}`}
            />

            {!entity.entity_type && (
                <div className="mb-4 rounded-lg border-l-4 border-l-[var(--color-warning)] bg-amber-50 px-4 py-3 text-sm">
                    This entity has no regulatory profile, so only obligations that bind everyone are listed. Set its
                    entity type and licence categories to get an accurate applicability assessment.
                </div>
            )}

            {can.assign && unassigned.length > 0 && (
                <form onSubmit={assign} className="mb-6 card">
                    <div className="card-body grid gap-4 sm:grid-cols-3">
                        <div>
                            <InputLabel htmlFor="owner_id" value="Owner for the selected obligations" />
                            <SelectInput
                                id="owner_id"
                                value={form.data.owner_id}
                                onChange={(e) => form.setData('owner_id', e.target.value)}
                                className="mt-1 block w-full"
                                required
                            >
                                <option value="">Select an owner…</option>
                                {users.map((user) => (
                                    <option key={user.id} value={user.id}>
                                        {user.name}
                                    </option>
                                ))}
                            </SelectInput>
                        </div>

                        <div>
                            <InputLabel htmlFor="reviewer_id" value="Reviewer" />
                            <SelectInput
                                id="reviewer_id"
                                value={form.data.reviewer_id}
                                onChange={(e) => form.setData('reviewer_id', e.target.value)}
                                className="mt-1 block w-full"
                            >
                                <option value="">—</option>
                                {users
                                    .filter((user) => String(user.id) !== String(form.data.owner_id))
                                    .map((user) => (
                                        <option key={user.id} value={user.id}>
                                            {user.name}
                                        </option>
                                    ))}
                            </SelectInput>
                        </div>

                        <div className="flex items-end">
                            <PrimaryButton disabled={form.processing || selected.length === 0} className="w-full justify-center">
                                Assign {selected.length} obligation(s)
                            </PrimaryButton>
                        </div>
                    </div>
                </form>
            )}

            <div className="card">
                <DataTable
                    columns={columns}
                    data={obligations}
                    emptyMessage="No shipped obligation matches this entity’s regulatory profile."
                />
            </div>
        </AuthenticatedLayout>
    );
}
