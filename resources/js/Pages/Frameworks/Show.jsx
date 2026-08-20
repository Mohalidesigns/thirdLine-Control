import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import ProgressBar from '@/Components/ProgressBar';
import StatCard from '@/Components/StatCard';
import VerificationBadge from '@/Components/VerificationBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

function CoverageDot({ coverage }) {
    const state = coverage.effective > 0 ? 'effective' : coverage.mapped > 0 ? 'mapped' : 'unmapped';

    const style = {
        effective: 'bg-[var(--color-success)]',
        mapped: 'bg-[var(--color-warning)]',
        unmapped: 'bg-gray-200',
    }[state];

    const label = {
        effective: 'Covered by an effective control',
        mapped: 'Mapped, but no current effective rating',
        unmapped: 'No approved control mapping',
    }[state];

    return <span className={`inline-block h-2.5 w-2.5 shrink-0 rounded-full ${style}`} title={label} />;
}

function RequirementNode({ node, depth, expanded, onToggle, onSelect, selectedId }) {
    const hasChildren = node.children?.length > 0;
    const isOpen = expanded.has(node.id);

    return (
        <li>
            <div
                className={`flex items-start gap-2 rounded-lg px-2 py-2 hover:bg-gray-50 ${
                    selectedId === node.id ? 'bg-gray-50 ring-1 ring-[var(--color-primary)]/20' : ''
                }`}
                style={{ paddingLeft: `${depth * 14 + 8}px` }}
            >
                <button
                    type="button"
                    onClick={() => (hasChildren ? onToggle(node.id) : onSelect(node))}
                    aria-label={hasChildren ? (isOpen ? 'Collapse' : 'Expand') : 'Select'}
                    className="mt-0.5 w-4 shrink-0 text-gray-400"
                >
                    {hasChildren ? (isOpen ? '−' : '+') : '·'}
                </button>

                <CoverageDot coverage={node.coverage} />

                <button type="button" onClick={() => onSelect(node)} className="min-w-0 flex-1 text-left">
                    <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{node.ref_code}</span>
                    <span className="ml-2 text-sm text-[var(--color-text-primary)]">{node.title}</span>
                    {node.verification_status !== 'verified' && (
                        <VerificationBadge status={node.verification_status} className="ml-2" />
                    )}
                </button>

                <span className="shrink-0 text-xs text-gray-400">
                    {node.coverage.mapped > 0 ? `${node.coverage.mapped} control(s)` : '—'}
                </span>
            </div>

            {hasChildren && isOpen && (
                <ul>
                    {node.children.map((child) => (
                        <RequirementNode
                            key={child.id}
                            node={child}
                            depth={depth + 1}
                            expanded={expanded}
                            onToggle={onToggle}
                            onSelect={onSelect}
                            selectedId={selectedId}
                        />
                    ))}
                </ul>
            )}
        </li>
    );
}

