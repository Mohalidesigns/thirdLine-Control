import ConfirmDialog from '@/Components/ConfirmDialog';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import StatusBadge from '@/Components/StatusBadge';
import TextArea from '@/Components/TextArea';
import VersionDiff from '@/Components/VersionDiff';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Show({ document, can = {} }) {
    const [action, setAction] = useState(null); // 'submit' | 'approve' | 'publish'
    const [showReject, setShowReject] = useState(false);
    const [showNewVersion, setShowNewVersion] = useState(false);

    return (
        <AuthenticatedLayout header={document.title}>
            <Head title={document.reference} />

            <PageHeader
                title={document.title}
                subtitle={`${document.reference} · v${document.version_no} · ${document.document_type}${document.is_confidential ? ' · Confidential' : ''}`}
                breadcrumbs={[{ label: 'Documents', href: route('documents.index') }, { label: document.reference }]}
                actions={
                    <div className="flex items-center gap-2">
                        <StatusBadge status={document.status} />
                        {can.download && document.file_path && (
                            <a href={route('documents.download', document.id)} className="btn-secondary">Download</a>
                        )}
                        {can.update && (
                            <SecondaryButton onClick={() => setShowNewVersion(true)}>New version</SecondaryButton>
                        )}
                        {can.submit && (
                            <button type="button" className="btn-primary" onClick={() => setAction('submit')}>Submit for approval</button>
                        )}
                        {can.approve && (
                            <>
                                <button type="button" className="btn-success" onClick={() => setAction('approve')}>Approve</button>
                                <button type="button" className="btn-danger" onClick={() => setShowReject(true)}>Reject</button>
                            </>
                        )}
                        {can.publish && (
                            <button type="button" className="btn-primary" onClick={() => setAction('publish')}>Publish</button>
                        )}
                    </div>
                }
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    {document.description && (
                        <div className="card">
                            <div className="card-header"><h3 className="text-sm font-semibold">Description</h3></div>
                            <div className="card-body whitespace-pre-wrap text-sm text-[var(--color-text-primary)]">{document.description}</div>
                        </div>
                    )}

                    <div className="card">
                        <div className="card-header"><h3 className="text-sm font-semibold">Version history</h3></div>
                        <div className="card-body">
                            <table className="data-table">
                                <thead>
                                    <tr><th>Version</th><th>Change</th><th>By</th><th>Approved</th><th>Date</th></tr>
                                </thead>
                                <tbody>
                                    {document.versions.map((v) => (
                                        <tr key={v.id}>
                                            <td className="font-mono text-xs">v{v.version_no}</td>
                                            <td className="text-sm">{v.change_summary ?? '—'}</td>
                                            <td className="text-sm">{v.creator?.name ?? '—'}</td>
                                            <td className="text-sm">{v.approver?.name ?? '—'}</td>
                                            <td className="text-sm">{formatDate(v.created_at)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header"><h3 className="text-sm font-semibold">Metadata changes</h3></div>
                        <div className="card-body">
                            <VersionDiff alias="document" id={document.id} />
                        </div>
                    </div>

                    {(document.supersedes || (document.superseded_by ?? []).length > 0) && (
                        <div className="card">
                            <div className="card-header"><h3 className="text-sm font-semibold">Supersession chain</h3></div>
                            <div className="card-body space-y-2 text-sm">
                                {document.supersedes && (
                                    <p>
                                        Supersedes{' '}
                                        <Link className="font-medium text-[var(--color-primary)]" href={route('documents.show', document.supersedes.id)}>
                                            {document.supersedes.reference} — {document.supersedes.title}
                                        </Link>{' '}
                                        <StatusBadge status={document.supersedes.status} />
                                    </p>
                                )}
                                {(document.superseded_by ?? []).map((d) => (
                                    <p key={d.id}>
                                        Superseded by{' '}
                                        <Link className="font-medium text-[var(--color-primary)]" href={route('documents.show', d.id)}>
                                            {d.reference} — {d.title}
                                        </Link>{' '}
                                        <StatusBadge status={d.status} />
                                    </p>
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                <div className="space-y-6">
                    <div className="card">
                        <div className="card-header"><h3 className="text-sm font-semibold">Details</h3></div>
                        <div className="card-body space-y-3 text-sm">
                            <Detail label="Folder">{document.folder?.path ?? 'Unfiled'}</Detail>
                            <Detail label="Owner">{document.owner?.name ?? '—'}</Detail>
                            <Detail label="Approver">{document.approver?.name ?? '—'}</Detail>
                            <Detail label="Approved">{document.approved_at ? formatDate(document.approved_at) : '—'}</Detail>
                            <Detail label="Published">{document.published_at ? formatDate(document.published_at) : '—'}</Detail>
                            <Detail label="Review due">{document.review_due_at ? formatDate(document.review_due_at) : '—'}</Detail>
                            <Detail label="Size">{document.size_bytes ? `${Math.round(document.size_bytes / 1024)} KB` : '—'}</Detail>
                            <Detail label="Downloads">{document.download_count}</Detail>
                            <Detail label="SHA-256"><span className="break-all font-mono text-[10px]">{document.file_hash ?? '—'}</span></Detail>
                        </div>
                    </div>
                </div>
            </div>

            <ConfirmDialog
                show={action !== null}
                title={{ submit: 'Submit for approval?', approve: 'Approve this document?', publish: 'Publish this document?' }[action]}
                message={{
                    submit: 'The document moves to Under Review; an approver other than the owner must sign off.',
                    approve: 'You confirm this version is fit to publish. The owner cannot approve their own document.',
                    publish: 'The document becomes visible to all permitted users; any named predecessor is superseded.',
                }[action]}
                variant="primary"
                onConfirm={() => router.post(route(`documents.${action}`, document.id), {}, { onFinish: () => setAction(null) })}
                onCancel={() => setAction(null)}
            />

            <RejectModal show={showReject} onClose={() => setShowReject(false)} document={document} />
            <NewVersionModal show={showNewVersion} onClose={() => setShowNewVersion(false)} document={document} />
        </AuthenticatedLayout>
    );
}

function Detail({ label, children }) {
    return (
        <div className="flex items-start justify-between gap-3">
            <span className="text-[var(--color-text-secondary)]">{label}</span>
            <span className="text-right font-medium text-[var(--color-text-primary)]">{children}</span>
        </div>
    );
}

function RejectModal({ show, onClose, document }) {
    const { data, setData, post, processing, errors } = useForm({ reason: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('documents.reject', document.id), { onSuccess: onClose });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-lg font-semibold">Reject document</h2>
                <div className="mt-4">
                    <TextArea rows={3} value={data.reason} onChange={(e) => setData('reason', e.target.value)} placeholder="Why is this being returned to draft?" />
                    {errors.reason && <p className="mt-1 text-sm text-[var(--color-error)]">{errors.reason}</p>}
                </div>
                <div className="mt-6 flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>Cancel</SecondaryButton>
                    <PrimaryButton disabled={processing}>Return to draft</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

function NewVersionModal({ show, onClose, document }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        title: document.title,
        document_type: document.document_type,
        change_summary: '',
        file: null,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('documents.update', document.id), { forceFormData: true, onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-lg font-semibold">Upload new version</h2>
                <p className="mt-1 text-sm text-[var(--color-text-secondary)]">
                    A new file bumps the version to v{document.version_no + 1} and returns the document to Draft for a fresh approval.
                </p>
                <div className="mt-4 space-y-4">
                    <div>
                        <label className="form-label">Change summary</label>
                        <TextArea rows={2} value={data.change_summary} onChange={(e) => setData('change_summary', e.target.value)} />
                    </div>
                    <div>
                        <label className="form-label">File</label>
                        <input type="file" className="mt-1 block w-full text-sm" onChange={(e) => setData('file', e.target.files[0])} />
                        {errors.file && <p className="mt-1 text-sm text-[var(--color-error)]">{errors.file}</p>}
                    </div>
                </div>
                <div className="mt-6 flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>Cancel</SecondaryButton>
                    <PrimaryButton disabled={processing}>Upload version</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
