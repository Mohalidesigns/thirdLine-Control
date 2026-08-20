/**
 * The chart design system, in one place.
 *
 * Colours are referenced as CSS custom properties, never as hex literals, so
 * the tokens in resources/css/app.css stay the single browser-side source of
 * truth (config/charts.php is its server-side twin for dompdf, PhpWord and
 * the PowerPoint writer).
 *
 * Three rules the components below depend on:
 *   · categorical slots are assigned in fixed order and never cycled — a
 *     ninth series folds into "Other" rather than inventing a hue;
 *   · a status tone (good → critical) is reserved and never doubles as
 *     series identity, and always travels with its label;
 *   · an ordered scale (ageing bands, tiers) takes the one-hue ordinal ramp,
 *     so the order is visible in the colour.
 */

export const CATEGORICAL = [
    'var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)',
    'var(--chart-5)', 'var(--chart-6)', 'var(--chart-7)', 'var(--chart-8)',
];

export const ORDINAL = [
    'var(--chart-ord-1)', 'var(--chart-ord-2)', 'var(--chart-ord-3)',
    'var(--chart-ord-4)', 'var(--chart-ord-5)',
];

export const SEQUENTIAL = [
    'var(--chart-seq-1)', 'var(--chart-seq-2)', 'var(--chart-seq-3)', 'var(--chart-seq-4)',
    'var(--chart-seq-5)', 'var(--chart-seq-6)', 'var(--chart-seq-7)',
];

export const STATUS = {
    good: 'var(--chart-good)',
    warning: 'var(--chart-warning)',
    serious: 'var(--chart-serious)',
    critical: 'var(--chart-critical)',
    muted: 'var(--chart-muted)',
};

export const INK = {
    primary: 'var(--chart-ink)',
    muted: 'var(--chart-ink-muted)',
    grid: 'var(--chart-grid)',
    axis: 'var(--chart-axis)',
    surface: 'var(--chart-surface)',
};

/** Mark specs, fixed across every chart in the product. */
export const MARKS = {
    barMaxThickness: 24,
    barRadius: 4,
    lineWidth: 2,
    markerRadius: 4,
    surfaceGap: 2,
    surfaceRing: 2,
};

/** Slot for a series: its declared slot, else its position. Never its rank. */
export function slotColour(series, index = 0) {
    const slot = Number.isInteger(series?.slot) ? series.slot - 1 : index;

    return CATEGORICAL[slot % CATEGORICAL.length];
}

/** The colour of one mark within a series. */
export function markColour(series, index = 0, seriesIndex = 0) {
    if (series?.tones?.[index]) {
        return STATUS[series.tones[index]] ?? STATUS.muted;
    }

    if (series?.tone) {
        return STATUS[series.tone] ?? slotColour(series, seriesIndex);
    }

    if (series?.ordinal) {
        return ORDINAL[index % ORDINAL.length];
    }

    return slotColour(series, seriesIndex);
}

/** A sequential step for a magnitude in 0..1 — heatmaps and choropleths. */
export function sequentialStep(ratio) {
    const clamped = Math.max(0, Math.min(1, Number.isFinite(ratio) ? ratio : 0));

    return SEQUENTIAL[Math.min(SEQUENTIAL.length - 1, Math.round(clamped * (SEQUENTIAL.length - 1)))];
}

/**
 * Compact value formatting. Proportional figures for hero numbers; the
 * tabular variant is reserved for columns that must align vertically.
 */
export function formatValue(value, unit, payload = {}) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (unit === 'money') {
        return payload.formatted ?? new Intl.NumberFormat('en-NG', {
            style: 'currency',
            currency: payload.currency || 'NGN',
            maximumFractionDigits: 0,
        }).format(Number(value) / 100);
    }

    if (unit === 'percent') {
        return `${Number(value).toLocaleString(undefined, { maximumFractionDigits: 1 })}%`;
    }

    const number = Number(value);

    if (!Number.isFinite(number)) {
        return String(value);
    }

    if (Math.abs(number) >= 1_000_000) {
        return `${(number / 1_000_000).toFixed(1).replace(/\.0$/, '')}M`;
    }

    if (Math.abs(number) >= 10_000) {
        return `${(number / 1000).toFixed(1).replace(/\.0$/, '')}K`;
    }

    return number.toLocaleString();
}

/** Axis ticks rounded to clean numbers, so the grid carries the values. */
export function niceTicks(max, count = 4) {
    if (!Number.isFinite(max) || max <= 0) {
        return [0, 1];
    }

    const rough = max / count;
    const magnitude = 10 ** Math.floor(Math.log10(rough));
    const step = [1, 2, 2.5, 5, 10].map((m) => m * magnitude).find((s) => s >= rough) ?? magnitude * 10;
    const top = Math.ceil(max / step) * step;
    const ticks = [];

    for (let value = 0; value <= top + step / 2; value += step) {
        ticks.push(Number(value.toFixed(6)));
    }

    return ticks;
}
