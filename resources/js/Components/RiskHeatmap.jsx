import { useMemo, useState } from 'react';

/**
 * N×N risk heatmap. The grid, the levels and every cell colour come from
 * the server (risk_assessment_scales) — nothing here assumes 5×5 (R1).
 *
 * Meaning is never carried by colour alone: each cell shows its band label
 * beside its fill, the legend names every band, and a table view renders
 * the same numbers without colour at all.
 */
export default function RiskHeatmap({ heatmap, onCellClick, selected = null }) {
    const { likelihood = [], impact = [], cells = [], bands = [], movement = [], view } = heatmap ?? {};
    const [showTable, setShowTable] = useState(false);

    const cellAt = useMemo(() => {
        const map = new Map();
        cells.forEach((cell) => map.set(`${cell.likelihood}:${cell.impact}`, cell));
        return map;
    }, [cells]);

    const maxFinancial = useMemo(
        () => Math.max(1, ...cells.map((cell) => cell.financial_total_minor ?? 0)),
        [cells],
    );

    // Impact runs up the y-axis, so the highest level is the top row.
    const rows = [...impact].sort((a, b) => b.level - a.level);

    if (likelihood.length === 0 || impact.length === 0) {
        return (
            <p className="p-6 text-sm text-[var(--color-text-secondary)]">
                No assessment scales are configured for this tenant yet.
            </p>
        );
    }

    return (
        <div>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                <Legend bands={bands} />
                <button
                    type="button"
                    onClick={() => setShowTable((value) => !value)}
                    className="text-xs font-semibold text-[var(--color-primary)] underline-offset-2 hover:underline"
                >
                    {showTable ? 'Show grid' : 'Show as table'}
                </button>
            </div>

            {showTable ? (
                <TableView rows={rows} likelihood={likelihood} cellAt={cellAt} />
            ) : (
                <div className="overflow-x-auto">
                    <div className="flex min-w-[560px] gap-2">
                        <AxisLabel text="Impact" vertical />

                        <div className="flex-1">
                            <div
                                className="grid gap-1"
                                style={{ gridTemplateColumns: `minmax(84px, 120px) repeat(${likelihood.length}, minmax(0, 1fr))` }}
                            >
                                {rows.map((row) => (
                                    <Row
                                        key={row.level}
                                        row={row}
                                        likelihood={likelihood}
                                        cellAt={cellAt}
                                        maxFinancial={maxFinancial}
                                        movement={movement}
                                        view={view}
                                        onCellClick={onCellClick}
                                        selected={selected}
                                    />
                                ))}

                                <div />
                                {likelihood.map((column) => (
                                    <div
                                        key={column.level}
                                        className="px-1 pt-2 text-center text-[11px] font-medium leading-tight text-[var(--color-text-secondary)]"
                                        title={column.description ?? undefined}
                                    >
                                        {column.label}
                                    </div>
                                ))}
                            </div>

                            <p className="mt-2 text-center text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                                Likelihood
                            </p>
                        </div>
                    </div>
                </div>
            )}

            {view === 'movement' && movement.length > 0 && !showTable && (
                <MovementList movement={movement} />
            )}
        </div>
    );
}

function Row({ row, likelihood, cellAt, maxFinancial, movement, view, onCellClick, selected }) {
    return (
        <>
            <div
                className="flex items-center justify-end pe-2 text-end text-[11px] font-medium leading-tight text-[var(--color-text-secondary)]"
                title={row.description ?? undefined}
            >
                {row.label}
            </div>
            {likelihood.map((column) => {
                const cell = cellAt.get(`${column.level}:${row.level}`);
                const isSelected =
                    selected && selected.likelihood === column.level && selected.impact === row.level;

                return (
                    <Cell
                        key={`${column.level}:${row.level}`}
                        cell={cell}
                        maxFinancial={maxFinancial}
                        movement={view === 'movement' ? movement : []}
                        isSelected={isSelected}
                        onClick={onCellClick}
                    />
                );
            })}
        </>
    );
}

