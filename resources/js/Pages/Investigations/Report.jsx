import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import TextArea from '@/Components/TextArea';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate, formatDateTime } from '@/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Check, Download, FileText } from 'lucide-react';
import { useState } from 'react';

/**
 * Spec §5.3 — the investigation report, and where it has got to.
 *
 * The five nodes across the top are the whole point of the screen: a
 * reader should be able to tell at a glance whether what they are looking
 * at has been signed off, and by whom. An issued report is served from its
 * frozen snapshot, so this page renders whatever the server hands it in
 * `document` rather than re-deriving anything from the case.
 */

/** The workflow line. Labels are spelled out — GHIC is not a word. */
const NODES = [
    { key: 'draft', label: 'Draft' },
    { key: 'manager_review', label: 'Manager Review' },
    { key: 'ghic_review', label: 'Group Head Internal Control Review' },
    { key: 'approved', label: 'Approved' },
    { key: 'issued', label: 'Issued' },
];

function WorkflowLine({ state }) {
    const current = NODES.findIndex((node) => node.key === state);

    return (
        <ol className="flex flex-wrap items-start gap-y-4">
            {NODES.map((node, index) => {
                const done = index < current;
                const active = index === current;

                return (
                    <li key={node.key} className="flex flex-1 items-start gap-2" style={{ minWidth: '9rem' }}>
                        <div className="flex flex-1 flex-col items-center text-center">
                            <span
                                className={[
                                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                                    done ? 'bg-emerald-500 text-white' : '',
                                    active ? 'bg-[var(--color-primary)] text-white' : '',
                                    !done && !active ? 'border border-gray-300 bg-white text-gray-400' : '',
                                ].join(' ')}
                                aria-hidden="true"
                            >
                                {done ? <Check className="h-4 w-4" /> : index + 1}
                            </span>
                            <span
                                className={[
                                    'mt-2 px-1 text-xs leading-tight',
                                    done ? 'text-emerald-700' : '',
                                    active ? 'font-semibold text-[var(--color-primary)]' : '',
                                    !done && !active ? 'text-gray-400' : '',
                                ].join(' ')}
                            >
                                {node.label}
                            </span>
                        </div>
                        {index < NODES.length - 1 && (
                            <span
                                className={`mt-4 hidden h-px flex-1 sm:block ${done ? 'bg-emerald-500' : 'bg-gray-200'}`}
                                aria-hidden="true"
                            />
                        )}
                    </li>
                );
            })}
        </ol>
    );
}

