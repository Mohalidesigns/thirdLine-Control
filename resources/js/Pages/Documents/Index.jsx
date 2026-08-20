import Checkbox from '@/Components/Checkbox';
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
import { formatDate } from '@/utils';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Plus } from 'lucide-react';

export default function Index({
    documents,
    folderTree = [],
    filters = {},
    statuses = [],
    documentTypes = [],
    reviewDueCount = 0,
    can = {},
    roles = [],
    users = [],
}) {
    const [showUpload, setShowUpload] = useState(false);
    const [showFolder, setShowFolder] = useState(false);

    const columns = [
        {
            field: 'reference',
            label: 'Ref',
            render: (row) => <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{row.reference}</span>,
        },
        {
            field: 'title',
            label: 'Title',
            render: (row) => (
                <div className="max-w-sm">
                    <p className="font-medium text-[var(--color-text-primary)]">
                        {row.title}
                        {row.is_confidential && <span className="badge badge-high ml-2">Confidential</span>}
                    </p>
                    <p className="text-xs text-gray-400">{row.folder?.path ?? 'Unfiled'} · v{row.version_no}</p>
                </div>
            ),
        },
        { field: 'document_type', label: 'Type' },
        { field: 'owner', label: 'Owner', render: (row) => row.owner?.name ?? '—' },
        { field: 'review_due_at', label: 'Review due', render: (row) => (row.review_due_at ? formatDate(row.review_due_at) : '—') },
        { field: 'download_count', label: 'Downloads' },
        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
    ];

    return (
        <AuthenticatedLayout header="Documents">
            <Head title="Documents" />
            <PageHeader
                title="Document Library"
                subtitle="Policies, procedures and governing artefacts — versioned, approved maker-checker, and review-scheduled"
                actions={
                    <>
                        {can.manageFolders && (
                            <SecondaryButton onClick={() => setShowFolder(true)}><Plus className="h-4 w-4" strokeWidth={2} /> Folder</SecondaryButton>
                        )}
                        {can.create && (
                            <PrimaryButton onClick={() => setShowUpload(true)}><Plus className="h-4 w-4" strokeWidth={2} /> Add document</PrimaryButton>
                        )}
                    </>
                }
            />

            {reviewDueCount > 0 && (
                <div className="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    {reviewDueCount} document{reviewDueCount === 1 ? ' is' : 's are'} due for review within 30 days.
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-[240px_1fr]">
                <div className="card h-fit">
                    <div className="card-header"><h3 className="text-sm font-semibold">Folders</h3></div>
                    <div className="card-body">
                        <button
                            type="button"
                            onClick={() => router.get(route('documents.index'), {}, { preserveState: true })}
                            className={`block w-full rounded px-2 py-1 text-left text-sm ${!filters.folder_id ? 'bg-[var(--color-primary)]/10 font-semibold text-[var(--color-primary)]' : 'hover:bg-gray-50'}`}
                        >
                            All documents
                        </button>
                        <FolderBranch nodes={folderTree} depth={0} activeId={filters.folder_id} />
                    </div>
                </div>

                <div>
                    <div className="filter-bar mb-4">
                        <div className="filter-bar-inner">
                            <div className="filter-bar-group">
                                <input
                                    className="filter-bar-input"
                                    placeholder="Search documents…"
                                    defaultValue={filters.search ?? ''}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') router.get(route('documents.index'), { ...filters, search: e.target.value }, { preserveState: true });
                                    }}
                                />
                            </div>
                            <div className="filter-bar-group">
                                <select
                                    className="filter-bar-select"
                                    value={filters.status ?? ''}
                                    onChange={(e) => router.get(route('documents.index'), { ...filters, status: e.target.value || undefined }, { preserveState: true })}
                                >
                                    <option value="">All statuses</option>
                                    {statuses.map((s) => <option key={s} value={s}>{s}</option>)}
                                </select>
                            </div>
                            <div className="filter-bar-group">
                                <select
                                    className="filter-bar-select"
                                    value={filters.document_type ?? ''}
                                    onChange={(e) => router.get(route('documents.index'), { ...filters, document_type: e.target.value || undefined }, { preserveState: true })}
                                >
                                    <option value="">All types</option>
                                    {documentTypes.map((t) => <option key={t} value={t}>{t}</option>)}
                                </select>
                            </div>
                        </div>
                    </div>

                    <div className="card">
                        <DataTable
                            columns={columns}
                            data={documents.data}
                            emptyMessage="No documents match the current filters."
                            onRowClick={(row) => router.visit(route('documents.show', row.id))}
                        />
                        <Pagination paginator={documents} />
                    </div>
                </div>
            </div>

            <UploadModal
                show={showUpload}
                onClose={() => setShowUpload(false)}
                folderTree={folderTree}
                documentTypes={documentTypes}
                roles={roles}
                users={users}
            />
            <FolderModal show={showFolder} onClose={() => setShowFolder(false)} folderTree={folderTree} />
        </AuthenticatedLayout>
    );
}

