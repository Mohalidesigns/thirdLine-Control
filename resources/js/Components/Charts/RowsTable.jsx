import { router } from '@inertiajs/react';
import { INK, STATUS } from './palette';

/**
 * A ranked or listed dataset. Rows carry an optional status tone, shown as a
 * labelled pill rather than a coloured row — a tinted row tells a
 * colour-blind reader nothing and prints as grey.
 */
export default function RowsTable({ payload, onDrill, limit = 12 }) {
    const columns = payload.columns ?? [];
    const rows = (payload.rows ?? []).slice(0, limit);

    if (columns.length === 0 || rows.length === 0) {
        return null;
    }

    return (
        <div className="overflow-x-auto">
            <table className="data-table">
                <thead>
                    <tr>
                        {columns.map((column) => (
                            <th key={column.key} className={column.align === 'right' ? 'text-right' : ''}>
                                {column.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, rowIndex) => (
                        <tr
                            key={rowIndex}
                            className={row.drill && onDrill !== false ? 'cursor-pointer' : ''}
                            onClick={() => row.drill && onDrill !== false
                                && router.visit(route(row.drill.route, row.drill.params))}
                        >
                            {row.cells.map((cell, cellIndex) => (
                                <td
                                    key={cellIndex}
                                    className={columns[cellIndex]?.align === 'right' ? 'text-right tabular-nums' : ''}
                                >
                                    {cellIndex === 0 && row.tone && (
                                        <span
                                            className="me-2 inline-block h-2 w-2 rounded-full align-middle"
                                            style={{ background: STATUS[row.tone] ?? STATUS.muted }}
                                            aria-hidden="true"
                                        />
                                    )}
                                    {cell === null || cell === '' ? '—' : String(cell)}
                                    {cellIndex === row.cells.length - 1 && row.note && (
                                        <span className="ms-2 text-[10px]" style={{ color: INK.muted }}>
                                            {row.note}
                                        </span>
                                    )}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>

            {(payload.rows?.length ?? 0) > limit && (
                <p className="mt-2 text-xs" style={{ color: INK.muted }}>
                    Showing {limit} of {payload.rows.length}. Open the full list for the rest.
                </p>
            )}
        </div>
    );
}
