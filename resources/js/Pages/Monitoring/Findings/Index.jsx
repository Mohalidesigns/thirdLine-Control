import FilterBar from '@/Components/FilterBar';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import RichTextEditor from '@/Components/RichTextEditor';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDateTime } from '@/utils';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { AlertCircle, CheckCircle2, CircleSlash, ShieldAlert } from 'lucide-react';

const SEVERITY_CLASSES = {
    Critical: 'badge-critical',
    High: 'badge-high',
    Medium: 'badge-medium',
    Low: 'badge-low',
};

const OUTCOMES = ['Under Review', 'Confirmed', 'False Positive', 'Remediated', 'Accepted'];

export default function Index({ findings, filters = {}, statuses = [], severities = [], rules = [], stats = {}, can = {} }) {
    const [selected, setSelected] = useState([]);
    const [reviewing, setReviewing] = useState(null);
    const [bulk, setBulk] = useState(false);
    const [expanded, setExpanded] = useState(null);

    const toggle = (id) =>
        setSelected((current) => (current.includes(id) ? current.filter((i) => i !== id) : [...current, id]));

    const toggleAll = () =>
        setSelected(selected.length === findings.data.length ? [] : findings.data.map((f) => f.id));

    return (
        <AuthenticatedLayout header="Monitoring Findings">
            <Head title="Monitoring Findings" />

            <PageHeader
                title="Monitoring findings"
                subtitle="Confirming a finding raises an exception on the control. Dismissing one feeds rule tuning — both need a written reason."
                breadcrumbs={[
                    { label: 'Continuous monitoring', href: route('monitoring.dashboard') },
                    { label: 'Findings' },
                ]}
                actions={
                    can.review &&
                    selected.length > 0 && (
                        <PrimaryButton onClick={() => setBulk(true)}>Review {selected.length} selected</PrimaryButton>
                    )
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard icon={AlertCircle} title="Open" value={stats.open ?? 0} color="warning" />
                <StatCard icon={ShieldAlert} title="Critical open" value={stats.critical ?? 0} color="danger" prominent={(stats.critical ?? 0) > 0} />
                <StatCard icon={CheckCircle2} title="Confirmed" value={stats.confirmed ?? 0} color="primary" />
                <StatCard icon={CircleSlash} title="False positives" value={stats.false_positives ?? 0} color="info" />
            </div>

            <FilterBar
                route="monitoring-findings.index"
                resource="monitoring-findings"
                currentFilters={filters}
                searchPlaceholder="Search records or reasons…"
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    { name: 'status', type: 'select', label: 'Status', options: statuses },
                    { name: 'severity', type: 'select', label: 'Severity', options: severities },
                    {
                        name: 'rule_id',
                        type: 'select',
                        label: 'Rule',
                        options: rules.map((r) => ({ value: r.id, label: `${r.rule_ref} — ${r.name}` })),
                    },
                ]}
            />

            <div className="card">
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                {can.review && (
                                    <th className="w-10">
                                        <input
                                            type="checkbox"
                                            className="rounded border-gray-300"
                                            checked={findings.data.length > 0 && selected.length === findings.data.length}
                                            onChange={toggleAll}
                                            aria-label="Select all findings on this page"
                                        />
                                    </th>
                                )}
                                <th>Record</th>
                                <th>Why it failed</th>
                                <th>Rule / control</th>
                                <th>Severity</th>
                                <th>Status</th>
                                {can.review && <th />}
                            </tr>
                        </thead>
                        <tbody>
                            {findings.data.length === 0 && (
                                <tr>
                                    <td colSpan={can.review ? 7 : 5} className="py-8 text-center text-sm text-gray-400">
                                        No findings match the filters.
                                    </td>
                                </tr>
                            )}
                            {findings.data.map((finding) => (
                                <tr key={finding.id}>
                                    {can.review && (
                                        <td>
                                            <input
                                                type="checkbox"
                                                className="rounded border-gray-300"
                                                checked={selected.includes(finding.id)}
                                                onChange={() => toggle(finding.id)}
                                                aria-label={`Select finding ${finding.record_identifier}`}
                                            />
                                        </td>
                                    )}
                                    <td>
                                        <span className="font-mono text-xs">{finding.record_identifier || '—'}</span>
                                        <br />
                                        <Link
                                            href={route('monitoring-runs.show', finding.run_id)}
                                            className="font-mono text-[10px] text-gray-400 hover:underline"
                                        >
                                            {finding.run?.run_ref}
                                        </Link>
                                    </td>
                                    <td className="max-w-md">
                                        <p className="text-sm">{finding.failure_reason}</p>
                                        <button
                                            type="button"
                                            className="mt-1 text-xs text-[var(--color-primary)] hover:underline"
                                            onClick={() => setExpanded(expanded === finding.id ? null : finding.id)}
                                        >
                                            {expanded === finding.id ? 'Hide record' : 'Show record'}
                                        </button>
                                        {expanded === finding.id && (
                                            <pre className="mt-2 max-h-48 overflow-auto rounded-lg bg-gray-50 p-2 font-mono text-xs">
                                                {JSON.stringify(finding.record_data, null, 2)}
                                            </pre>
                                        )}
                                        {finding.review_notes && (
                                            <p className="mt-1 text-xs italic text-gray-400">
                                                {finding.reviewer?.name}: {finding.review_notes}
                                            </p>
                                        )}
                                    </td>
                                    <td className="text-xs">
                                        <p className="font-mono">{finding.run?.rule?.rule_ref}</p>
                                        {finding.run?.rule?.control && (
                                            <Link
                                                href={route('controls.show', finding.run.rule.control.id)}
                                                className="text-[var(--color-primary)] hover:underline"
                                            >
                                                {finding.run.rule.control.control_ref}
                                            </Link>
                                        )}
                                    </td>
                                    <td>
                                        <span className={`badge ${SEVERITY_CLASSES[finding.severity]}`}>{finding.severity}</span>
                                    </td>
                                    <td>
                                        <StatusBadge status={finding.status} />
                                        {finding.exception && (
                                            <Link
                                                href={route('exceptions.show', finding.exception.id)}
                                                className="mt-1 block text-xs text-[var(--color-primary)] hover:underline"
                                            >
                                                {finding.exception.reference}
                                            </Link>
                                        )}
                                        {finding.reviewed_at && (
                                            <p className="text-[10px] text-gray-400">{formatDateTime(finding.reviewed_at)}</p>
                                        )}
                                    </td>
                                    {can.review && (
                                        <td>
                                            <SecondaryButton onClick={() => setReviewing(finding)}>Review</SecondaryButton>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination paginator={findings} />
            </div>

            <ReviewModal finding={reviewing} onClose={() => setReviewing(null)} />
            <BulkModal
                show={bulk}
                ids={selected}
                onClose={() => {
                    setBulk(false);
                    setSelected([]);
                }}
            />
        </AuthenticatedLayout>
    );
}

function ReviewModal({ finding, onClose }) {
    const form = useForm({ status: 'Confirmed', review_notes: '' , review_notes_rich: null});

    if (!finding) return null;

    const submit = (event) => {
        event.preventDefault();
        form.post(route('monitoring-findings.review', finding.id), { onSuccess: onClose, preserveScroll: true });
    };

    return (
        <Modal show={!!finding} onClose={onClose} maxWidth="lg" title="Review finding">
            <form onSubmit={submit} className="space-y-4">
                <div className="rounded-lg bg-gray-50 p-3 text-sm">
                    <p className="font-mono text-xs text-gray-400">{finding.record_identifier}</p>
                    <p className="mt-1">{finding.failure_reason}</p>
                </div>

                <div>
                    <InputLabel value="Outcome" required />
                    <SelectInput value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                        {OUTCOMES.map((outcome) => (
                            <option key={outcome} value={outcome}>
                                {outcome}
                            </option>
                        ))}
                    </SelectInput>
                    {form.data.status === 'Confirmed' && (
                        <p className="mt-1 text-xs text-[var(--color-warning)]">
                            Confirming raises an exception against the linked control.
                        </p>
                    )}
                </div>

                <div>
                    <InputLabel
                        value="Notes"
                        required={['False Positive', 'Accepted'].includes(form.data.status)}
                    />
                    <RichTextEditor
                        value={form.data.review_notes_rich ?? form.data.review_notes}
                        onChange={(doc, plain) => form.setData((d) => ({ ...d, review_notes: plain, review_notes_rich: doc }))}
                        tools="minimal"
                        minHeight={104}
                    />
                    {form.errors.review_notes && (
                        <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.review_notes}</p>
                    )}
                </div>

                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Record</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

function BulkModal({ show, ids, onClose }) {
    const form = useForm({ finding_ids: [], status: 'False Positive', review_notes: '' , review_notes_rich: null});

    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, finding_ids: ids }));
        form.post(route('monitoring-findings.bulk-review'), { onSuccess: onClose, preserveScroll: true });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="lg" title={`Review ${ids.length} finding(s)`}>
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel value="Outcome" required />
                    <SelectInput value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                        {['Under Review', 'Confirmed', 'False Positive', 'Accepted'].map((outcome) => (
                            <option key={outcome} value={outcome}>
                                {outcome}
                            </option>
                        ))}
                    </SelectInput>
                </div>
                <div>
                    <InputLabel value="Notes" required={['False Positive', 'Accepted'].includes(form.data.status)} />
                    <RichTextEditor
                        value={form.data.review_notes_rich ?? form.data.review_notes}
                        onChange={(doc, plain) => form.setData((d) => ({ ...d, review_notes: plain, review_notes_rich: doc }))}
                        tools="minimal"
                        minHeight={104}
                    />
                    {form.errors.review_notes && (
                        <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.review_notes}</p>
                    )}
                </div>
                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Apply to {ids.length}</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