function Cell({ cell, maxFinancial, movement, isSelected, onClick }) {
    if (!cell) return <div />;

    const count = cell.count ?? 0;
    const bubble = count > 0 ? 10 + Math.round(22 * Math.sqrt((cell.financial_total_minor ?? 0) / maxFinancial)) : 0;

    const arrivals = movement.filter(
        (m) => m.to.likelihood === cell.likelihood && m.to.impact === cell.impact,
    ).length;
    const departures = movement.filter(
        (m) => m.from.likelihood === cell.likelihood && m.from.impact === cell.impact,
    ).length;

    const label = `${cell.band} · score ${cell.score} · ${count} risk${count === 1 ? '' : 's'}`;

    return (
        <button
            type="button"
            onClick={() => count > 0 && onClick?.(cell)}
            disabled={count === 0}
            aria-label={label}
            title={label}
            className={`group relative flex min-h-[72px] flex-col justify-between rounded-lg border p-1.5 text-start transition-shadow duration-200 ${
                count > 0 ? 'cursor-pointer hover:shadow-md' : 'cursor-default'
            } ${isSelected ? 'ring-2 ring-[var(--color-primary)] ring-offset-1' : ''}`}
            style={{
                backgroundColor: tint(cell.colour, 0.2),
                borderColor: tint(cell.colour, 0.55),
            }}
        >
            <span className="text-[10px] font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                {cell.band}
            </span>

            <span className="flex items-center justify-center">
                {count > 0 ? (
                    <span
                        className="flex items-center justify-center rounded-full text-xs font-bold text-white"
                        style={{
                            width: bubble,
                            height: bubble,
                            minWidth: 22,
                            minHeight: 22,
                            backgroundColor: cell.colour,
                        }}
                    >
                        {count}
                    </span>
                ) : (
                    <span className="text-xs text-gray-300">—</span>
                )}
            </span>

            <span className="flex items-center justify-between text-[10px] text-[var(--color-text-secondary)]">
                <span>{cell.score}</span>
                <span className="flex gap-1">
                    {cell.breach_count > 0 && (
                        <span className="font-bold text-[var(--color-error)]" title={`${cell.breach_count} outside appetite`}>
                            !{cell.breach_count}
                        </span>
                    )}
                    {arrivals > 0 && <span title={`${arrivals} target position(s)`}>→{arrivals}</span>}
                    {departures > 0 && <span title={`${departures} risk(s) moving out`}>{departures}→</span>}
                </span>
            </span>
        </button>
    );
}

function Legend({ bands }) {
    if (!bands?.length) return null;

    return (
        <div className="flex flex-wrap items-center gap-3">
            {bands.map((band) => (
                <span key={band.level} className="flex items-center gap-1.5 text-xs text-[var(--color-text-secondary)]">
                    <span
                        className="inline-block h-3 w-3 rounded-sm border"
                        style={{ backgroundColor: tint(band.colour, 0.35), borderColor: band.colour }}
                    />
                    <span className="font-medium text-[var(--color-text-primary)]">{band.label}</span>
                    <span className="tabular-nums">
                        {band.score_from ?? 0}–{band.score_to ?? '∞'}
                    </span>
                </span>
            ))}
        </div>
    );
}

function TableView({ rows, likelihood, cellAt }) {
    return (
        <div className="overflow-x-auto">
            <table className="data-table">
                <thead>
                    <tr>
                        <th>Impact \ Likelihood</th>
                        {likelihood.map((column) => (
                            <th key={column.level}>{column.label}</th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.level}>
                            <td className="font-medium">{row.label}</td>
                            {likelihood.map((column) => {
                                const cell = cellAt.get(`${column.level}:${row.level}`);
                                return (
                                    <td key={column.level} className="tabular-nums">
                                        {cell ? `${cell.count} · ${cell.band}` : '—'}
                                    </td>
                                );
                            })}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function MovementList({ movement }) {
    return (
        <div className="mt-4 rounded-lg border border-gray-100 bg-gray-50 p-3">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                Residual → target movement
            </p>
            <ul className="space-y-1 text-xs text-[var(--color-text-primary)]">
                {movement.map((item) => (
                    <li key={item.id} className="flex flex-wrap items-center gap-1.5">
                        <span className="font-mono font-semibold text-[var(--color-primary)]">{item.code}</span>
                        <span className="text-[var(--color-text-secondary)]">{item.title}</span>
                        <span className="tabular-nums">
                            {item.from.band} (L{item.from.likelihood}/I{item.from.impact}) → {item.to.band} (L
                            {item.to.likelihood}/I{item.to.impact})
                        </span>
                        {item.gap !== null && item.gap > 0 && (
                            <span className="font-semibold text-[var(--color-warning)]">gap {item.gap}</span>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function AxisLabel({ text }) {
    return (
        <div className="flex items-center">
            <span
                className="whitespace-nowrap text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]"
                style={{ writingMode: 'vertical-rl', transform: 'rotate(180deg)' }}
            >
                {text}
            </span>
        </div>
    );
}

/** Hex + alpha over the card surface, so cell text keeps its contrast. */
function tint(hex, alpha) {
    if (!hex || hex.length !== 7) return `rgba(225, 224, 217, ${alpha})`;
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}
