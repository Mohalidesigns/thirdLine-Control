import DataTable from '@/Components/DataTable';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';

export default function Templates({ templates }) {
    const columns = [
        {
            field: 'control_ref',
            label: 'Ref',
            render: (row) => <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{row.control_ref}</span>,
        },
        {
            field: 'title',
            label: 'Control',
            render: (row) => (
                <div>
                    <p className="font-medium text-[var(--color-text-primary)]">{row.title}</p>
                    <p className="max-w-xl text-xs text-gray-400">{row.description}</p>
                </div>
            ),
        },
        { field: 'category', label: 'Category', render: (row) => row.category?.name ?? '—' },
        { field: 'type', label: 'Type' },
        { field: 'frequency', label: 'Frequency' },
        {
            field: 'actions',
            label: '',
            render: (row) => (
                <PrimaryButton onClick={() => router.post(route('controls.adopt', row.id))}>
                    Adopt
                </PrimaryButton>
            ),
        },
    ];

    return (
        <AuthenticatedLayout header="Starter Control Library">
            <Head title="Starter Library" />
            <PageHeader
                title="Starter control library"
                subtitle="Standard banking controls you can adopt into your own library, then edit to fit"
                breadcrumbs={[{ label: 'Control Library', href: route('controls.index') }, { label: 'Starter library' }]}
            />
            <div className="card">
                <DataTable columns={columns} data={templates.data} emptyMessage="No templates available." />
                <Pagination paginator={templates} />
            </div>
        </AuthenticatedLayout>
    );
}
