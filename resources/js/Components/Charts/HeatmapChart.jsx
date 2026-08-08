import { router } from '@inertiajs/react';
import { ChartHost, TooltipLayer, useTooltip } from './Tooltip';
import { INK, sequentialStep } from './palette';

/**
 * A matrix of counts. Cells keep the tenant's own configured band colours
 * where the source supplies them — a board that has agreed what "red" means
 * on its risk matrix should not have a visualisation layer repaint it — and
 * fall back to the one-hue sequential ramp where it does not.
 *
 * The count is printed in every cell, so magnitude never depends on the fill
 * alone, and the ink colour flips by fill luminance so it always clears
 * contrast.
 */
export default function HeatmapChart({ payload, onDrill }) {
    const { tip, handlers } = useTooltip();

    const matrix = payload.matrix;

    if (!matrix?.x?.length || !matrix?.y?.length) {
        return null;
    }

    const cells = matrix.cells ?? [];
    const max = Math.max(1, ...cells.map((cell) => Number(cell.value ?? 0)));
    const find = (x, y) => cells.find((cell) => cell.x === x && cell.y === y);

    return (
        <ChartHost>
            <TooltipLayer tip={tip} />
            <div className="overflow-x-auto">
                <table className="w-full border-separate" style={{ borderSpacing: 2 }}>
                    <tbody>
                        {[...matrix.y].reverse().map((row) => (
                            <tr key={row.key}>
                                <th
                                    scope="row"
                                    className="w-24 pe-2 text-right text-[10px] font-medium"
                                    style={{ color: INK.muted }}
                                >
                                    {row.label}
                                </th>
                                {matrix.x.map((column) => {
                                    const cell = find(column.key, row.key);
                                    const value = Number(cell?.value ?? 0);
                                    const fill = cell?.colour ?? sequentialStep(value / max);
                                    const ink = value > 0 && isDark(cell?.colour) ? '#FFFFFF' : INK.primary;

                                    return (
                                        <td key={column.key} className="p-0">
                                            <button
                                                type="button"
                                                disabled={!cell?.drill}
                                                onClick={() => cell?.drill && onDrill !== false
                                                    && router.visit(route(cell.drill.route, cell.drill.params))}
                                                aria-label={`${row.label} × ${column.label}: ${value} (${cell?.label ?? 'no band'})`}
                                                className="flex h-12 w-full items-center justify-center rounded-md text-sm font-semibold outline-none transition-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)] disabled:cursor-default"
                                                style={{ background: value > 0 ? fill : '#F7FAFC', color: value > 0 ? ink : INK.muted }}
                                                {...handlers(`${row.label} × ${column.label}: ${value} risk(s) · ${cell?.label ?? '—'}`)}
                                            >
                                                {value}
                                            </button>
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                        <tr>
                            <td />
                            {matrix.x.map((column) => (
                                <td key={column.key} className="pt-1 text-center text-[10px]" style={{ color: INK.muted }}>
                                    {column.label}
                                </td>
                            ))}
                        </tr>
                    </tbody>
                </table>
            </div>

            {matrix.legend?.length > 0 && (
                <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs" style={{ color: INK.muted }}>
                    {matrix.legend.map((band) => (
                        <li key={band.band ?? band.label} className="flex items-center gap-1.5">
                            <span
                                className="inline-block h-2.5 w-2.5 rounded-sm"
                                style={{ background: band.colour ?? band.color }}
                                aria-hidden="true"
                            />
                            {band.band ?? band.label}
                        </li>
                    ))}
                </ul>
            )}
        </ChartHost>
    );
}

/** Relative luminance, so an in-cell label always clears contrast. */
function isDark(hex) {
    if (typeof hex !== 'string' || !/^#[0-9a-f]{6}$/i.test(hex)) {
        return true;
    }

    const r = parseInt(hex.slice(1, 3), 16) / 255;
    const g = parseInt(hex.slice(3, 5), 16) / 255;
    const b = parseInt(hex.slice(5, 7), 16) / 255;

    return 0.2126 * r + 0.7152 * g + 0.0722 * b < 0.55;
}
