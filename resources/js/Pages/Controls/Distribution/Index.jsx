import EmptyState from '@/Components/EmptyState';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Plus } from 'lucide-react';

/**
 * Corporater-style distribution overview: stat tiles + an org-tree table of
 * every distributed control per entity with status and progress.
 */
export default function Index({ stats = {}, distributions = [], entities = [], groupControls = [], owners = [], filters = {} }) {
    const { auth } = usePage().props;
    const canDistribute = auth.permissions.includes('distribute controls');
    const [showDistribute, setShowDistribute] = useState(false);

    // Group distributions under their entity, ordered by the org hierarchy.
    const byEntity = useMemo(() => {
        const map = new Map();
        distributions.forEach((d) => {
            if (!map.has(d.entity_id)) map.set(d.entity_id, []);
            map.get(d.entity_id).push(d);
        });
        return map;
    }, [distributions]);

    const orderedEntities = useMemo(() => {
        const children = new Map();
        entities.forEach((e) => {
            const key = e.parent_id ?? 'root';
            if (!children.has(key)) children.set(key, []);
            children.get(key).push(e);
        });
        const ordered = [];
        const walk = (parentKey, depth) => {
            (children.get(parentKey) ?? []).forEach((e) => {
                ordered.push({ ...e, depth });
                walk(e.id, depth + 1);
            });
        };
        walk('root', 0);
        return ordered.filter((e) => byEntity.has(e.id));
    }, [entities, byEntity]);

    return (
        <AuthenticatedLayout header="Control Distribution">
            <Head title="Control Distribution" />

            <PageHeader
                title="Control Distribution Overview"
                subtitle="Group controls distributed to entities, with implementation progress"
                breadcrumbs={[{ label: 'Control Library', href: route('controls.index') }, { label: 'Distribution' }]}
                actions={
                    canDistribute && (
                        <button type="button" className="btn-primary" onClick={() => setShowDistribute(true)}>
                            Open distribution settings
                        </button>
                    )
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-5">
                <StatCard title="Entities distributed to" value={stats.entities_distributed_to ?? 0} color="primary" />
                <StatCard title="Tasks completed" value={stats.tasks_completed ?? 0} color="success" />
                <StatCard title="Distributed tasks not completed" value={stats.tasks_outstanding ?? 0} color="warning" />
                <StatCard title="Implementation progress" value={`${stats.implementation_progress ?? 0}%`} color="primary" />
                <StatCard title="Declined (coverage gaps)" value={stats.declined_gaps ?? 0} color="danger" />
            </div>

            <div className="filter-bar mb-4">
                <div className="filter-bar-inner">
                    <div className="filter-bar-group">
                        <label className="filter-bar-label">Group control</label>
                        <select
                            className="filter-bar-select"
                            value={filters.control_id ?? ''}
                            onChange={(e) =>
                                router.get(route('distributions.index'), e.target.value ? { control_id: e.target.value } : {}, { preserveState: true })
                            }
                        >
                            <option value="">All group controls</option>
                            {groupControls.map((c) => (
                                <option key={c.id} value={c.id}>{c.control_ref} — {c.title}</option>
                            ))}
                        </select>
                    </div>
                </div>
            </div>

            {orderedEntities.length === 0 ? (
                <EmptyState
                    title="Nothing distributed yet"
                    description="Mark a group control as distributable, then open distribution settings to roll it out to entities."
                    actionLabel={canDistribute ? 'Open distribution settings' : undefined}
                    onAction={canDistribute ? () => setShowDistribute(true) : undefined}
                />
            ) : (
                <div className="card overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Entity / Control</th>
                                <th>Owner</th>
                                <th>Due</th>
                                <th>Status</th>
                                <th className="w-40">Progress</th>
                                <th>Rating</th>
                            </tr>
                        </thead>
                        <tbody>
                            {orderedEntities.map((entity) => (
                                <EntityRows key={entity.id} entity={entity} rows={byEntity.get(entity.id) ?? []} />
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <DistributeModal
                show={showDistribute}
                onClose={() => setShowDistribute(false)}
                groupControls={groupControls}
                entities={entities}
                owners={owners}
            />
        </AuthenticatedLayout>
    );
}

function EntityRows({ entity, rows }) {
    return (
        <>
            <tr className="bg-gray-50/70">
                <td colSpan={6} className="!py-2">
                    <span style={{ paddingLeft: `${entity.depth * 16}px` }} className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                        {entity.name} <span className="ml-1 font-normal normal-case text-gray-400">({entity.type})</span>
                    </span>
                </td>
            </tr>
            {rows.map((d) => (
                <tr key={d.id} className="cursor-pointer hover:bg-gray-50" onClick={() => router.visit(route('distributions.show', d.id))}>
                    <td>
                        <span style={{ paddingLeft: `${entity.depth * 16 + 16}px` }}>
                            <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{d.control?.control_ref}</span>{' '}
                            <span className="text-sm">{d.control?.title}</span>
                        </span>
                    </td>
                    <td>{d.assigned_owner?.name ?? '—'}</td>
                    <td className="text-sm">{d.due_at ? new Date(d.due_at).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'}</td>
                    <td><StatusBadge status={d.status} /></td>
                    <td>
                        <div className="progress-bar">
                            <div className="progress-bar-fill bg-[var(--color-secondary)]" style={{ width: `${d.progress}%` }} />
                        </div>
                        <p className="mt-0.5 text-[10px] text-gray-400">
                            {d.completed_tasks_count}/{d.tasks_count} tasks · {d.progress}%
                        </p>
                    </td>
                    <td>{d.child_control?.overall_rating ? <StatusBadge status={d.child_control.overall_rating} /> : '—'}</td>
                </tr>
            ))}
        </>
    );
}

function DistributeModal({ show, onClose, groupControls, entities, owners }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        control_id: '',
        entity_ids: [],
        due_at: '',
        tasks: [],
    });

    const toggleEntity = (id) => {
        setData('entity_ids', data.entity_ids.includes(id)
            ? data.entity_ids.filter((e) => e !== id)
            : [...data.entity_ids, id]);
    };

    const addTask = () => setData('tasks', [...data.tasks, { title: '', evidence_required: false }]);

    const submit = (e) => {
        e.preventDefault();
        post(route('controls.distribute', data.control_id), {
            onSuccess: () => { reset(); onClose(); },
        });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-lg font-semibold text-[var(--color-text-primary)]">Distribution settings</h2>
                <p className="mt-1 text-sm text-[var(--color-text-secondary)]">
                    Distribute an approved group control to entities. Each entity receives its own control instance with its own owner, testing and rating.
                </p>

                <div className="mt-4 space-y-4">
                    <div>
                        <InputLabel htmlFor="control_id" value="Group control" required />
                        <SelectInput id="control_id" value={data.control_id} onChange={(e) => setData('control_id', e.target.value)}>
                            <option value="">Select a distributable group control…</option>
                            {groupControls.filter((c) => c.status === 'Active').map((c) => (
                                <option key={c.id} value={c.id}>{c.control_ref} — {c.title}</option>
                            ))}
                        </SelectInput>
                        <InputError message={errors.control_id} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel value="Entities" required />
                        <div className="mt-1 max-h-48 space-y-1 overflow-y-auto rounded-lg border border-gray-200 p-3">
                            {entities.map((e) => (
                                <label key={e.id} className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        className="rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                        checked={data.entity_ids.includes(e.id)}
                                        onChange={() => toggleEntity(e.id)}
                                    />
                                    <span>{e.name}</span>
                                    <span className="text-xs text-gray-400">{e.type}</span>
                                </label>
                            ))}
                        </div>
                        <InputError message={errors.entity_ids} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="due_at" value="Implementation due date" />
                        <TextInput id="due_at" type="date" value={data.due_at} onChange={(e) => setData('due_at', e.target.value)} />
                        <InputError message={errors.due_at} className="mt-1" />
                    </div>

                    <div>
                        <div className="flex items-center justify-between">
                            <InputLabel value="Implementation tasks" />
                            <button type="button" className="text-xs font-semibold text-[var(--color-primary)]" onClick={addTask}>
                                <Plus className="h-4 w-4" strokeWidth={2} /> Add task
                            </button>
                        </div>
                        <div className="mt-1 space-y-2">
                            {data.tasks.map((task, i) => (
                                <div key={i} className="flex items-center gap-2">
                                    <TextInput
                                        value={task.title}
                                        placeholder={`Task ${i + 1} — e.g. Adopt procedure locally`}
                                        onChange={(e) => setData('tasks', data.tasks.map((t, j) => (j === i ? { ...t, title: e.target.value } : t)))}
                                    />
                                    <button
                                        type="button"
                                        className="text-xs text-[var(--color-error)]"
                                        onClick={() => setData('tasks', data.tasks.filter((_, j) => j !== i))}
                                    >
                                        Remove
                                    </button>
                                </div>
                            ))}
                        </div>
                        <InputError message={errors.tasks} className="mt-1" />
                    </div>
                </div>

                <div className="mt-6 flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>Cancel</SecondaryButton>
                    <PrimaryButton disabled={processing || !data.control_id || data.entity_ids.length === 0}>
                        Distribute
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