/** One assembled section, in whichever of the engine's shapes it arrived. */
function Section({ section }) {
    return (
        <div className="card">
            <div className="card-header">
                <h3 className="text-sm font-semibold">{section.title}</h3>
            </div>
            <div className="card-body space-y-4">
                {section.body && (
                    <div className="whitespace-pre-line text-sm text-[var(--color-text-secondary)]">{section.body}</div>
                )}

                {section.items && (
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        {section.items.map((item) => (
                            <div key={item.label}>
                                <p className="text-xs uppercase tracking-wide text-gray-400">{item.label}</p>
                                <p className="mt-1 font-semibold tabular-nums">{item.value}</p>
                                {item.caption && <p className="text-xs text-gray-400">{item.caption}</p>}
                            </div>
                        ))}
                    </div>
                )}

                {section.fields && (
                    <dl className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        {section.fields.map((field) => (
                            <div key={field.label}>
                                <dt className="text-xs uppercase tracking-wide text-gray-400">{field.label}</dt>
                                <dd className="mt-0.5 text-sm">{field.value}</dd>
                            </div>
                        ))}
                    </dl>
                )}

                {section.signatories && (
                    <dl className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        {section.signatories.map((signatory) => (
                            <div key={signatory.role}>
                                <dt className="text-xs uppercase tracking-wide text-gray-400">{signatory.role}</dt>
                                <dd className="mt-0.5 text-sm">{signatory.name ?? '—'}</dd>
                            </div>
                        ))}
                    </dl>
                )}

                {section.table?.rows?.length > 0 && (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-gray-200 text-left">
                                    {section.table.columns.map((column) => (
                                        <th key={column} className="px-2 py-2 text-xs uppercase tracking-wide text-gray-400">
                                            {column}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {section.table.rows.map((row, rowIndex) => (
                                    <tr key={rowIndex} className="border-b border-gray-100 last:border-0">
                                        {row.map((cell, cellIndex) => (
                                            <td key={cellIndex} className="px-2 py-2 align-top">
                                                {cell}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
}

export default function Report({ investigation, report, document, can = {} }) {
    const [dialog, setDialog] = useState(null);
    const close = () => setDialog(null);

    const advance = useForm({ to: '', comment: '' });
    const returnForm = useForm({ returned_reason: '' });

    const move = (to, comment = '') => {
        advance.transform(() => ({ to, comment }));
        advance.post(route('investigations.reports.advance', [investigation.id, report.id]), {
            preserveScroll: true,
            onSuccess: close,
        });
    };

    const submitReturn = (event) => {
        event.preventDefault();
        returnForm.post(route('investigations.reports.return', [investigation.id, report.id]), {
            preserveScroll: true,
            onSuccess: close,
        });
    };

    const sections = document?.sections ?? [];

    return (
        <AuthenticatedLayout header="Investigation report">
            <Head title={report.report_number} />

            <PageHeader
                title={`Investigation Report — ${investigation.title}`}
                subtitle={report.report_number}
                icon={FileText}
                actions={
                    <>
                        <Link href={route('investigations.show', investigation.id)} className="btn-secondary">
                            Back to case
                        </Link>
                        {can.submit && (
                            <button type="button" className="btn-primary" onClick={() => move('manager_review')}>
                                Submit for manager review
                            </button>
                        )}
                        {can.review && (
                            <button type="button" className="btn-primary" onClick={() => setDialog('review')}>
                                Approve &amp; send to Group Head Internal Control
                            </button>
                        )}
                        {can.approve && (
                            <button type="button" className="btn-primary" onClick={() => setDialog('approve')}>
                                Approve
                            </button>
                        )}
                        {can.issue && (
                            <button
                                type="button"
                                className="btn-primary"
                                onClick={() =>
                                    router.post(
                                        route('investigations.reports.issue', [investigation.id, report.id]),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Issue report
                            </button>
                        )}
                        {can.return && (
                            <button type="button" className="btn-secondary" onClick={() => setDialog('return')}>
                                Return to preparer
                            </button>
                        )}
                    </>
                }
            />

            <div className="card mb-6">
                <div className="card-body">
                    <WorkflowLine state={report.workflow_state} />
                </div>
            </div>

            {report.returned_reason && (
                <div className="mb-6 rounded-lg border-l-4 border-l-amber-500 bg-amber-50 p-4 text-sm">
                    <p className="font-semibold">Returned to the preparer</p>
                    <p className="mt-1 text-[var(--color-text-secondary)]">{report.returned_reason}</p>
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    {sections.map((section) => (
                        <Section key={section.key} section={section} />
                    ))}
                </div>

                <div className="space-y-6">
                    <div className="card">
                        <div className="card-header">
                            <h3 className="text-sm font-semibold">Report details</h3>
                        </div>
                        <div className="card-body space-y-3 text-sm">
                            <Detail label="Report number" value={<span className="font-mono">{report.report_number}</span>} />
                            <Detail label="Version" value={report.version} />
                            <Detail label="State" value={<StatusBadge status={report.workflow_state.replace(/_/g, ' ')} />} />
                            <Detail label="Issue date" value={report.issue_date ? formatDate(report.issue_date) : '—'} />
                        </div>
                    </div>

                    {report.workflow_state === 'issued' && (
                        <div className="card border-l-4 border-l-[var(--color-primary)]">
                            <div className="card-header">
                                <h3 className="text-sm font-semibold">Issued report</h3>
                            </div>
                            <div className="card-body space-y-3 text-sm">
                                <p className="text-[var(--color-text-secondary)]">
                                    This report has been formally issued. Its content is fixed — later changes to the case
                                    produce a new version rather than altering this one.
                                </p>
                                {can.download && report.run?.id && (
                                    <a
                                        className="btn-primary inline-flex w-full justify-center"
                                        href={route('reports.runs.download', report.run.id)}
                                    >
                                        <Download className="me-1.5 h-4 w-4" aria-hidden="true" />
                                        Download report (PDF)
                                    </a>
                                )}
                            </div>
                        </div>
                    )}

                    <div className="card">
                        <div className="card-header">
                            <h3 className="text-sm font-semibold">Investigation case</h3>
                        </div>
                        <div className="card-body space-y-3 text-sm">
                            <Detail
                                label="Case reference"
                                value={
                                    <Link
                                        href={route('investigations.show', investigation.id)}
                                        className="font-mono text-[var(--color-primary)] hover:underline"
                                    >
                                        {investigation.reference}
                                    </Link>
                                }
                            />
                            <Detail label="Case title" value={investigation.title} />
                            <Detail label="Control entity" value={investigation.control_entity?.name ?? '—'} />
                            <Detail label="Department" value={investigation.organisation_unit?.name ?? '—'} />
                            <Detail label="Lead investigator" value={investigation.lead_investigator?.name ?? '—'} />
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">
                            <h3 className="text-sm font-semibold">People &amp; review trail</h3>
                        </div>
                        <div className="card-body space-y-4 text-sm">
                            <TrailEntry label="Prepared by" name={report.prepared_by?.name} />
                            <TrailEntry
                                label="Manager review"
                                name={report.manager_reviewed_by?.name}
                                at={report.manager_reviewed_at}
                                comment={report.manager_comment}
                            />
                            <TrailEntry
                                label="Reviewed by (Group Head Internal Control)"
                                name={report.ghic_reviewed_by?.name}
                                at={report.ghic_reviewed_at}
                                comment={report.ghic_comment}
                            />
                            <TrailEntry label="Approved by" name={report.approved_by?.name} at={report.approved_at} />
                        </div>
                    </div>
                </div>
            </div>

            {/* ── Dialogs ────────────────────────────────────────────── */}

            <Modal show={dialog === 'review' || dialog === 'approve'} onClose={close} maxWidth="lg">
                <div className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold">
                        {dialog === 'review' ? 'Approve and send to Group Head Internal Control' : 'Approve the report'}
                    </h2>
                    <div>
                        <InputLabel htmlFor="comment" value="Comment (optional)" />
                        <TextArea
                            id="comment"
                            className="mt-1 block w-full"
                            rows={3}
                            value={advance.data.comment}
                            onChange={(event) => advance.setData('comment', event.target.value)}
                        />
                        <InputError message={advance.errors.workflow_state} className="mt-2" />
                    </div>
                    <div className="flex justify-end gap-2">
                        <button type="button" className="btn-secondary" onClick={close}>
                            Cancel
                        </button>
                        <button
                            type="button"
                            className="btn-primary"
                            disabled={advance.processing}
                            onClick={() => move(dialog === 'review' ? 'ghic_review' : 'approved', advance.data.comment)}
                        >
                            Confirm
                        </button>
                    </div>
                </div>
            </Modal>

            <Modal show={dialog === 'return'} onClose={close} maxWidth="lg">
                <form onSubmit={submitReturn} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold">Return to the preparer</h2>
                    <p className="text-sm text-[var(--color-text-secondary)]">
                        Say what needs to change. The preparer sees this on the report and cannot act on a return without
                        it.
                    </p>
                    <div>
                        <InputLabel htmlFor="returned_reason" value="Reason" />
                        <TextArea
                            id="returned_reason"
                            className="mt-1 block w-full"
                            rows={4}
                            value={returnForm.data.returned_reason}
                            onChange={(event) => returnForm.setData('returned_reason', event.target.value)}
                            required
                        />
                        <InputError message={returnForm.errors.returned_reason} className="mt-2" />
                    </div>
                    <div className="flex justify-end gap-2">
                        <button type="button" className="btn-secondary" onClick={close}>
                            Cancel
                        </button>
                        <button type="submit" className="btn-primary" disabled={returnForm.processing}>
                            Return report
                        </button>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}

function Detail({ label, value }) {
    return (
        <div>
            <p className="text-xs uppercase tracking-wide text-gray-400">{label}</p>
            <div className="mt-0.5">{value}</div>
        </div>
    );
}

function TrailEntry({ label, name, at, comment }) {
    return (
        <div>
            <p className="text-xs uppercase tracking-wide text-gray-400">{label}</p>
            <p className="mt-0.5">{name ?? '—'}</p>
            {at && <p className="text-xs text-gray-400">{formatDateTime(at)}</p>}
            {comment && <p className="mt-1 text-xs italic text-[var(--color-text-secondary)]">“{comment}”</p>}
        </div>
    );
}
