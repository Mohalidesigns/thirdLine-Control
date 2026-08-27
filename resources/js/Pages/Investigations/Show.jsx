import DataTable from '@/Components/DataTable';
import EvidencePanel from '@/Components/EvidencePanel';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import RelationshipsPanel from '@/Components/RelationshipsPanel';
import RichTextEditor from '@/Components/RichTextEditor';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import SeverityBadge from '@/Components/SeverityBadge';
import StatusBadge from '@/Components/StatusBadge';
import TabBar from '@/Components/TabBar';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatCurrency, formatDate, formatDateTime } from '@/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertOctagon, Archive, ClipboardList, EyeOff, FileText, Gavel, History,
    Lock, Paperclip, Scale, ShieldAlert, Users,
} from 'lucide-react';
import { useState } from 'react';

const humanise = (value) => (value ? String(value).replace(/_/g, ' ') : '—');

/**
 * CR-04 — one investigation.
 *
 * Seven tabs, because an investigation is seven different questions:
 * what happened, who it is about, what we established, what we did about
 * it, what we hold, what we did when, and what we published.
 */
export default function Show({
    investigation = {},
    origin = null,
    links = [],
    reports = [],
    reportVersions = [],
    hasReport = false,
    options = {},
    users = [],
    manualActivityTypes = [],
    can = {},
}) {
    const [tab, setTab] = useState('overview');
    const [dialog, setDialog] = useState(null);

    // Investigations hold money in MAJOR units (incidents hold minor) —
    // formatCurrency, never formatMoney, or every figure reads 100x low.
    const money = (value) => (value == null ? '—' : formatCurrency(Number(value), investigation.currency ?? 'NGN'));
    const close = () => setDialog(null);
    const submitTo = (form, routeName, params, extra = {}) => (e) => {
        e.preventDefault();
        form.post(route(routeName, params), { preserveScroll: true, onSuccess: () => { form.reset(); close(); }, ...extra });
    };

    const statusForm = useForm({ status: '', note: '' });
    const completeForm = useForm({ risk_rating: 'Moderate', completed_date: '', conclusion: '', confirmed_financial_loss: '' });
    const teamForm = useForm({ user_id: '', role: 'investigator', notes: '' });
    const subjectForm = useForm({
        subject_type: 'staff', name: '', user_id: '', staff_id: '', account_number: '',
        department: '', position: '', role_in_case: 'primary_subject', notes: '',
    });
    // The subject endpoint takes the whole subject, so the outcome dialog
    // carries the record it is editing rather than a fragment of it.
    const outcomeForm = useForm({
        subject_type: 'staff', name: '', user_id: '', staff_id: '', account_number: '',
        department: '', position: '', role_in_case: 'primary_subject', notes: '',
        outcome: 'exonerated', outcome_rationale: '',
    
        outcome_rationale_rich: null,
});

    const openOutcome = (subject) => {
        outcomeForm.setData({
            subject_type: subject.subject_type,
            name: subject.name,
            user_id: subject.user_id ?? '',
            staff_id: subject.staff_id ?? '',
            account_number: subject.account_number ?? '',
            department: subject.department ?? '',
            position: subject.position ?? '',
            role_in_case: subject.role_in_case,
            notes: subject.notes ?? '',
            outcome: subject.outcome === 'pending' ? 'exonerated' : subject.outcome,
            outcome_rationale: subject.outcome_rationale ?? '',
        
            outcome_rationale_rich: subject.outcome_rationale_rich ?? null,
});
        setDialog(`outcome:${subject.id}`);
    };
    const findingForm = useForm({
        title: '', description: '', severity: 'Moderate', root_cause: '',
        control_failure: '', recommendation: '', financial_impact: '', control_id: '',
    });
    const consequenceForm = useForm({ action_type: 'query_issued', investigation_subject_id: '', description: '', due_date: '' });
    const decisionForm = useForm({ action: 'approve', rejection_reason: '', amount_recovered: '', implementation_note: '' , implementation_note_rich: null, rejection_reason_rich: null});
    const activityForm = useForm({ activity_type: 'comment', title: '', description: '', activity_date: '' });
    const archiveForm = useForm({ archive_reason: '' , archive_reason_rich: null});
    const improvementForm = useForm({ title: '', owner_id: '', due_at: '', priority: 'High' });

    const nextStatuses = (options.transitions ?? {})[investigation.status] ?? [];

    return (
        <AuthenticatedLayout header={investigation.reference}>
            <Head title={investigation.reference ?? 'Investigation'} />

            <PageHeader
                title={investigation.title}
                subtitle={`${investigation.reference} · ${humanise(investigation.category)} · ${humanise(investigation.source)}`}
                icon={Gavel}
                breadcrumbs={[{ label: 'Investigations', href: route('investigations.index') }, { label: investigation.reference }]}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <SeverityBadge severity={investigation.priority} />
                        <StatusBadge status={humanise(investigation.status)} />
                        {investigation.risk_rating && <StatusBadge status={investigation.risk_rating} />}
                        {can.update && nextStatuses.length > 0 && (
                            <SecondaryButton type="button" onClick={() => { statusForm.setData('status', nextStatuses[0]); setDialog('status'); }}>
                                Move status
                            </SecondaryButton>
                        )}
                        {can.complete && (
                            <PrimaryButton type="button" onClick={() => setDialog('complete')}>Complete</PrimaryButton>
                        )}
                        {can.archive && !investigation.is_archived && (
                            <SecondaryButton type="button" onClick={() => setDialog('archive')}>
                                <Archive className="me-1.5 h-4 w-4" aria-hidden="true" />
                                Archive
                            </SecondaryButton>
                        )}
                        {can.unarchive && investigation.is_archived && (
                            <SecondaryButton type="button" onClick={() => router.post(route('investigations.unarchive', investigation.id), {}, { preserveScroll: true })}>
                                Restore
                            </SecondaryButton>
                        )}
                        {can.update && (
                            <Link href={route('investigations.edit', investigation.id)} className="btn-secondary">Edit</Link>
                        )}
                    </div>
                }
            />

            {investigation.is_confidential && (
                <div className="mb-5 flex items-start gap-3 rounded-lg border-l-4 border-l-[var(--color-error)] bg-red-50 p-4 text-sm">
                    <EyeOff className="mt-0.5 h-5 w-5 shrink-0 text-red-600" aria-hidden="true" />
                    <div>
                        <p className="flex items-center gap-1.5 font-semibold text-[var(--color-text-primary)]">
                            Confidential investigation
                            {investigation.confidentiality_locked && <Lock className="h-3.5 w-3.5" aria-hidden="true" />}
                        </p>
                        <p className="mt-1 text-[var(--color-text-secondary)]">
                            This file is visible only to its lead, its team and the named confidential authority — the
                            general oversight permission does not reach it. Your opening of it has been recorded on the
                            diary below and on the audit trail.
                            {investigation.confidentiality_locked &&
                                ' Confidentiality was inherited from the Speak Up report this was raised from and cannot be lowered here.'}
                        </p>
                    </div>
                </div>
            )}

            {investigation.has_sod_conflict && (
                <div className="mb-5 flex items-start gap-3 rounded-lg border-l-4 border-l-[var(--color-accent)] bg-amber-50 p-4 text-sm">
                    <ShieldAlert className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" aria-hidden="true" />
                    <div>
                        <p className="font-semibold text-[var(--color-text-primary)]">Segregation of duties</p>
                        <p className="mt-1 text-[var(--color-text-secondary)]">{investigation.sod_conflict_note}</p>
                        <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                            Recorded, not blocked — in a small branch it is sometimes unavoidable. It prints on the report cover.
                        </p>
                    </div>
                </div>
            )}

            {origin && (
                <div className="mb-5 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm">
                    <p className="font-semibold text-[var(--color-text-primary)]">Raised from a {origin.label?.toLowerCase()}</p>
                    <p className="mt-1 text-[var(--color-text-secondary)]">
                        {origin.available ? `${origin.reference ?? ''} ${origin.title}`.trim() : 'That record is not visible to you.'}
                    </p>
                </div>
            )}

            {investigation.is_archived && (
                <div className="mb-5 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm">
                    <p className="font-semibold text-[var(--color-text-primary)]">Archived</p>
                    <p className="mt-1 text-[var(--color-text-secondary)]">{investigation.archive_reason}</p>
                </div>
            )}

            <TabBar
                tabs={[
                    { name: 'overview', label: 'Overview', icon: ClipboardList },
                    { name: 'subjects', label: 'Subjects', icon: Users, count: investigation.subjects?.length ?? 0 },
                    { name: 'findings', label: 'Findings', icon: AlertOctagon, count: investigation.findings?.length ?? 0 },
                    { name: 'consequences', label: 'Consequences', icon: Scale, count: investigation.consequence_actions?.length ?? 0 },
                    { name: 'evidence', label: 'Evidence', icon: Paperclip, count: investigation.evidence?.length ?? 0 },
                    { name: 'diary', label: 'Diary', icon: History, count: investigation.activities?.length ?? 0 },
                    { name: 'report', label: 'Report', icon: FileText, count: reports?.length ?? 0 },
                ]}
                active={tab}
                onChange={setTab}
            />

            {tab === 'overview' && (
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                    <div className="space-y-5 lg:col-span-2">
                        <div className="card">
                            <div className="card-header"><h3 className="text-sm font-semibold">What is alleged</h3></div>
                            <div className="card-body">
                                <p className="whitespace-pre-line text-sm">{investigation.description || '—'}</p>
                            </div>
                        </div>

                        {['background', 'scope', 'objectives', 'methodology', 'conclusion'].some((f) => investigation[f]) && (
                            <div className="card">
                                <div className="card-header"><h3 className="text-sm font-semibold">Report narrative</h3></div>
                                <div className="card-body space-y-4">
                                    {['background', 'scope', 'objectives', 'methodology', 'conclusion'].map((field) => investigation[field] && (
                                        <div key={field}>
                                            <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">{field}</p>
                                            <p className="mt-1 whitespace-pre-line text-sm">{investigation[field]}</p>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        <div className="card">
                            <div className="card-header">
                                <h3 className="text-sm font-semibold">Team ({investigation.team_members?.length ?? 0})</h3>
                                {can.assign && (
                                    <button type="button" className="text-sm text-[var(--color-primary)] hover:underline" onClick={() => setDialog('team')}>
                                        Add member
                                    </button>
                                )}
                            </div>
                            <DataTable
                                columns={[
                                    { field: 'name', label: 'Name', render: (row) => row.user?.name ?? '—' },
                                    { field: 'role', label: 'Role', width: '12rem', render: (row) => humanise(row.role) },
                                    { field: 'assigned_at', label: 'Assigned', width: '12rem', render: (row) => formatDateTime(row.assigned_at) },
                                    {
                                        field: 'actions',
                                        label: '',
                                        width: '5rem',
                                        render: (row) => can.assign && row.user_id !== investigation.lead_investigator_id && (
                                            <button
                                                type="button"
                                                className="text-xs text-[var(--color-error)] hover:underline"
                                                onClick={() => router.delete(route('investigations.team.destroy', [investigation.id, row.id]), { preserveScroll: true })}
                                            >
                                                Remove
                                            </button>
                                        ),
                                    },
                                ]}
                                data={investigation.team_members ?? []}
                                emptyMessage="Nobody is assigned to this investigation."
                            />
                        </div>
                    </div>

                    <div className="space-y-5">
                        <div className="card">
                            <div className="card-header"><h3 className="text-sm font-semibold">Financial position</h3></div>
                            <div className="card-body space-y-2 text-sm">
                                <Row label="Estimated impact" value={money(investigation.estimated_financial_impact)} />
                                <Row label="Confirmed loss" value={money(investigation.confirmed_financial_loss)} />
                                <Row label="Recovered" value={money(investigation.amount_recovered)} />
                                <Row
                                    label="Net loss"
                                    value={money((Number(investigation.confirmed_financial_loss ?? 0) - Number(investigation.amount_recovered ?? 0)).toFixed(2))}
                                />
                                <p className="pt-1 text-xs text-[var(--color-text-secondary)]">
                                    Recovered is the sum of what implemented consequences actually recovered — it is derived, not typed.
                                </p>
                            </div>
                        </div>

                        <div className="card">
                            <div className="card-header"><h3 className="text-sm font-semibold">Key dates</h3></div>
                            <div className="card-body space-y-2 text-sm">
                                <Row label="Reported" value={formatDate(investigation.reported_date)} />
                                <Row label="Commenced" value={formatDate(investigation.commenced_date)} />
                                <Row label="Target" value={formatDate(investigation.target_completion_date)} />
                                <Row label="Completed" value={formatDate(investigation.completed_date)} />
                                <Row label="Closed" value={formatDate(investigation.closed_date)} />
                                <Row label="Lead" value={investigation.lead_investigator?.name ?? '—'} />
                                <Row label="Control entity" value={investigation.control_entity?.name ?? '—'} />
                                <Row label="Department" value={investigation.organisation_unit?.name ?? '—'} />
                            </div>
                        </div>

                        <RelationshipsPanel type="investigation" id={investigation.id} links={links} canLink={can.update} />
                    </div>
                </div>
            )}

            {tab === 'subjects' && (
                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold">Subjects</h3>
                        {can.update && (
                            <button type="button" className="text-sm text-[var(--color-primary)] hover:underline" onClick={() => setDialog('subject')}>
                                Name a subject
                            </button>
                        )}
                    </div>
                    <div className="border-b border-gray-100 px-5 py-3 text-xs text-[var(--color-text-secondary)]">
                        Identifying details on this tab are the most sensitive the platform holds. They never appear in a
                        dashboard aggregate or a board extract, and an investigation cannot be completed while anyone here
                        is still marked pending.
                    </div>
                    <DataTable
                        columns={[
                            { field: 'name', label: 'Name' },
                            { field: 'subject_type', label: 'Type', width: '9rem', render: (row) => humanise(row.subject_type) },
                            { field: 'role_in_case', label: 'Role', width: '11rem', render: (row) => humanise(row.role_in_case) },
                            { field: 'department', label: 'Department', width: '12rem', render: (row) => row.department ?? row.organisation_unit?.name ?? '—' },
                            {
                                field: 'outcome',
                                label: 'Outcome',
                                width: '11rem',
                                render: (row) => (row.outcome === 'pending'
                                    ? <span className="badge badge-status-pending">Pending</span>
                                    : <StatusBadge status={humanise(row.outcome)} />),
                            },
                            {
                                field: 'actions',
                                label: '',
                                width: '8rem',
                                render: (row) => can.update && (
                                    <button
                                        type="button"
                                        className="text-xs text-[var(--color-primary)] hover:underline"
                                        onClick={() => openOutcome(row)}
                                    >
                                        Record outcome
                                    </button>
                                ),
                            },
                        ]}
                        data={investigation.subjects ?? []}
                        emptyMessage="No subject has been named."
                    />
                </div>
            )}

            {tab === 'findings' && (
                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold">Findings of fact</h3>
                        {can.update && (
                            <button type="button" className="text-sm text-[var(--color-primary)] hover:underline" onClick={() => setDialog('finding')}>
                                Record a finding
                            </button>
                        )}
                    </div>
                    <DataTable
                        columns={[
                            { field: 'reference', label: 'Ref', width: '9rem', render: (row) => <span className="font-mono text-xs">{row.reference}</span> },
                            { field: 'title', label: 'Finding' },
                            { field: 'severity', label: 'Severity', width: '8rem', render: (row) => <SeverityBadge severity={row.severity} /> },
                            { field: 'control', label: 'Control that failed', width: '12rem', render: (row) => row.control?.control_ref ?? '—' },
                            {
                                field: 'improvement',
                                label: 'Remediation',
                                width: '13rem',
                                render: (row) => (row.improvement_action
                                    ? <span className="font-mono text-xs">{row.improvement_action.reference}</span>
                                    : can.update
                                        ? (
                                            <button
                                                type="button"
                                                className="text-xs text-[var(--color-primary)] hover:underline"
                                                onClick={() => { improvementForm.setData('title', `Remediate: ${row.title}`); setDialog(`improvement:${row.id}`); }}
                                            >
                                                Raise action
                                            </button>
                                        )
                                        : <span className="text-gray-400">Not raised</span>),
                            },
                        ]}
                        data={investigation.findings ?? []}
                        emptyMessage="No finding has been recorded."
                    />
                    <div className="border-t border-gray-100 px-5 py-3 text-xs text-[var(--color-text-secondary)]">
                        A High or Critical finding must have a tracked improvement action before this investigation can be closed.
                    </div>
                </div>
            )}

            {tab === 'consequences' && (
                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold">Consequence management</h3>
                        {can.consequences && (
                            <button type="button" className="text-sm text-[var(--color-primary)] hover:underline" onClick={() => setDialog('consequence')}>
                                Recommend a consequence
                            </button>
                        )}
                    </div>
                    <DataTable
                        columns={[
                            { field: 'reference', label: 'Ref', width: '9rem', render: (row) => <span className="font-mono text-xs">{row.reference}</span> },
                            { field: 'action_type', label: 'Action', render: (row) => humanise(row.action_type) },
                            { field: 'subject', label: 'Subject', width: '12rem', render: (row) => row.subject?.name ?? '—' },
                            { field: 'status', label: 'Status', width: '10rem', render: (row) => <StatusBadge status={humanise(row.status)} /> },
                            { field: 'recommended_by', label: 'Recommended by', width: '11rem', render: (row) => row.recommender?.name ?? '—' },
                            { field: 'approved_by', label: 'Approved by', width: '11rem', render: (row) => row.approver?.name ?? '—' },
                            {
                                field: 'actions',
                                label: '',
                                width: '8rem',
                                render: (row) => can.consequences && row.status !== 'implemented' && row.status !== 'rejected' && (
                                    <button
                                        type="button"
                                        className="text-xs text-[var(--color-primary)] hover:underline"
                                        onClick={() => { decisionForm.setData('action', row.status === 'recommended' ? 'approve' : 'implement'); setDialog(`decision:${row.id}`); }}
                                    >
                                        Decide
                                    </button>
                                ),
                            },
                        ]}
                        data={investigation.consequence_actions ?? []}
                        emptyMessage="No consequence has been recommended."
                    />
                    <div className="border-t border-gray-100 px-5 py-3 text-xs text-[var(--color-text-secondary)]">
                        A consequence is never approved by the person who recommended it, and a rejection always carries a reason.
                    </div>
                </div>
            )}

            {tab === 'evidence' && (
                <EvidencePanel
                    linkedType="investigation"
                    linkedId={investigation.id}
                    evidence={investigation.evidence ?? []}
                    canUpload={can.update}
                />
            )}

            {tab === 'diary' && (
                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold">Case diary</h3>
                        {can.update && (
                            <button type="button" className="text-sm text-[var(--color-primary)] hover:underline" onClick={() => setDialog('activity')}>
                                Log an entry
                            </button>
                        )}
                    </div>
                    <DataTable
                        columns={[
                            { field: 'activity_date', label: 'When', width: '13rem', render: (row) => formatDateTime(row.activity_date) },
                            { field: 'activity_type', label: 'Type', width: '12rem', render: (row) => humanise(row.activity_type) },
                            {
                                field: 'title',
                                label: 'What happened',
                                render: (row) => (
                                    <div>
                                        <p className="text-[var(--color-text-primary)]">{row.title}</p>
                                        {row.description && <p className="text-xs text-gray-400">{row.description}</p>}
                                    </div>
                                ),
                            },
                            { field: 'performer', label: 'By', width: '11rem', render: (row) => row.performer?.name ?? 'System' },
                        ]}
                        data={investigation.activities ?? []}
                        emptyMessage="Nothing on the diary yet."
                    />
                </div>
            )}

            {tab === 'report' && (
                <>
                {/* Spec §5.3 — the document under review, above the renders of it. */}
                {reportVersions.length > 0 && (
                    <div className="card mb-6">
                        <div className="card-header">
                            <h3 className="text-sm font-semibold">Report versions &amp; review</h3>
                        </div>
                        <DataTable
                            columns={[
                                {
                                    field: 'report_number',
                                    label: 'Report',
                                    width: '13rem',
                                    render: (row) => (
                                        <Link
                                            href={route('investigations.reports.show', [investigation.id, row.id])}
                                            className="font-mono text-xs font-semibold text-[var(--color-primary)] hover:underline"
                                        >
                                            {row.report_number}
                                        </Link>
                                    ),
                                },
                                {
                                    field: 'workflow_state',
                                    label: 'Stage',
                                    width: '14rem',
                                    render: (row) => <StatusBadge status={humanise(row.workflow_state)} />,
                                },
                                { field: 'prepared_by', label: 'Prepared by', width: '12rem', render: (row) => row.prepared_by?.name ?? '—' },
                                {
                                    field: 'issue_date',
                                    label: 'Issued',
                                    render: (row) => (row.issue_date ? formatDate(row.issue_date) : '—'),
                                },
                            ]}
                            data={reportVersions}
                            emptyMessage="No report yet."
                        />
                        <div className="border-t border-gray-100 px-5 py-3 text-xs text-[var(--color-text-secondary)]">
                            An issued report is fixed. Later changes to the case produce a new version rather than
                            altering the one that was signed.
                        </div>
                    </div>
                )}

                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold">Generated documents</h3>
                        {can.report && !hasReport && (
                            <button
                                type="button"
                                className="text-sm text-[var(--color-primary)] hover:underline"
                                onClick={() => router.post(route('investigations.report.generate', investigation.id), {}, { preserveScroll: true })}
                            >
                                Generate draft
                            </button>
                        )}
                    </div>
                    <DataTable
                        columns={[
                            { field: 'run_ref', label: 'Run', width: '11rem', render: (row) => <span className="font-mono text-xs">{row.run_ref}</span> },
                            { field: 'output_format', label: 'Format', width: '7rem' },
                            { field: 'status', label: 'Status', width: '9rem', render: (row) => <StatusBadge status={row.status} /> },
                            { field: 'requester', label: 'Generated by', width: '12rem', render: (row) => row.requester?.name ?? '—' },
                            { field: 'created_at', label: 'Generated', render: (row) => formatDateTime(row.created_at) },
                        ]}
                        data={reports ?? []}
                        emptyMessage="No report has been generated. One is produced automatically when the investigation is completed."
                    />
                    <div className="border-t border-gray-100 px-5 py-3 text-xs text-[var(--color-text-secondary)]">
                        The report is always a draft: thirteen sections, nine of them generated from the record itself.
                        Regeneration is blocked once a run exists — issuing a new version is a deliberate act.
                    </div>
                </div>
                </>
            )}

            {/* ── Dialogs ────────────────────────────────────────────── */}

            <Modal show={dialog === 'status'} onClose={close} maxWidth="lg">
                <form onSubmit={submitTo(statusForm, 'investigations.status', investigation.id)} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold">Move status</h2>
                    <div>
                        <InputLabel value="New status" />
                        <SelectInput className="mt-1 block w-full capitalize" value={statusForm.data.status} onChange={(e) => statusForm.setData('status', e.target.value)}>
                            {nextStatuses.map((status) => <option key={status} value={status}>{humanise(status)}</option>)}
                        </SelectInput>
                        <InputError message={statusForm.errors.status} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Note (goes on the diary)" />
                        <TextArea rows={3} className="mt-1 block w-full" value={statusForm.data.note} onChange={(e) => statusForm.setData('note', e.target.value)} />
                    </div>
                    <Actions onCancel={close} processing={statusForm.processing} label="Move" />
                </form>
            </Modal>

            <Modal show={dialog === 'complete'} onClose={close} maxWidth="lg">
                <form onSubmit={submitTo(completeForm, 'investigations.complete', investigation.id)} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold">Complete the investigation</h2>
                    <p className="text-sm text-[var(--color-text-secondary)]">
                        Completion needs a risk rating, and every named subject must already have an outcome recorded
                        against them. A draft report is generated automatically.
                    </p>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel value="Risk rating" />
                            <SelectInput className="mt-1 block w-full" value={completeForm.data.risk_rating} onChange={(e) => completeForm.setData('risk_rating', e.target.value)}>
                                {(options.riskRatings ?? []).map((rating) => <option key={rating} value={rating}>{rating}</option>)}
                            </SelectInput>
                            <InputError message={completeForm.errors.risk_rating} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Completed on" />
                            <TextInput type="date" className="mt-1 block w-full" value={completeForm.data.completed_date} onChange={(e) => completeForm.setData('completed_date', e.target.value)} />
                        </div>
                    </div>
                    <div>
                        <InputLabel value="Confirmed financial loss" />
                        <TextInput type="number" step="0.01" min="0" className="mt-1 block w-full" value={completeForm.data.confirmed_financial_loss} onChange={(e) => completeForm.setData('confirmed_financial_loss', e.target.value)} />
                    </div>
                    <div>
                        <InputLabel value="Conclusion" />
                        <TextArea rows={4} className="mt-1 block w-full" value={completeForm.data.conclusion} onChange={(e) => completeForm.setData('conclusion', e.target.value)} />
                    </div>
                    <InputError message={completeForm.errors.subjects} className="mt-1" />
                    <Actions onCancel={close} processing={completeForm.processing} label="Complete" />
                </form>
            </Modal>

            <Modal show={dialog === 'archive'} onClose={close} maxWidth="lg">
                <form onSubmit={submitTo(archiveForm, 'investigations.archive', investigation.id)} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold">Archive the investigation</h2>
                    <p className="text-sm text-[var(--color-text-secondary)]">
                        An archived investigation drops out of every list, count and KPI. That needs a reason on the record.
                    </p>
                    <RichTextEditor
                        value={archiveForm.data.archive_reason_rich ?? archiveForm.data.archive_reason}
                        onChange={(doc, plain) => archiveForm.setData((d) => ({ ...d, archive_reason: plain, archive_reason_rich: doc }))}
                        tools="minimal"
                        minHeight={90}
                    />
                    <InputError message={archiveForm.errors.archive_reason} className="mt-1" />
                    <Actions onCancel={close} processing={archiveForm.processing} label="Archive" />
                </form>
            </Modal>

            <Modal show={dialog === 'team'} onClose={close} maxWidth="lg">
                <form onSubmit={submitTo(teamForm, 'investigations.team.store', investigation.id)} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold">Add a team member</h2>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel value="Person" />
                            <SelectInput className="mt-1 block w-full" value={teamForm.data.user_id} onChange={(e) => teamForm.setData('user_id', e.target.value)}>
                                <option value="">—</option>
                                {users.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
                            </SelectInput>
                            <InputError message={teamForm.errors.user_id} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Role" />
                            <SelectInput className="mt-1 block w-full capitalize" value={teamForm.data.role} onChange={(e) => teamForm.setData('role', e.target.value)}>
                                {(options.teamRoles ?? []).map((role) => <option key={role} value={role}>{humanise(role)}</option>)}
                            </SelectInput>
                        </div>
                    </div>
                    <p className="text-xs text-[var(--color-text-secondary)]">
                        Someone named as a subject of this investigation cannot be on its team. Where the investigation was
                        raised from a Speak Up report, the person must already be on that report&apos;s allowlist.
                    </p>
                    <Actions onCancel={close} processing={teamForm.processing} label="Add" />
                </form>
            </Modal>

            <Modal show={dialog === 'subject'} onClose={close} maxWidth="2xl">
                <form onSubmit={submitTo(subjectForm, 'investigations.subjects.store', investigation.id)} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold">Name a subject</h2>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel value="Type" />
                            <SelectInput className="mt-1 block w-full capitalize" value={subjectForm.data.subject_type} onChange={(e) => subjectForm.setData('subject_type', e.target.value)}>
                                {(options.subjectTypes ?? []).map((type) => <option key={type} value={type}>{humanise(type)}</option>)}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Role in case" />
                            <SelectInput className="mt-1 block w-full capitalize" value={subjectForm.data.role_in_case} onChange={(e) => subjectForm.setData('role_in_case', e.target.value)}>
                                {(options.subjectRoles ?? []).map((role) => <option key={role} value={role}>{humanise(role)}</option>)}
                            </SelectInput>
                        </div>
                        <div className="col-span-2">
                            <InputLabel value="Name" />
                            <TextInput className="mt-1 block w-full" value={subjectForm.data.name} onChange={(e) => subjectForm.setData('name', e.target.value)} required />
                            <InputError message={subjectForm.errors.name} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Platform account (staff)" />
                            <SelectInput className="mt-1 block w-full" value={subjectForm.data.user_id} onChange={(e) => subjectForm.setData('user_id', e.target.value)}>
                                <option value="">—</option>
                                {users.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
                            </SelectInput>
                            <InputError message={subjectForm.errors.user_id} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Staff ID" />
                            <TextInput className="mt-1 block w-full" value={subjectForm.data.staff_id} onChange={(e) => subjectForm.setData('staff_id', e.target.value)} />
                        </div>
                        <div>
                            <InputLabel value="Account number" />
                            <TextInput className="mt-1 block w-full" value={subjectForm.data.account_number} onChange={(e) => subjectForm.setData('account_number', e.target.value)} />
                        </div>
                        <div>
                            <InputLabel value="Department" />
                            <TextInput className="mt-1 block w-full" value={subjectForm.data.department} onChange={(e) => subjectForm.setData('department', e.target.value)} />
                        </div>
                    </div>
                    <Actions onCancel={close} processing={subjectForm.processing} label="Name subject" />
                </form>
            </Modal>

            {(investigation.subjects ?? []).map((subject) => (
                <Modal key={subject.id} show={dialog === `outcome:${subject.id}`} onClose={close} maxWidth="lg">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            outcomeForm.put(route('investigations.subjects.update', [investigation.id, subject.id]), {
                                preserveScroll: true,
                                onSuccess: close,
                            });
                        }}
                        className="space-y-4 p-6"
                    >
                        <h2 className="text-lg font-semibold">Record an outcome for {subject.name}</h2>
                        <div>
                            <InputLabel value="Outcome" />
                            <SelectInput className="mt-1 block w-full capitalize" value={outcomeForm.data.outcome} onChange={(e) => outcomeForm.setData('outcome', e.target.value)}>
                                {(options.subjectOutcomes ?? []).filter((o) => o !== 'pending').map((outcome) => (
                                    <option key={outcome} value={outcome}>{humanise(outcome)}</option>
                                ))}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Rationale" />
                            <RichTextEditor
                                value={outcomeForm.data.outcome_rationale_rich ?? outcomeForm.data.outcome_rationale}
                                onChange={(doc, plain) => outcomeForm.setData((d) => ({ ...d, outcome_rationale: plain, outcome_rationale_rich: doc }))}
                                tools="minimal"
                                minHeight={104}
                            />
                            <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                                An outcome recorded against a named person always carries its reason — a disciplinary panel will ask for it.
                            </p>
                            <InputError message={outcomeForm.errors.outcome_rationale} className="mt-1" />
                        </div>
                        <Actions onCancel={close} processing={outcomeForm.processing} label="Record outcome" />
                    </form>
                </Modal>
            ))}

            <Modal show={dialog === 'finding'} onClose={close} maxWidth="2xl">
                <form onSubmit={submitTo(findingForm, 'investigations.findings.store', investigation.id)} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold">Record a finding of fact</h2>
                    <div>
                        <InputLabel value="Finding" />
                        <TextInput className="mt-1 block w-full" value={findingForm.data.title} onChange={(e) => findingForm.setData('title', e.target.value)} required />
                        <InputError message={findingForm.errors.title} className="mt-1" />
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel value="Severity" />
                            <SelectInput className="mt-1 block w-full" value={findingForm.data.severity} onChange={(e) => findingForm.setData('severity', e.target.value)}>
                                {(options.findingSeverities ?? []).map((s) => <option key={s} value={s}>{s}</option>)}
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel value="Control that failed" />
                            <SelectInput className="mt-1 block w-full" value={findingForm.data.control_id} onChange={(e) => findingForm.setData('control_id', e.target.value)}>
                                <option value="">—</option>
                                {(options.controls ?? []).map((control) => (
                                    <option key={control.id} value={control.id}>{control.control_ref} — {control.title}</option>
                                ))}
                            </SelectInput>
                        </div>
                    </div>
                    <div>
                        <InputLabel value="What was established" />
                        <TextArea rows={3} className="mt-1 block w-full" value={findingForm.data.description} onChange={(e) => findingForm.setData('description', e.target.value)} />
                    </div>
                    <div>
                        <InputLabel value="Root cause" />
                        <TextArea rows={2} className="mt-1 block w-full" value={findingForm.data.root_cause} onChange={(e) => findingForm.setData('root_cause', e.target.value)} />
                    </div>
                    <div>
                        <InputLabel value="Recommendation" />
                        <TextArea rows={2} className="mt-1 block w-full" value={findingForm.data.recommendation} onChange={(e) => findingForm.setData('recommendation', e.target.value)} />
                    </div>
                    <Actions onCancel={close} processing={findingForm.processing} label="Record finding" />
                </form>
            </Modal>

            {(investigation.findings ?? []).map((finding) => (
                <Modal key={finding.id} show={dialog === `improvement:${finding.id}`} onClose={close} maxWidth="lg">
                    <form onSubmit={submitTo(improvementForm, 'investigations.findings.improvement', [investigation.id, finding.id])} className="space-y-4 p-6">
                        <h2 className="text-lg font-semibold">Raise remediation for {finding.reference}</h2>
                        <div>
                            <InputLabel value="Title" />
                            <TextInput className="mt-1 block w-full" value={improvementForm.data.title} onChange={(e) => improvementForm.setData('title', e.target.value)} />
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <InputLabel value="Owner" />
                                <SelectInput className="mt-1 block w-full" value={improvementForm.data.owner_id} onChange={(e) => improvementForm.setData('owner_id', e.target.value)}>
                                    <option value="">—</option>
                                    {users.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
                                </SelectInput>
                            </div>
                            <div>
                                <InputLabel value="Due" />
                                <TextInput type="date" className="mt-1 block w-full" value={improvementForm.data.due_at} onChange={(e) => improvementForm.setData('due_at', e.target.value)} />
                            </div>
                        </div>
                        <Actions onCancel={close} processing={improvementForm.processing} label="Raise action" />
                    </form>
                </Modal>
            ))}

            <Modal show={dialog === 'consequence'} onClose={close} maxWidth="lg">
                <form onSubmit={submitTo(consequenceForm, 'investigations.consequences.store', investigation.id)} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold">Recommend a consequence</h2>
                    <div>
                        <InputLabel value="Action" />
                        <SelectInput className="mt-1 block w-full capitalize" value={consequenceForm.data.action_type} onChange={(e) => consequenceForm.setData('action_type', e.target.value)}>
                            {(options.consequenceTypes ?? []).map((type) => <option key={type} value={type}>{humanise(type)}</option>)}
                        </SelectInput>
                        <InputError message={consequenceForm.errors.action_type} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Subject" />
                        <SelectInput className="mt-1 block w-full" value={consequenceForm.data.investigation_subject_id} onChange={(e) => consequenceForm.setData('investigation_subject_id', e.target.value)}>
                            <option value="">—</option>
                            {(investigation.subjects ?? []).map((subject) => <option key={subject.id} value={subject.id}>{subject.name}</option>)}
                        </SelectInput>
                        <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                            Required for anything that bears on a person&apos;s employment.
                        </p>
                        <InputError message={consequenceForm.errors.investigation_subject_id} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Detail" />
                        <TextArea rows={3} className="mt-1 block w-full" value={consequenceForm.data.description} onChange={(e) => consequenceForm.setData('description', e.target.value)} />
                    </div>
                    <div>
                        <InputLabel value="Due" />
                        <TextInput type="date" className="mt-1 block w-full" value={consequenceForm.data.due_date} onChange={(e) => consequenceForm.setData('due_date', e.target.value)} />
                    </div>
                    <Actions onCancel={close} processing={consequenceForm.processing} label="Recommend" />
                </form>
            </Modal>

            {(investigation.consequence_actions ?? []).map((action) => (
                <Modal key={action.id} show={dialog === `decision:${action.id}`} onClose={close} maxWidth="lg">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            decisionForm.put(route('investigations.consequences.update', [investigation.id, action.id]), {
                                preserveScroll: true,
                                onSuccess: close,
                            });
                        }}
                        className="space-y-4 p-6"
                    >
                        <h2 className="text-lg font-semibold">{action.reference} — {humanise(action.action_type)}</h2>
                        <div>
                            <InputLabel value="Decision" />
                            <SelectInput className="mt-1 block w-full capitalize" value={decisionForm.data.action} onChange={(e) => decisionForm.setData('action', e.target.value)}>
                                {action.status === 'recommended' && <option value="approve">Approve</option>}
                                {action.status === 'recommended' && <option value="reject">Reject</option>}
                                {action.status === 'approved' && <option value="start">Mark in progress</option>}
                                {(action.status === 'approved' || action.status === 'in_progress') && <option value="implement">Mark implemented</option>}
                            </SelectInput>
                        </div>
                        {decisionForm.data.action === 'reject' && (
                            <div>
                                <InputLabel value="Reason" />
                                <RichTextEditor
                                    value={decisionForm.data.rejection_reason_rich ?? decisionForm.data.rejection_reason}
                                    onChange={(doc, plain) => decisionForm.setData((d) => ({ ...d, rejection_reason: plain, rejection_reason_rich: doc }))}
                                    tools="minimal"
                                    minHeight={90}
                                />
                                <InputError message={decisionForm.errors.rejection_reason} className="mt-1" />
                            </div>
                        )}
                        {decisionForm.data.action === 'implement' && (
                            <>
                                <div>
                                    <InputLabel value="Amount recovered" />
                                    <TextInput type="number" step="0.01" min="0" className="mt-1 block w-full" value={decisionForm.data.amount_recovered} onChange={(e) => decisionForm.setData('amount_recovered', e.target.value)} />
                                </div>
                                <div>
                                    <InputLabel value="Note" />
                                    <RichTextEditor
                                        value={decisionForm.data.implementation_note_rich ?? decisionForm.data.implementation_note}
                                        onChange={(doc, plain) => decisionForm.setData((d) => ({ ...d, implementation_note: plain, implementation_note_rich: doc }))}
                                        tools="minimal"
                                        minHeight={90}
                                    />
                                </div>
                            </>
                        )}
                        <InputError message={decisionForm.errors.approval} className="mt-1" />
                        <Actions onCancel={close} processing={decisionForm.processing} label="Record decision" />
                    </form>
                </Modal>
            ))}

            <Modal show={dialog === 'activity'} onClose={close} maxWidth="lg">
                <form onSubmit={submitTo(activityForm, 'investigations.activities.store', investigation.id)} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold">Log a diary entry</h2>
                    <div>
                        <InputLabel value="Type" />
                        <SelectInput className="mt-1 block w-full capitalize" value={activityForm.data.activity_type} onChange={(e) => activityForm.setData('activity_type', e.target.value)}>
                            {manualActivityTypes.map((type) => <option key={type} value={type}>{humanise(type)}</option>)}
                        </SelectInput>
                        <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                            Only these six can be logged by hand — the rest are written by the workflow itself, which is
                            what keeps the chronology worth reading.
                        </p>
                    </div>
                    <div>
                        <InputLabel value="What happened" />
                        <TextInput className="mt-1 block w-full" value={activityForm.data.title} onChange={(e) => activityForm.setData('title', e.target.value)} required />
                        <InputError message={activityForm.errors.title} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Detail" />
                        <TextArea rows={3} className="mt-1 block w-full" value={activityForm.data.description} onChange={(e) => activityForm.setData('description', e.target.value)} />
                    </div>
                    <div>
                        <InputLabel value="When" />
                        <TextInput type="datetime-local" className="mt-1 block w-full" value={activityForm.data.activity_date} onChange={(e) => activityForm.setData('activity_date', e.target.value)} />
                    </div>
                    <Actions onCancel={close} processing={activityForm.processing} label="Log entry" />
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}

function Row({ label, value }) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <span className="text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">{label}</span>
            <span className="text-right font-medium text-[var(--color-text-primary)]">{value ?? '—'}</span>
        </div>
    );
}

function Actions({ onCancel, processing, label }) {
    return (
        <div className="flex justify-end gap-2 pt-2">
            <SecondaryButton type="button" onClick={onCancel}>Cancel</SecondaryButton>
            <PrimaryButton disabled={processing}>{label}</PrimaryButton>
        </div>
    );
}