export default function Show({ framework, tree = [], coverage = {}, can = {} }) {
    const [expanded, setExpanded] = useState(() => new Set(tree.map((node) => node.id)));
    const [selected, setSelected] = useState(null);
    const [suggestions, setSuggestions] = useState(null);
    const [loadingSuggestions, setLoadingSuggestions] = useState(false);

    const toggle = (id) =>
        setExpanded((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    const select = (node) => {
        setSelected(node);
        setSuggestions(null);
    };

    const loadSuggestions = () => {
        if (!selected) return;
        setLoadingSuggestions(true);
        fetch(route('frameworks.requirements.suggestions', [framework.id, selected.id]), {
            headers: { Accept: 'application/json' },
        })
            .then((response) => (response.ok ? response.json() : { requirements: [], controls: [] }))
            .then(setSuggestions)
            .finally(() => setLoadingSuggestions(false));
    };

    const mapSuggested = (controlId) => {
        router.post(
            route('control-mappings.store'),
            { control_id: controlId, requirement_id: selected.id, coverage: 'full' },
            { preserveScroll: true },
        );
    };

    const totals = useMemo(
        () => ({
            mapped: coverage.mapped_percentage ?? 0,
            effective: coverage.effective_percentage ?? 0,
        }),
        [coverage],
    );

    return (
        <AuthenticatedLayout header={framework.code}>
            <Head title={framework.name} />

            <PageHeader
                breadcrumbs={[{ label: 'Frameworks', href: route('frameworks.index') }, { label: framework.code }]}
                title={framework.name}
                subtitle={`${framework.issuing_body ?? 'Issuing body not recorded'} · version ${framework.version} · ${framework.jurisdiction}`}
                actions={
                    <>
                        <Link href={route('frameworks.coverage', framework.id)} className="btn-secondary">
                            Coverage heatmap
                        </Link>
                        {framework.is_certifiable && (
                            <Link href={route('frameworks.soa', framework.id)} className="btn-secondary">
                                Statement of Applicability
                            </Link>
                        )}
                        <a href={route('frameworks.export', framework.id)} className="btn-secondary">
                            Export pack
                        </a>
                    </>
                }
            />

            {framework.verification_status !== 'verified' && (
                <div className="mb-4 rounded-lg border-l-4 border-l-[var(--color-warning)] bg-amber-50 px-4 py-3 text-sm">
                    <span className="font-semibold">This framework is {framework.verification_status}.</span> Its
                    requirements are usable internally but are excluded from any generated regulatory submission until a
                    human confirms them against the issuing body’s primary document.
                </div>
            )}

            <div className="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-4">
                <StatCard title="Testable requirements" value={coverage.testable_requirements ?? 0} color="primary" />
                <StatCard title="Mapped" value={coverage.mapped_requirements ?? 0} color="info" subtitle={`${totals.mapped}% of testable`} />
                <StatCard title="Effective" value={coverage.effective_requirements ?? 0} color="success" subtitle={`${totals.effective}% of testable`} />
                <StatCard title="Unmapped" value={coverage.unmapped_requirements ?? 0} color="danger" prominent={(coverage.unmapped_requirements ?? 0) > 0} />
            </div>

            <div className="mb-6 card">
                <div className="card-body">
                    <div className="mb-1 flex items-center justify-between text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                        <span>Coverage</span>
                        <span>{totals.effective}% effective · {totals.mapped}% mapped</span>
                    </div>
                    <ProgressBar value={totals.effective} />
                </div>
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="card lg:col-span-2">
                    <div className="card-header flex items-center justify-between">
                        <h2 className="text-sm font-semibold">Requirements</h2>
                        <div className="flex items-center gap-3 text-xs text-gray-400">
                            <span className="flex items-center gap-1"><span className="h-2.5 w-2.5 rounded-full bg-[var(--color-success)]" /> effective</span>
                            <span className="flex items-center gap-1"><span className="h-2.5 w-2.5 rounded-full bg-[var(--color-warning)]" /> mapped</span>
                            <span className="flex items-center gap-1"><span className="h-2.5 w-2.5 rounded-full bg-gray-200" /> unmapped</span>
                        </div>
                    </div>
                    <div className="card-body max-h-[70vh] overflow-y-auto">
                        {tree.length === 0 ? (
                            <EmptyState title="No requirements yet" description="Install or import a content pack to populate this framework." />
                        ) : (
                            <ul>
                                {tree.map((node) => (
                                    <RequirementNode
                                        key={node.id}
                                        node={node}
                                        depth={0}
                                        expanded={expanded}
                                        onToggle={toggle}
                                        onSelect={select}
                                        selectedId={selected?.id}
                                    />
                                ))}
                            </ul>
                        )}
                    </div>
                </div>

                <div className="card h-fit">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">{selected ? selected.ref_code : 'Requirement detail'}</h2>
                    </div>
                    <div className="card-body space-y-4">
                        {!selected && (
                            <p className="text-sm text-gray-400">Select a requirement to see its detail, mapped controls and suggestions.</p>
                        )}

                        {selected && (
                            <>
                                <div>
                                    <p className="font-medium">{selected.title}</p>
                                    {selected.description && <p className="mt-1 text-sm text-gray-500">{selected.description}</p>}
                                    <div className="mt-2 flex flex-wrap items-center gap-2">
                                        <VerificationBadge status={selected.verification_status} />
                                        <span className="badge badge-status-draft">{selected.requirement_type}</span>
                                        {!selected.is_testable && <span className="badge badge-status-draft">not testable</span>}
                                    </div>
                                    {selected.source_reference && (
                                        <p className="mt-2 text-xs text-gray-400">Source: {selected.source_reference}</p>
                                    )}
                                </div>

                                <div>
                                    <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                                        Mapped controls
                                    </p>
                                    {selected.coverage.controls.length === 0 ? (
                                        <p className="mt-1 text-sm text-gray-400">No approved mappings.</p>
                                    ) : (
                                        <ul className="mt-2 space-y-2">
                                            {selected.coverage.controls.map((control) => (
                                                <li key={control.id} className="flex items-start justify-between gap-2 text-sm">
                                                    <Link href={route('controls.show', control.id)} className="min-w-0">
                                                        <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">
                                                            {control.control_ref}
                                                        </span>
                                                        <span className="ml-2">{control.title}</span>
                                                    </Link>
                                                    <span className={`badge ${control.is_effective ? 'badge-low' : 'badge-medium'}`}>
                                                        {control.coverage}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>

                                {can.map && (
                                    <div>
                                        <button type="button" onClick={loadSuggestions} className="btn-secondary w-full">
                                            {loadingSuggestions ? 'Finding matches…' : 'Suggest controls & mappings'}
                                        </button>

                                        {suggestions && (
                                            <div className="mt-3 space-y-3">
                                                <div>
                                                    <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                                                        Suggested controls
                                                    </p>
                                                    {suggestions.controls.length === 0 ? (
                                                        <p className="mt-1 text-sm text-gray-400">No close matches in the control library.</p>
                                                    ) : (
                                                        <ul className="mt-2 space-y-2">
                                                            {suggestions.controls.map((suggestion) => (
                                                                <li key={suggestion.control_id} className="flex items-center justify-between gap-2 text-sm">
                                                                    <span className="min-w-0 truncate">
                                                                        <span className="font-mono text-xs">{suggestion.control_ref}</span>{' '}
                                                                        {suggestion.title}
                                                                    </span>
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => mapSuggested(suggestion.control_id)}
                                                                        className="btn-secondary shrink-0 text-xs"
                                                                    >
                                                                        Map
                                                                    </button>
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    )}
                                                </div>

                                                <div>
                                                    <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                                                        Equivalent requirements elsewhere
                                                    </p>
                                                    {suggestions.requirements.length === 0 ? (
                                                        <p className="mt-1 text-sm text-gray-400">No cross-framework matches found.</p>
                                                    ) : (
                                                        <ul className="mt-2 space-y-1 text-sm">
                                                            {suggestions.requirements.map((suggestion) => (
                                                                <li key={suggestion.requirement_id} className="flex items-center justify-between gap-2">
                                                                    <span className="min-w-0 truncate">
                                                                        <span className="font-mono text-xs">{suggestion.ref_code}</span>{' '}
                                                                        {suggestion.title}
                                                                    </span>
                                                                    <span className="badge badge-status-draft shrink-0">
                                                                        {suggestion.suggested_relationship}
                                                                    </span>
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    )}
                                                    <p className="mt-2 text-xs text-gray-400">
                                                        Suggestions are drafts scored on shared terminology. A person maps, a second
                                                        person approves.
                                                    </p>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
