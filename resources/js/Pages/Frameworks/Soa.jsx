import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import StatCard from '@/Components/StatCard';
import TextArea from '@/Components/TextArea';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CheckCircle2, CircleSlash, Gauge } from 'lucide-react';

const STATUS_LABELS = {
    implemented: 'Implemented',
    partially_implemented: 'Partially implemented',
    planned: 'Planned',
    not_implemented: 'Not implemented',
};

const STATUS_COLOURS = {
    implemented: 'bg-green-100 text-green-800',
    partially_implemented: 'bg-yellow-100 text-yellow-800',
    planned: 'bg-blue-100 text-blue-800',
    not_implemented: 'bg-gray-100 text-gray-600',
};

export default function Soa({ framework, statement = [], can = {} }) {
    const [deciding, setDeciding] = useState(null);

    const applicable = statement.filter((r) => r.is_applicable);
    const implemented = statement.filter((r) => r.implementation_status === 'implemented');

    return (
        <AuthenticatedLayout header="Statement of Applicability">
            <Head title={`SoA — ${framework.code}`} />

            <PageHeader
                title={`Statement of Applicability — ${framework.name}`}
                subtitle="Derived from the live control mappings; decisions and exclusions are recorded per requirement"
                actions={(
                    <a className="btn-secondary" href={route('frameworks.soa.export', framework.id)}>
                        Export PDF
                    </a>
                )}
            />

            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <StatCard icon={Gauge} tone="blue" title="Requirements" value={statement.length} />
                <StatCard icon={CheckCircle2} tone="emerald" title="Applicable" value={applicable.length} />
                <StatCard icon={CircleSlash} tone="slate" title="Excluded" value={statement.length - applicable.length} subtitle="justification required" />
                <StatCard icon={CheckCircle2} tone="emerald" title="Implemented" value={implemented.length} />
            </div>

            <div className="card">
                <div className="card-body p-0">
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Ref</th>
                                    <th>Requirement</th>
                                    <th>Applicable</th>
                                    <th>Implementation</th>
                                    <th>Mapped controls</th>
                                    <th>Decided by</th>
                                    {can.decide && <th></th>}
                                </tr>
                            </thead>
                            <tbody>
                                {statement.map((row) => (
                                    <tr key={row.requirement_id} className={row.is_applicable ? undefined : 'opacity-60'}>
                                        <td className="font-mono text-xs">{row.ref_code}</td>
                                        <td>
                                            <span className="text-sm">{row.title}</span>
                                            {row.justification && (
                                                <div className="text-xs text-gray-500">{row.justification}</div>
                                            )}
                                        </td>
                                        <td>{row.is_applicable ? 'Yes' : 'No'}</td>
                                        <td>
                                            <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_COLOURS[row.implementation_status]}`}>
                                                {STATUS_LABELS[row.implementation_status]}
                                            </span>
                                        </td>
                                        <td>
                                            {row.controls.length === 0 ? (
                                                <span className="text-xs text-gray-400">—</span>
                                            ) : (
                                                <span className="font-mono text-xs">
                                                    {row.controls.map((c) => c.control_ref).join(', ')}
                                                </span>
                                            )}
                                        </td>
                                        <td className="text-xs text-gray-500">{row.decided_by ?? '—'}</td>
                                        {can.decide && (
                                            <td className="text-right">
                                                <button
                                                    type="button"
                                                    className="text-xs font-medium text-[var(--color-primary)] hover:underline"
                                                    onClick={() => setDeciding(row)}
                                                >
                                                    Decide
                                                </button>
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <Modal show={deciding !== null} onClose={() => setDeciding(null)} title={`${deciding?.ref_code} — ${deciding?.title ?? ''}`}>
                {deciding && <DecisionForm framework={framework} row={deciding} onDone={() => setDeciding(null)} />}
            </Modal>
        </AuthenticatedLayout>
    );
}

function DecisionForm({ framework, row, onDone }) {
    const form = useForm({
        requirement_id: row.requirement_id,
        is_applicable: row.is_applicable,
        justification: row.justification ?? '',
        implementation_status: row.implementation_status,
    });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.put(route('frameworks.soa.decide', framework.id), { preserveScroll: true, onSuccess: onDone });
            }}
            className="space-y-4 p-6"
        >
            <div>
                <InputLabel value="Applicable to this organisation?" required />
                <SelectInput
                    value={form.data.is_applicable ? '1' : '0'}
                    onChange={(e) => form.setData('is_applicable', e.target.value === '1')}
                >
                    <option value="1">Yes — in scope</option>
                    <option value="0">No — excluded (justify below)</option>
                </SelectInput>
            </div>
            <div>
                <InputLabel value="Implementation status" required />
                <SelectInput
                    value={form.data.implementation_status}
                    onChange={(e) => form.setData('implementation_status', e.target.value)}
                >
                    {Object.entries(STATUS_LABELS).map(([value, label]) => (
                        <option key={value} value={value}>{label}</option>
                    ))}
                </SelectInput>
            </div>
            <div>
                <InputLabel value="Justification" required={!form.data.is_applicable} />
                <TextArea
                    rows={3}
                    value={form.data.justification}
                    onChange={(e) => form.setData('justification', e.target.value)}
                    className="w-full"
                    placeholder="Why it applies (or does not), and how it is met"
                />
                <InputError message={form.errors.justification} />
            </div>
            <div className="flex justify-end gap-3">
                <SecondaryButton type="button" onClick={onDone}>Cancel</SecondaryButton>
                <PrimaryButton disabled={form.processing}>Record decision</PrimaryButton>
            </div>
        </form>
    );
}
