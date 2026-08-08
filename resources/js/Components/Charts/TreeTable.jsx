import { useState } from 'react';
import { router } from '@inertiajs/react';
import { INK, STATUS } from './palette';

/**
 * The org-tree rollup: a value per node, rolled up its hierarchy, with an
 * inline progress bar against the whole. Expanding a branch is how the
 * "individual → team → department → business unit → organisation" walk works,
 * and every row's number is clickable through to the filtered list it came
 * from.
 */
export default function TreeTable({ payload, onDrill }) {
    const nodes = payload.tree ?? [];

    if (nodes.length === 0) {
        return null;
    }

    return (
        <div className="overflow-x-auto">
            <table className="data-table">
                <thead>
                    <tr>
                        <th>Unit</th>
                        <th className="text-right">Own</th>
                        <th className="text-right">Rolled up</th>
                        <th className="w-1/3">Share</th>
                    </tr>
                </thead>
                <tbody>
                    {nodes.map((node) => (
                        <Row key={node.id} node={node} depth={0} onDrill={onDrill} />
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function Row({ node, depth, onDrill }) {
    const [open, setOpen] = useState(depth < 1);
    const children = node.children ?? [];

    return (
        <>
            <tr>
                <td>
                    <div className="flex items-center gap-1" style={{ paddingInlineStart: depth * 14 }}>
                        {children.length > 0 ? (
                            <button
                                type="button"
                                onClick={() => setOpen((value) => !value)}
                                aria-expanded={open}
                                aria-label={open ? `Collapse ${node.label}` : `Expand ${node.label}`}
                                className="flex h-5 w-5 items-center justify-center rounded text-xs text-gray-400 hover:bg-gray-100"
                            >
                                {open ? '−' : '+'}
                            </button>
                        ) : (
                            <span className="inline-block h-5 w-5" aria-hidden="true" />
                        )}
                        <span className="font-medium text-[var(--color-text-primary)]">{node.label}</span>
                        {node.code && <span className="font-mono text-[10px] text-gray-400">{node.code}</span>}
                    </div>
                </td>
                <td className="text-right tabular-nums">{node.own ?? 0}</td>
                <td className="text-right tabular-nums">
                    {node.drill && onDrill !== false ? (
                        <button
                            type="button"
                            onClick={() => router.visit(route(node.drill.route, node.drill.params))}
                            className="font-semibold text-[var(--color-primary)] underline-offset-2 hover:underline"
                        >
                            {node.value}
                        </button>
                    ) : (
                        node.value
                    )}
                </td>
                <td>
                    <div className="flex items-center gap-2">
                        <span className="progress-bar flex-1">
                            <span
                                className="progress-bar-fill block"
                                style={{
                                    width: `${Math.min(100, node.pct ?? 0)}%`,
                                    background: node.tone ? STATUS[node.tone] : 'var(--chart-1)',
                                }}
                            />
                        </span>
                        <span className="w-10 text-right text-xs tabular-nums" style={{ color: INK.muted }}>
                            {Math.round(node.pct ?? 0)}%
                        </span>
                    </div>
                </td>
            </tr>

            {open && children.map((child) => (
                <Row key={child.id} node={child} depth={depth + 1} onDrill={onDrill} />
            ))}
        </>
    );
}