function FolderBranch({ nodes, depth, activeId }) {
    return nodes.map((node) => (
        <div key={node.id}>
            <button
                type="button"
                onClick={() => router.get(route('documents.index'), { folder_id: node.id }, { preserveState: true })}
                className={`block w-full rounded px-2 py-1 text-left text-sm ${String(activeId) === String(node.id) ? 'bg-[var(--color-primary)]/10 font-semibold text-[var(--color-primary)]' : 'hover:bg-gray-50'}`}
                style={{ paddingLeft: `${8 + depth * 14}px` }}
            >
                📁 {node.name} <span className="text-xs text-gray-400">({node.documents_count})</span>
            </button>
            <FolderBranch nodes={node.children} depth={depth + 1} activeId={activeId} />
        </div>
    ));
}

function flattenFolders(nodes, depth = 0) {
    return nodes.flatMap((n) => [{ id: n.id, name: `${'— '.repeat(depth)}${n.name}` }, ...flattenFolders(n.children, depth + 1)]);
}

function UploadModal({ show, onClose, folderTree, documentTypes, roles, users }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        title: '',
        description: '',
        document_type: 'policy',
        folder_id: '',
        owner_id: '',
        review_due_at: '',
        is_confidential: false,
        access_role_ids: [],
        file: null,
    });

    const folders = flattenFolders(folderTree);

    const submit = (e) => {
        e.preventDefault();
        post(route('documents.store'), { forceFormData: true, onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-lg font-semibold">Add document</h2>
                <div className="mt-4 space-y-4">
                    <div>
                        <InputLabel value="Title" required />
                        <TextInput value={data.title} onChange={(e) => setData('title', e.target.value)} />
                        <InputError message={errors.title} className="mt-1" />
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Type" required />
                            <SelectInput value={data.document_type} onChange={(e) => setData('document_type', e.target.value)}>
                                {documentTypes.map((t) => <option key={t} value={t}>{t}</option>)}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Folder" />
                            <SelectInput value={data.folder_id} onChange={(e) => setData('folder_id', e.target.value)}>
                                <option value="">Unfiled</option>
                                {folders.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
                            </SelectInput>
                        </div>
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Owner" />
                            <SelectInput value={data.owner_id} onChange={(e) => setData('owner_id', e.target.value)}>
                                <option value="">Me</option>
                                {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Review due" />
                            <TextInput type="date" value={data.review_due_at} onChange={(e) => setData('review_due_at', e.target.value)} />
                            <InputError message={errors.review_due_at} className="mt-1" />
                        </div>
                    </div>
                    <div>
                        <InputLabel value="Description" />
                        <TextArea rows={2} value={data.description} onChange={(e) => setData('description', e.target.value)} />
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox checked={data.is_confidential} onChange={(e) => setData('is_confidential', e.target.checked)} />
                        Confidential — visible only to selected roles
                    </label>
                    {data.is_confidential && (
                        <div className="space-y-1 rounded-lg border border-gray-200 p-3">
                            {roles.map((r) => (
                                <label key={r.id} className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={data.access_role_ids.includes(r.id)}
                                        onChange={(e) => setData('access_role_ids', e.target.checked
                                            ? [...data.access_role_ids, r.id]
                                            : data.access_role_ids.filter((id) => id !== r.id))}
                                    />
                                    {r.name}
                                </label>
                            ))}
                            <InputError message={errors.access_role_ids} className="mt-1" />
                        </div>
                    )}
                    <div>
                        <InputLabel value="File" required />
                        <input type="file" className="mt-1 block w-full text-sm" onChange={(e) => setData('file', e.target.files[0])} />
                        <InputError message={errors.file} className="mt-1" />
                    </div>
                </div>
                <div className="mt-6 flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>Cancel</SecondaryButton>
                    <PrimaryButton disabled={processing}>Create draft</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

function FolderModal({ show, onClose, folderTree }) {
    const { data, setData, post, processing, errors, reset } = useForm({ name: '', parent_id: '', description: '' });
    const folders = flattenFolders(folderTree);

    const submit = (e) => {
        e.preventDefault();
        post(route('document-folders.store'), { onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-lg font-semibold">New folder</h2>
                <div className="mt-4 space-y-4">
                    <div>
                        <InputLabel value="Name" required />
                        <TextInput value={data.name} onChange={(e) => setData('name', e.target.value)} />
                        <InputError message={errors.name} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Parent folder" />
                        <SelectInput value={data.parent_id} onChange={(e) => setData('parent_id', e.target.value)}>
                            <option value="">Top level</option>
                            {folders.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
                        </SelectInput>
                    </div>
                </div>
                <div className="mt-6 flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>Cancel</SecondaryButton>
                    <PrimaryButton disabled={processing}>Create folder</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
