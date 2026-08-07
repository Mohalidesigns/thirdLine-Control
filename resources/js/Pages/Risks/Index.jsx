import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import InputError from '@/Components/InputError';
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
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

function ratingBadge(value) {
    if (value === null || value === undefined) return <span className="text-gray-400">—</span>;
    const rounded = Math.round(Number(value) * 10) / 10;
    const cls = rounded >= 15 ? 'badge-critical' : rounded >= 9 ? 'badge-high' : rounded >= 5 ? 'badge-medium' : 'badge-low';
    return <span className={`badge ${cls}`}>{rounded}</span>;
}

export default function Index({ risks, filters = {}, categories = [] }) {
    const { auth } = usePage().props;
    const canCreate = auth.permissions.includes('create risks');
    const [showCreate, setShowCreate] = useState(false);

    const form = useForm({
        title: '',
        description: '',
        category: '',
        inherent_likelihood: 3,
        inherent_impact: 3,
    });

    const columns = [
        { field: 'code', label: 'Code', render: (row) => <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{row.code}</span> },
        {
            field: 'title',
            label: 'Risk',
            render: (row) => (
                <div>
                    <p className="font-medium text-[var(--color-text-primary)]">{row.title}</p>
                    <p className="text-xs text-gray-400">{row.category ?? '—'}</p>
                </div>
            ),
        },
        { field: 'inherent_rating', label: 'Inherent', render: (row) => ratingBadge(row.inherent_rating) },
        { field: 'residual_rating', label: 'Residual', render: (row) => ratingBadge(row.residual_rating) },
        {
            field: 'controls',
            label: 'Mapped controls',
            render: (row) =>
                row.controls?.length ? (
                    <span className="text-sm">{row.controls.length}</span>
                ) : (
                    <span className="badge badge-critical">Gap</span>
                ),
        },
        { field: 'owner', label: 'Owner', render: (row) => row.owner?.name ?? '—' },
    ];

    return (
        <AuthenticatedLayout header="Risk Register">
            <Head title="Risk Register" />

            <PageHeader
                title="Risk Register"
                subtitle="Risks and the controls that mitigate them"
                actions={
                    <>
                        <Link href={route('risks.gaps')} className="btn-secondary">Gap analysis</Link>
                        {canCreate && <PrimaryButton onClick={() => setShowCreate(true)}>+ New risk</PrimaryButton>}
                    </>
                }
            />

            <FilterBar
                route="risks.index"
                resource="risks"
                currentFilters={filters}
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    { name: 'category', type: 'select', label: 'Category', options: categories },
                ]}
            />

            <div className="card">
                <DataTable
                    columns={columns}
                    data={risks.data}
                    emptyMessage="No risks recorded."
                    onRowClick={(row) => router.visit(route('risks.show', row.id))}
                />
                <Pagination paginator={risks} />
            </div>

            <Modal show={showCreate} onClose={() => setShowCreate(false)} title="New risk">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(route('risks.store'), { onSuccess: () => setShowCreate(false) });
                    }}
                    className="space-y-4"
                >
                    <div>
                        <InputLabel value="Title" required />
                        <TextInput value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                        <InputError message={form.errors.title} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Description" />
                        <TextArea value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                    </div>
                    <div className="grid grid-cols-3 gap-4">
                        <div>
                            <InputLabel value="Category" />
                            <TextInput value={form.data.category} onChange={(e) => form.setData('category', e.target.value)} />
                        </div>
                        <div>
                            <InputLabel value="Likelihood (1–5)" required />
                            <SelectInput value={form.data.inherent_likelihood} onChange={(e) => form.setData('inherent_likelihood', Number(e.target.value))}>
                                {[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n}</option>)}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Impact (1–5)" required />
                            <SelectInput value={form.data.inherent_impact} onChange={(e) => form.setData('inherent_impact', Number(e.target.value))}>
                                {[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n}</option>)}
                            </SelectInput>
                        </div>
                    </div>
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={() => setShowCreate(false)}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={form.processing}>Create risk</PrimaryButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
