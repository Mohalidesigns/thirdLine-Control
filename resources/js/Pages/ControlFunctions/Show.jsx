import DataTable from '@/Components/DataTable';
import FrequencyBadge from '@/Components/FrequencyBadge';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import StatusBadge from '@/Components/StatusBadge';
import TabBar from '@/Components/TabBar';
import TextArea from '@/Components/TextArea';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Building2, CalendarCheck, History, ListChecks, Zap } from 'lucide-react';
import { useState } from 'react';

/**
 * CR-03 §E.2: one control function — its checklist with a per-line
 * frequency chip, the desks and branches that execute it, the version
 * history of the checklist, and the instances it has produced.
 */
export default function Show({
    function: fn = {},
    rhythms = [],
    checklist = [],
    versions = [],
    entities = [],
    instances = [],
    can = {},
}) {
    const [tab, setTab] = useState('checklist');
    const [triggering, setTriggering] = useState(false);

    const form = useForm({ control_entity_id: '', reason: '' });

    const trigger = (e) => {
        e.preventDefault();
        form.post(route('control-functions.trigger', fn.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setTriggering(false);
            },
        });
    };

    const checklistColumns = [
        { field: 'sequence', label: '#', width: '3rem' },
        {
            field: 'question',
            label: 'Checklist line',
            render: (row) => (
                <div>
                    <p className="text-[var(--color-text-primary)]">{row.question}</p>
                    {row.source_ref && (
                        <p className="text-xs text-[var(--color-text-secondary)]" title="The cell this line came from in the bank's workbook">
                            {row.source_ref}
                        </p>
                    )}
                </div>
            ),
        },
        {
            field: 'frequency',
            label: 'Frequency of Activity',
            width: '13rem',
            render: (row) => (
                <FrequencyBadge frequency={row.frequency} raw={row.frequency_raw} isOverride={row.is_override} />
            ),
        },
        {
            field: 'is_mandatory',
            label: 'Mandatory',
            width: '7rem',
            render: (row) => (row.is_mandatory ? 'Yes' : 'Optional'),
        },
    ];

    const instanceColumns = [
        { field: 'reference', label: 'Task', width: '9rem' },
        { field: 'entity', label: 'Desk / branch' },
        { field: 'period_label', label: 'Period', width: '9rem' },
        { field: 'frequency', label: 'Rhythm', width: '9rem' },
        {
            field: 'due_date',
            label: 'Due',
            width: '8rem',
            // A continuous observation has no deadline by design (§C.5).
            render: (row) => row.due_date ?? <span className="text-gray-400" title="A continuous task has no deadline">Rolling</span>,
        },
        { field: 'tester', label: 'Officer', width: '10rem' },
        {
            field: 'status',
            label: 'Status',
            width: '8rem',
            render: (row) => <StatusBadge status={row.is_overdue ? 'Overdue' : row.status} />,
        },
    ];

    return (
        <AuthenticatedLayout header="Control Functions">
            <Head title={fn.title ?? 'Control function'} />

            <PageHeader
                title={fn.title}
                subtitle={`${fn.reference} · ${fn.unit?.name ?? '—'}${fn.entity ? ` · ${fn.entity.name}` : ''}`}
                actions={can.trigger && (
                    <PrimaryButton type="button" onClick={() => setTriggering(true)}>
                        <Zap className="me-1.5 h-4 w-4" aria-hidden="true" />
                        Trigger occurrence
                    </PrimaryButton>
                )}
            />

            <div className="card mb-5">
                <div className="card-body grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Frequency</p>
                        <div className="mt-1"><FrequencyBadge frequency={fn.frequency} raw={fn.frequency_raw} /></div>
                    </div>
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Checklist lines</p>
                        <p className="mt-1 text-lg font-semibold tabular-nums">{fn.line_count ?? checklist.length}</p>
                    </div>
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Executed by</p>
                        <p className="mt-1 text-lg font-semibold tabular-nums">{entities.length}</p>
                    </div>
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Owner</p>
                        <p className="mt-1">{fn.owner?.name ?? <span className="text-gray-400">Unassigned</span>}</p>
                    </div>
                </div>
            </div>

            {rhythms.length > 1 && (
                <div className="card mb-5">
                    <div className="card-body">
                        <p className="text-sm text-[var(--color-text-secondary)]">
                            This function carries more than one rhythm: each generates its own task holding only the lines
                            that run to it.
                        </p>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {rhythms.map((rhythm) => (
                                <FrequencyBadge key={rhythm.code} frequency={rhythm} />
                            ))}
                        </div>
                    </div>
                </div>
            )}

            <TabBar
                tabs={[
                    { name: 'checklist', label: 'Checklist', icon: ListChecks, count: checklist.length },
                    { name: 'entities', label: 'Desks & branches', icon: Building2, count: entities.length },
                    { name: 'versions', label: 'Versions', icon: History, count: versions.length },
                    { name: 'instances', label: 'Task history', icon: CalendarCheck, count: instances.length },
                ]}
                active={tab}
                onChange={setTab}
            />

            <div className="card mt-4">
                {tab === 'checklist' && (
                    <DataTable columns={checklistColumns} data={checklist} emptyMessage="This function has no active checklist." />
                )}

                {tab === 'entities' && (
                    <DataTable
                        columns={[
                            { field: 'name', label: 'Desk / branch' },
                            { field: 'entity_kind', label: 'Kind', width: '10rem' },
                            { field: 'officer', label: 'Control officer', width: '14rem' },
                        ]}
                        data={entities}
                        emptyMessage="Nobody executes this function yet — attach it to a desk or branch."
                    />
                )}

                {tab === 'versions' && (
                    <DataTable
                        columns={[
                            { field: 'version_no', label: 'Version', width: '6rem' },
                            { field: 'status', label: 'Status', width: '9rem', render: (row) => <StatusBadge status={row.status} /> },
                            { field: 'items', label: 'Lines', width: '6rem' },
                            { field: 'approved_at', label: 'Approved' },
                        ]}
                        data={versions}
                        emptyMessage="No checklist versions."
                    />
                )}

                {tab === 'instances' && (
                    <DataTable
                        columns={instanceColumns}
                        data={instances}
                        emptyMessage="No tasks generated for this function yet."
                        onRowClick={(row) => window.location.assign(route('test-instances.show', row.id))}
                    />
                )}
            </div>

            <Modal show={triggering} onClose={() => setTriggering(false)}>
                <form onSubmit={trigger} className="p-6">
                    <h2 className="text-lg font-semibold">Trigger an occurrence</h2>
                    <p className="mt-1 text-sm text-[var(--color-text-secondary)]">
                        This function has no calendar — it runs when something makes it run. What happened is recorded on
                        the task.
                    </p>

                    <div className="mt-4">
                        <InputLabel htmlFor="control_entity_id" value="Desk or branch" />
                        <SelectInput
                            id="control_entity_id"
                            value={form.data.control_entity_id}
                            onChange={(e) => form.setData('control_entity_id', e.target.value)}
                        >
                            <option value="">All / not specific</option>
                            {entities.map((entity) => (
                                <option key={entity.id} value={entity.id}>{entity.name}</option>
                            ))}
                        </SelectInput>
                    </div>

                    <div className="mt-4">
                        <InputLabel htmlFor="reason" value="What triggered it" />
                        <TextArea
                            id="reason"
                            value={form.data.reason}
                            onChange={(e) => form.setData('reason', e.target.value)}
                            rows={3}
                            required
                        />
                    </div>

                    <div className="mt-5 flex justify-end gap-2">
                        <SecondaryButton type="button" onClick={() => setTriggering(false)}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={form.processing}>Raise the task</PrimaryButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
