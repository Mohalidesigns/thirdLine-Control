import LinkageGraph from '@/Components/LinkageGraph';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import { router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const RELATIONSHIPS = [
    'mitigates', 'causes', 'indicates', 'governs', 'evidences',
    'relates_to', 'escalates_to', 'depends_on',
];

const LINKABLE_TYPES = [
    ['risk', 'Risk'],
    ['control', 'Control'],
    ['metric', 'Metric'],
    ['exception', 'Exception'],
    ['treatment', 'Treatment'],
    ['obligation', 'Obligation'],
    ['requirement', 'Requirement'],
    ['document', 'Document'],
    ['improvement', 'Improvement'],
    ['process', 'Process'],
];

/**
 * The Relationships panel that sits on every major record: what this
 * record is connected to, how, and — behind a toggle — the two-hop graph.
 */
export default function RelationshipsPanel({ type, id, links = [], canLink = false }) {
    const [showGraph, setShowGraph] = useState(false);
    const [adding, setAdding] = useState(false);

    const grouped = links.reduce((groups, link) => {
        (groups[link.type_label] ??= []).push(link);
        return groups;
    }, {});

    return (
        <div className="card">
            <div className="card-header">
                <h3 className="font-semibold text-[var(--color-text-primary)]">Relationships</h3>
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={() => setShowGraph((v) => !v)}
                        className="text-xs font-semibold text-[var(--color-primary)] underline-offset-2 hover:underline"
                    >
                        {showGraph ? 'Show list' : 'Show graph'}
                    </button>
                    {canLink && (
                        <SecondaryButton type="button" className="!px-2 !py-1 !text-xs" onClick={() => setAdding((v) => !v)}>
                            {adding ? 'Cancel' : '+ Link'}
                        </SecondaryButton>
                    )}
                </div>
            </div>

            <div className="card-body">
                {adding && <LinkForm sourceType={type} sourceId={id} onDone={() => setAdding(false)} />}

                {showGraph ? (
                    <LinkageGraph type={type} id={id} />
                ) : links.length === 0 ? (
                    <p className="text-sm text-[var(--color-text-secondary)]">
                        Nothing is linked to this record yet.
                    </p>
                ) : (
                    <div className="space-y-4">
                        {Object.entries(grouped).map(([label, group]) => (
                            <div key={label}>
                                <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                                    {label}
                                </p>
                                <ul className="space-y-1">
                                    {group.map((link) => (
                                        <li key={link.link_id} className="flex flex-wrap items-center gap-2 text-sm">
                                            <span className="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-600">
                                                {link.direction === 'outgoing' ? '→' : '←'} {link.relationship.replace('_', ' ')}
                                            </span>
                                            {link.route ? (
                                                <a
                                                    href={route(link.route, link.id)}
                                                    className="font-mono text-xs font-semibold text-[var(--color-primary)] hover:underline"
                                                >
                                                    {link.ref ?? `#${link.id}`}
                                                </a>
                                            ) : (
                                                <span className="font-mono text-xs font-semibold">{link.ref ?? `#${link.id}`}</span>
                                            )}
                                            <span className="text-[var(--color-text-secondary)]">{link.title}</span>
                                            {canLink && (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        router.delete(route('links.destroy', link.link_id), { preserveScroll: true })
                                                    }
                                                    className="ms-auto text-xs text-[var(--color-error)] hover:underline"
                                                >
                                                    Remove
                                                </button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}

function LinkForm({ sourceType, sourceId, onDone }) {
    const [candidates, setCandidates] = useState([]);
    const [search, setSearch] = useState('');

    const { data, setData, post, processing, errors, reset } = useForm({
        source_type: sourceType,
        source_id: sourceId,
        target_type: 'control',
        target_id: '',
        relationship: 'relates_to',
        strength: 3,
        notes: '',
    });

    useEffect(() => {
        let cancelled = false;
        const timer = setTimeout(() => {
            fetch(route('links.candidates', { type: data.target_type, search }), {
                headers: { Accept: 'application/json' },
            })
                .then((response) => (response.ok ? response.json() : []))
                .then((rows) => !cancelled && setCandidates(rows))
                .catch(() => !cancelled && setCandidates([]));
        }, 250);

        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [data.target_type, search]);

    const submit = (event) => {
        event.preventDefault();
        post(route('links.store'), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onDone();
            },
        });
    };

    return (
        <form onSubmit={submit} className="mb-4 space-y-3 rounded-lg border border-gray-100 bg-gray-50 p-3">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div>
                    <label className="filter-bar-label">Record type</label>
                    <SelectInput value={data.target_type} onChange={(e) => setData('target_type', e.target.value)}>
                        {LINKABLE_TYPES.map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </SelectInput>
                </div>
                <div>
                    <label className="filter-bar-label">Relationship</label>
                    <SelectInput value={data.relationship} onChange={(e) => setData('relationship', e.target.value)}>
                        {RELATIONSHIPS.map((value) => (
                            <option key={value} value={value}>
                                {value.replace('_', ' ')}
                            </option>
                        ))}
                    </SelectInput>
                </div>
                <div>
                    <label className="filter-bar-label">Search</label>
                    <TextInput value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Reference or title…" />
                </div>
            </div>

            <div>
                <label className="filter-bar-label">Record</label>
                <SelectInput value={data.target_id} onChange={(e) => setData('target_id', e.target.value)}>
                    <option value="">Select a record…</option>
                    {candidates.map((candidate) => (
                        <option key={candidate.id} value={candidate.id}>
                            {candidate.ref} — {candidate.title}
                        </option>
                    ))}
                </SelectInput>
                {errors.target_id && <p className="mt-1 text-xs text-[var(--color-error)]">{errors.target_id}</p>}
            </div>

            <div className="flex justify-end gap-2">
                <SecondaryButton type="button" onClick={onDone}>
                    Cancel
                </SecondaryButton>
                <button type="submit" className="btn-primary !py-1.5 !text-xs" disabled={processing || !data.target_id}>
                    Link record
                </button>
            </div>
        </form>
    );
}
