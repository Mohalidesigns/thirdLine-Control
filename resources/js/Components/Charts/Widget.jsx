import BarChart from './BarChart';
import ChartFrame from './ChartFrame';
import DonutChart from './DonutChart';
import GaugeChart from './GaugeChart';
import HeatmapChart from './HeatmapChart';
import LineChart from './LineChart';
import ProgressList from './ProgressList';
import RowsTable from './RowsTable';
import StatTile from './StatTile';
import TreeTable from './TreeTable';
import { formatValue, markColour, slotColour } from './palette';

/**
 * One tile: payload in, figure out. The dispatcher is the only place that
 * knows which widget type maps to which mark, which is what keeps seventeen
 * tile types looking like one system.
 *
 * A stat tile is the exception to the frame: it is a number, not a plot, so
 * it renders bare rather than inside a card with a legend row it will never
 * use.
 */
export default function Widget({ widget, onDrill }) {
    const payload = widget.data ?? {};
    const type = widget.type;

    if (payload.kind === 'text') {
        return (
            <ChartFrame title={widget.title} subtitle={widget.subtitle} payload={payload}>
                <p className="whitespace-pre-line text-sm text-[var(--color-text-primary)]">{payload.body}</p>
            </ChartFrame>
        );
    }

    // A bare number belongs on a tile, not in a chart frame — but a refusal
    // or a failure still needs the frame's explanation.
    if (type === 'stat' && payload.kind === 'scalar') {
        return <StatTile payload={payload} title={widget.title} subtitle={widget.subtitle} />;
    }

    const legend = legendFor(payload);
    const table = tableFor(payload);

    return (
        <ChartFrame
            title={widget.title}
            subtitle={widget.subtitle}
            caption={payload.caption}
            payload={payload}
            series={legend}
            table={table}
        >
            {body(type, payload, onDrill)}
        </ChartFrame>
    );
}

function body(type, payload, onDrill) {
    if (payload.kind === 'matrix') {
        return <HeatmapChart payload={payload} onDrill={onDrill} />;
    }

    if (payload.kind === 'tree') {
        return <TreeTable payload={payload} onDrill={onDrill} />;
    }

    if (payload.kind === 'rows') {
        return <RowsTable payload={payload} onDrill={onDrill} />;
    }

    if (payload.kind === 'scalar') {
        return type === 'gauge' || type === 'progress'
            ? <GaugeChart payload={payload} />
            : <StatTile payload={payload} title="" />;
    }

    switch (type) {
        case 'donut':
        case 'pie':
            return <DonutChart payload={payload} onDrill={onDrill} />;
        case 'line':
        case 'sparkline':
            return <LineChart payload={payload} />;
        case 'horizontal_bar':
            return <BarChart payload={payload} orientation="horizontal" onDrill={onDrill} />;
        case 'stacked_bar':
            return <BarChart payload={payload} stacked onDrill={onDrill} />;
        case 'progress':
        case 'scorecard':
            return <ProgressList payload={payload} onDrill={onDrill} />;
        case 'gauge':
            return <GaugeChart payload={payload} />;
        case 'table':
            return <RowsTable payload={payload} onDrill={onDrill} />;
        default:
            return <BarChart payload={payload} stacked={payload.stacked === true} onDrill={onDrill} />;
    }
}

/**
 * The legend entries the frame renders. A single-series chart returns none —
 * the title already names what is plotted, and a one-swatch box restates it.
 */
function legendFor(payload) {
    const series = payload.series ?? [];

    if (payload.kind !== 'series' || series.length < 2) {
        return [];
    }

    return series.map((one, index) => ({
        key: one.key,
        label: one.label,
        colour: one.tone ? markColour(one, 0, index) : slotColour(one, index),
    }));
}

/** The table twin, built from the same payload the figure was drawn from. */
function tableFor(payload) {
    if (payload.kind === 'series') {
        return {
            columns: ['', ...(payload.series ?? []).map((one) => one.label)],
            rows: (payload.categories ?? []).map((category, index) => [
                category.label,
                ...(payload.series ?? []).map((one) => formatValue(one.values[index] ?? 0, payload.unit, payload)),
            ]),
        };
    }

    if (payload.kind === 'rows') {
        return {
            columns: (payload.columns ?? []).map((column) => column.label),
            rows: (payload.rows ?? []).map((row) => row.cells),
        };
    }

    if (payload.kind === 'matrix') {
        const cells = payload.matrix?.cells ?? [];

        return {
            columns: ['', ...(payload.matrix?.x ?? []).map((column) => column.label)],
            rows: (payload.matrix?.y ?? []).map((row) => [
                row.label,
                ...(payload.matrix?.x ?? []).map((column) =>
                    cells.find((cell) => cell.x === column.key && cell.y === row.key)?.value ?? 0),
            ]),
        };
    }

    if (payload.kind === 'tree') {
        const rows = [];

        const walk = (nodes, depth) => nodes.forEach((node) => {
            rows.push([`${'— '.repeat(depth)}${node.label}`, node.own ?? 0, node.value ?? 0]);
            walk(node.children ?? [], depth + 1);
        });

        walk(payload.tree ?? [], 0);

        return { columns: ['Unit', 'Own', 'Rolled up'], rows };
    }

    return null;
}
