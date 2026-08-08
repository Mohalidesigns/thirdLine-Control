import { Link } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

const TYPE_COLOURS = {
    risk: '#2a78d6',
    control: '#1baf7a',
    metric: '#eb6834',
    exception: '#e34948',
    treatment: '#4a3aa7',
    obligation: '#eda100',
    requirement: '#e87ba4',
    document: '#008300',
    improvement: '#319795',
    process: '#718096',
};

/**
 * Two hops out from a record, laid out with a short deterministic
 * force relaxation over the server-computed adjacency. The browser never
 * walks the register itself and never runs an open-ended simulation —
 * a fixed iteration count keeps this cheap on a mid-range phone (R6).
 */
export default function LinkageGraph({ type, id, hops = 2 }) {
    const [graph, setGraph] = useState(null);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        let cancelled = false;
        setLoading(true);

        fetch(route('links.graph', { type, id, hops }), { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error('Unable to load the graph.'))))
            .then((data) => !cancelled && setGraph(data))
            .catch((e) => !cancelled && setError(e.message))
            .finally(() => !cancelled && setLoading(false));

        return () => {
            cancelled = true;
        };
    }, [type, id, hops]);

    const layout = useMemo(() => (graph ? relax(graph) : null), [graph]);

    if (loading) return <p className="p-6 text-sm text-[var(--color-text-secondary)]">Loading relationships…</p>;
    if (error) return <p className="p-6 text-sm text-[var(--color-error)]">{error}</p>;
    if (!layout || layout.nodes.length <= 1) {
        return <p className="p-6 text-sm text-[var(--color-text-secondary)]">Nothing is linked to this record yet.</p>;
    }

    const width = 640;
    const height = 380;
    const positions = new Map(layout.nodes.map((node) => [node.key, node]));

    return (
        <div>
            <div className="mb-2 flex flex-wrap gap-3 text-xs text-[var(--color-text-secondary)]">
                {[...new Set(layout.nodes.map((node) => node.type))].map((nodeType) => (
                    <span key={nodeType} className="flex items-center gap-1.5">
                        <span className="inline-block h-2.5 w-2.5 rounded-full" style={{ backgroundColor: TYPE_COLOURS[nodeType] ?? '#718096' }} />
                        {layout.nodes.find((n) => n.type === nodeType)?.type_label ?? nodeType}
                    </span>
                ))}
            </div>

            <div className="overflow-x-auto">
                <svg viewBox={`0 0 ${width} ${height}`} className="w-full min-w-[520px]" role="img" aria-label="Relationship map">
                    {layout.edges.map((edge, index) => {
                        const source = positions.get(edge.source);
                        const target = positions.get(edge.target);
                        if (!source || !target) return null;

                        return (
                            <g key={index}>
                                <line
                                    x1={source.x}
                                    y1={source.y}
                                    x2={target.x}
                                    y2={target.y}
                                    stroke="#c3c2b7"
                                    strokeWidth={Math.max(1, (edge.strength ?? 3) / 2)}
                                />
                                <text
                                    x={(source.x + target.x) / 2}
                                    y={(source.y + target.y) / 2 - 3}
                                    textAnchor="middle"
                                    className="fill-[var(--color-text-secondary)] text-[9px]"
                                >
                                    {edge.relationship.replace('_', ' ')}
                                </text>
                            </g>
                        );
                    })}

                    {layout.nodes.map((node) => (
                        <g key={node.key}>
                            <circle
                                cx={node.x}
                                cy={node.y}
                                r={node.is_root ? 13 : 9}
                                fill={TYPE_COLOURS[node.type] ?? '#718096'}
                                stroke="#ffffff"
                                strokeWidth="2"
                            />
                            <text
                                x={node.x}
                                y={node.y + (node.is_root ? 27 : 22)}
                                textAnchor="middle"
                                className="fill-[var(--color-text-primary)] text-[10px] font-semibold"
                            >
                                {node.ref ?? node.type_label}
                            </text>
                        </g>
                    ))}
                </svg>
            </div>

            {layout.truncated && (
                <p className="mt-2 text-xs text-[var(--color-warning)]">
                    The graph was capped at {layout.nodes.length} nodes — some relationships are not shown.
                </p>
            )}

            <ul className="mt-3 grid gap-1 text-xs sm:grid-cols-2">
                {layout.nodes
                    .filter((node) => !node.is_root)
                    .map((node) => (
                        <li key={node.key} className="flex items-center gap-1.5">
                            <span className="inline-block h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: TYPE_COLOURS[node.type] ?? '#718096' }} />
                            <span className="text-[var(--color-text-secondary)]">{node.type_label}</span>
                            {node.route ? (
                                <Link href={route(node.route, node.id)} className="font-medium text-[var(--color-primary)] hover:underline">
                                    {node.ref ?? node.title}
                                </Link>
                            ) : (
                                <span className="font-medium">{node.ref ?? node.title}</span>
                            )}
                        </li>
                    ))}
            </ul>
        </div>
    );
}

/**
 * Seeded radial start, then a fixed 140 iterations of repulsion/attraction.
 * Deterministic — the same graph always lays out the same way.
 */
function relax({ nodes, edges, truncated }) {
    const width = 640;
    const height = 380;
    const centre = { x: width / 2, y: height / 2 };

    const placed = nodes.map((node, index) => {
        const ring = node.is_root ? 0 : node.depth;
        const peers = Math.max(1, nodes.filter((n) => n.depth === node.depth).length);
        const angle = (2 * Math.PI * index) / peers;
        const radius = ring * 110;

        return {
            ...node,
            x: centre.x + radius * Math.cos(angle),
            y: centre.y + radius * Math.sin(angle),
        };
    });

    const index = new Map(placed.map((node) => [node.key, node]));

    for (let step = 0; step < 140; step++) {
        const cooling = 1 - step / 140;

        for (let i = 0; i < placed.length; i++) {
            for (let j = i + 1; j < placed.length; j++) {
                const a = placed[i];
                const b = placed[j];
                let dx = a.x - b.x;
                let dy = a.y - b.y;
                let distance = Math.sqrt(dx * dx + dy * dy) || 0.01;
                const push = (3200 / (distance * distance)) * cooling;
                dx /= distance;
                dy /= distance;
                a.x += dx * push;
                a.y += dy * push;
                b.x -= dx * push;
                b.y -= dy * push;
            }
        }

        edges.forEach((edge) => {
            const a = index.get(edge.source);
            const b = index.get(edge.target);
            if (!a || !b) return;

            const dx = b.x - a.x;
            const dy = b.y - a.y;
            const distance = Math.sqrt(dx * dx + dy * dy) || 0.01;
            const pull = ((distance - 120) / distance) * 0.08 * cooling;

            a.x += dx * pull;
            a.y += dy * pull;
            b.x -= dx * pull;
            b.y -= dy * pull;
        });

        placed.forEach((node) => {
            if (node.is_root) {
                node.x = centre.x;
                node.y = centre.y;
                return;
            }
            node.x = Math.max(40, Math.min(width - 40, node.x));
            node.y = Math.max(30, Math.min(height - 30, node.y));
        });
    }

    return { nodes: placed, edges, truncated };
}
