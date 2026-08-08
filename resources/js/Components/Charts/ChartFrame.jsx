import { useState } from 'react';
import { INK, STATUS } from './palette';

/**
 * The shell every chart sits in.
 *
 * It owns the four states a chart can be in — loading, empty, refused,
 * broken — so no individual chart has to, and it owns the two things the
 * accessibility rules demand of every figure: a legend whenever there are two
 * or more series, and a table view of the same numbers. The table twin is not
 * optional here; it is what makes the lighter palette slots legal on a white
 * surface at all.
 */
export default function ChartFrame({
    title,
    subtitle,
    caption,
    payload = {},
    series = [],
    table = null,
    actions = null,
    children,
}) {
    const [showTable, setShowTable] = useState(false);

    const kind = payload.kind;

    if (kind === 'denied') {
        return (
            <Shell title={title} subtitle={subtitle}>
                <State
                    tone="muted"
                    heading="Not available to you"
                    body={payload.message ?? 'This tile draws on data your permissions do not cover.'}
                />
            </Shell>
        );
    }

    if (kind === 'error') {
        return (
            <Shell title={title} subtitle={subtitle}>
                <State
                    tone="critical"
                    heading="This tile could not be calculated"
                    body={payload.message ?? 'Try again, or check the underlying data source.'}
                />
            </Shell>
        );
    }

    const isEmpty = payload.empty === true;

    return (
        <Shell
            title={title}
            subtitle={subtitle}
            actions={
                <>
                    {actions}
                    {table?.rows?.length > 0 && (
                        <button
                            type="button"
                            onClick={() => setShowTable((value) => !value)}
                            className="text-xs font-medium text-[var(--color-primary)] underline-offset-2 hover:underline"
                            aria-pressed={showTable}
                        >
                            {showTable ? 'Chart' : 'Table'}
                        </button>
                    )}
                </>
            }
        >
            {isEmpty ? (
                <State
                    tone="muted"
                    heading="Nothing to show yet"
                    body="There is no data for this period. The tile will fill as records are created."
                />
            ) : (
                <>
                    {/* A legend is always present for two or more series — identity
                        must never depend on colour-matching alone. One series needs
                        none: the title already names what is plotted. */}
                    {series.length > 1 && !showTable && (
                        <ul className="mb-3 flex flex-wrap gap-x-4 gap-y-1">
                            {series.map((entry) => (
                                <li key={entry.key} className="flex items-center gap-1.5 text-xs" style={{ color: INK.muted }}>
                                    <span
                                        className="inline-block h-2.5 w-2.5 rounded-sm"
                                        style={{ background: entry.colour }}
                                        aria-hidden="true"
                                    />
                                    {entry.label}
                                </li>
                            ))}
                        </ul>
                    )}

                    {showTable && table ? <TableView table={table} /> : children}

                    {caption && (
                        <p className="mt-3 text-xs" style={{ color: INK.muted }}>
                            {caption}
                        </p>
                    )}
                </>
            )}
        </Shell>
    );
}

function Shell({ title, subtitle, actions, children }) {
    return (
        <section className="card flex h-full flex-col">
            <header className="flex items-start justify-between gap-3 border-b border-gray-100 px-5 py-3">
                <div className="min-w-0">
                    <h3 className="truncate text-sm font-semibold text-[var(--color-text-primary)]">{title}</h3>
                    {subtitle && <p className="truncate text-xs text-[var(--color-text-secondary)]">{subtitle}</p>}
                </div>
                <div className="flex shrink-0 items-center gap-3">{actions}</div>
            </header>
            <div className="flex-1 overflow-x-auto p-5">{children}</div>
        </section>
    );
}

function State({ tone, heading, body }) {
    return (
        <div className="flex h-full min-h-[120px] flex-col items-center justify-center text-center">
            <span
                className="mb-2 inline-flex h-8 w-8 items-center justify-center rounded-full text-sm font-bold text-white"
                style={{ background: STATUS[tone] ?? STATUS.muted }}
                aria-hidden="true"
            >
                {tone === 'critical' ? '!' : 'i'}
            </span>
            <p className="text-sm font-medium text-[var(--color-text-primary)]">{heading}</p>
            <p className="mt-1 max-w-xs text-xs text-[var(--color-text-secondary)]">{body}</p>
        </div>
    );
}

/** The WCAG-clean twin of whatever is plotted above it. */
export function TableView({ table }) {
    return (
        <div className="overflow-x-auto">
            <table className="data-table">
                <thead>
                    <tr>
                        {table.columns.map((column, index) => (
                            <th key={index}>{column}</th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {table.rows.map((row, rowIndex) => (
                        <tr key={rowIndex}>
                            {row.map((cell, cellIndex) => (
                                <td key={cellIndex} className={cellIndex === 0 ? '' : 'tabular-nums'}>
                                    {cell === null || cell === '' ? '—' : String(cell)}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
