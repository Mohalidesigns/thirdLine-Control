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
import StatusBadge from '@/Components/StatusBadge';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ changes, filters = {}, regulators = [], statuses = [], stats = {}, can = {} }) {
    const { auth } = usePage().props;
    const [logging, setLogging] = useState(false);
    const [assessing, setAssessing] = useState(null);

    const logForm = useForm({
        regulator_id: '',
        title: '',
        reference: '',
        published_at: '',
        effective_at: '',
        document_url: '',
        summary: '',
    });

    const assessForm = useForm({ impact_assessment: '' });

    const log = (event) => {
        event.preventDefault();
        logForm.post(route('regulatory-changes.store'), {
            preserveScroll: true,
            onSuccess: () => {
                setLogging(false);
                logForm.reset();
            },
        });
    };

    const assess = (event) => {
        event.preventDefault();
        assessForm.post(route('regulatory-changes.assess', assessing.id), {
            preserveScroll: true,
            onSuccess: () => {
                setAssessing(null);
                assessForm.reset();
            },
        });
    };

    const columns = [
        {
            field: 'title',
            label: 'Publication',
            render: (row) => (
                <div className="max-w-md">
                    <p className="font-medium">{row.title}</p>
                    <p className="truncate text-xs text-gray-400">
                        {row.reference ? `${row.reference} · ` : ''}
                        {row.regulator?.name} · {row.source}
                    </p>
                </div>
            ),
        },
        { field: 'published_at', label: 'Published', render: (row) => formatDate(row.published_at) },
        { field: 'effective_at', label: 'Effective', render: (row) => formatDate(row.effective_at) },
        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
        {
            field: 'assessor',
            label: 'Assessed by',
            render: (row) => row.assessor?.name ?? '—',
        },
        {
            field: 'actions',
            label: '',
            render: (row) => (
                <div className="flex flex-wrap gap-2">
                    {can.assess && row.status === 'New' && (
                        <button
                            type="button"
                            onClick={() => router.post(route('regulatory-changes.review', row.id), {}, { preserveScroll: true })}
                            className="btn-secondary text-xs"
                        >
                            Start review
                        </button>
                    )}
                    {can.assess && row.status === 'Under Review' && (
                        <button type="button" onClick={() => setAssessing(row)} className="btn-secondary text-xs">
                            Record impact
                        </button>
                    )}
                    {can.action && row.status === 'Impact Assessed' && (
                        <button
                            type="button"
                            onClick={() => router.post(route('regulatory-changes.action', row.id), {}, { preserveScroll: true })}
                            disabled={row.assessed_by === auth?.user?.id}
                            title={
                                row.assessed_by === auth?.user?.id
                                    ? 'You wrote this impact assessment — someone else must sign it off'
                                    : undefined
                            }
                            className="btn-success text-xs disabled:opacity-40"
                        >
                            Mark actioned
                        </button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <AuthenticatedLayout header="Regulatory Changes">
            <Head title="Regulatory Changes" />

            <PageHeader
                title="Regulatory Change Feed"
                subtitle="What the regulators published, what it means for us, and what we did about it"
                actions={
                    can.assess && (
                        <button type="button" onClick={() => setLogging(true)} className="btn-primary">
                            + Log a publication
                        </button>
                    )
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-4">
                <StatCard title="New" value={stats.new ?? 0} color="warning" prominent={(stats.new ?? 0) > 0} />
                <StatCard title="Under review" value={stats.underReview ?? 0} color="info" />
                <StatCard title="Impact assessed" value={stats.assessed ?? 0} color="primary" />
                <StatCard title="Actioned" value={stats.actioned ?? 0} color="success" />
            </div>

            <FilterBar
                route="regulatory-changes.index"
                resource="regulatory-changes"
                currentFilters={filters}
                searchPlaceholder="Search publications…"
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    {
                        name: 'regulator_id',
                        type: 'select',
                        label: 'Regulator',
                        options: regulators.map((r) => ({ value: r.id, label: r.name })),
                    },
                    {
                        name: 'status',
                        type: 'select',
                        label: 'Status',
                        options: statuses.map((s) => ({ value: s, label: s })),
                    },
                    {
                        name: 'source',
                        type: 'select',
                        label: 'Source',
                        options: [
                            { value: 'manual', label: 'Logged by hand' },
                            { value: 'rss', label: 'Regulator feed' },
                            { value: 'ai_parsed', label: 'AI-parsed draft' },
                        ],
                    },
                ]}
            />

            <div className="card">
                <DataTable columns={columns} data={changes.data} emptyMessage="Nothing in the feed yet." />
                <Pagination paginator={changes} />
            </div>

            <Modal show={logging} maxWidth="lg" title="Log a regulatory publication" onClose={() => setLogging(false)}>
                <form onSubmit={log} className="grid gap-4 sm:grid-cols-2">
                    <div className="sm:col-span-2">
                        <InputLabel htmlFor="title" value="Title" />
                        <TextInput
                            id="title"
                            value={logForm.data.title}
                            onChange={(e) => logForm.setData('title', e.target.value)}
                            className="mt-1 block w-full"
                            required
                        />
                    </div>

                    <div>
                        <InputLabel htmlFor="regulator_id" value="Regulator" />
                        <SelectInput
                            id="regulator_id"
                            value={logForm.data.regulator_id}
                            onChange={(e) => logForm.setData('regulator_id', e.target.value)}
                            className="mt-1 block w-full"
                            required
                        >
                            <option value="">Select…</option>
                            {regulators.map((regulator) => (
                                <option key={regulator.id} value={regulator.id}>
                                    {regulator.name}
                                </option>
                            ))}
                        </SelectInput>
                    </div>

                    <div>
                        <InputLabel htmlFor="reference" value="Reference" />
                        <TextInput
                            id="reference"
                            value={logForm.data.reference}
                            onChange={(e) => logForm.setData('reference', e.target.value)}
                            className="mt-1 block w-full"
                            placeholder="e.g. PSS/DIR/PUB/CIR/001/004"
                        />
                    </div>

                    <div>
                        <InputLabel htmlFor="published_at" value="Published" />
                        <TextInput
                            id="published_at"
                            type="date"
                            value={logForm.data.published_at}
                            onChange={(e) => logForm.setData('published_at', e.target.value)}
                            className="mt-1 block w-full"
                        />
                    </div>

                    <div>
                        <InputLabel htmlFor="effective_at" value="Effective" />
                        <TextInput
                            id="effective_at"
                            type="date"
                            value={logForm.data.effective_at}
                            onChange={(e) => logForm.setData('effective_at', e.target.value)}
                            className="mt-1 block w-full"
                        />
                    </div>

                    <div className="sm:col-span-2">
                        <InputLabel htmlFor="document_url" value="Document URL" />
                        <TextInput
                            id="document_url"
                            type="url"
                            value={logForm.data.document_url}
                            onChange={(e) => logForm.setData('document_url', e.target.value)}
                            className="mt-1 block w-full"
                        />
                    </div>

                    <div className="sm:col-span-2">
                        <InputLabel htmlFor="summary" value="Summary" />
                        <TextArea
                            id="summary"
                            value={logForm.data.summary}
                            onChange={(e) => logForm.setData('summary', e.target.value)}
                            className="mt-1 block w-full"
                            rows={3}
                        />
                    </div>

                    <div className="flex justify-end gap-2 sm:col-span-2">
                        <SecondaryButton type="button" onClick={() => setLogging(false)}>
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton disabled={logForm.processing}>Log publication</PrimaryButton>
                    </div>
                </form>
            </Modal>

            <Modal show={Boolean(assessing)} maxWidth="lg" title="Record the impact" onClose={() => setAssessing(null)}>
                <form onSubmit={assess} className="space-y-4">
                    <p className="text-sm text-[var(--color-text-secondary)]">
                        Describe what changes for us — which obligations move, which controls need amending, and by when.
                        Sign-off is a separate step by a different person.
                    </p>
                    <TextArea
                        value={assessForm.data.impact_assessment}
                        onChange={(e) => assessForm.setData('impact_assessment', e.target.value)}
                        rows={6}
                        className="block w-full"
                        placeholder="Impact assessment (minimum 20 characters)"
                        required
                    />
                    <div className="flex justify-end gap-2">
                        <SecondaryButton type="button" onClick={() => setAssessing(null)}>
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton disabled={assessForm.processing || assessForm.data.impact_assessment.trim().length < 20}>
                            Record assessment
                        </PrimaryButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
