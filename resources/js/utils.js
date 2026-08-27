export function classNames(...classes) {
    return classes.filter(Boolean).join(' ');
}

/**
 * Tenant localisation (R7). Defaults match the platform defaults; app.jsx
 * overwrites them from the shared `localisation` Inertia prop on every
 * page load, so all formatting below follows the tenant's settings.
 */
const localisation = {
    locale: 'en-NG',
    timezone: 'Africa/Lagos',
    currency: 'NGN',
    date_format: 'DD MMM YYYY',
    fiscal_year_start_month: 1,
};

export function configureLocalisation(config) {
    if (config && typeof config === 'object') Object.assign(localisation, config);
}

export function getLocalisation() {
    return { ...localisation };
}

const DATE_FORMAT_OPTIONS = {
    'DD MMM YYYY': { day: '2-digit', month: 'short', year: 'numeric' },
    'DD/MM/YYYY': { day: '2-digit', month: '2-digit', year: 'numeric' },
    'MM/DD/YYYY': { month: '2-digit', day: '2-digit', year: 'numeric' },
    'YYYY-MM-DD': { year: 'numeric', month: '2-digit', day: '2-digit' },
};

/**
 * A calendar date has no instant, so it must not be converted between
 * timezones. `2026-07-28` names the 28th everywhere; parsing it with
 * `new Date()` yields UTC midnight, and rendering that in a timezone
 * behind UTC shows the 27th. Date-only values are therefore formatted
 * from their own parts, and only true timestamps are localised.
 */
const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;

export function formatDate(value) {
    if (!value) return '—';
    const options = DATE_FORMAT_OPTIONS[localisation.date_format] ?? DATE_FORMAT_OPTIONS['DD MMM YYYY'];

    if (typeof value === 'string' && DATE_ONLY.test(value)) {
        const [year, month, day] = value.split('-').map(Number);
        // UTC parts + a UTC render: the calendar date survives intact.
        return new Date(Date.UTC(year, month - 1, day)).toLocaleDateString(localisation.locale, {
            ...options,
            timeZone: 'UTC',
        });
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return date.toLocaleDateString(localisation.locale, { ...options, timeZone: localisation.timezone });
}

export function formatDateTime(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return `${formatDate(value)} ${date.toLocaleTimeString(localisation.locale, {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: localisation.timezone,
    })}`;
}

/**
 * Format a money value stored as integer minor units + ISO-4217 code
 * ({ amount, currency }) or a raw minor-unit integer in the tenant currency.
 */
export function formatMoney(value, currency = null) {
    if (value === null || value === undefined) return '—';
    const amount = typeof value === 'object' ? value.amount : value;
    const code = (typeof value === 'object' ? value.currency : currency) ?? localisation.currency;
    if (amount === null || amount === undefined) return '—';
    return new Intl.NumberFormat(localisation.locale, {
        style: 'currency',
        currency: code,
        maximumFractionDigits: 2,
    }).format(amount / 100);
}

/** Major-unit amounts (legacy call sites). Prefer formatMoney for minor units. */
export function formatCurrency(amount, currency = null) {
    if (amount === null || amount === undefined) return '—';
    return new Intl.NumberFormat(localisation.locale, {
        style: 'currency',
        currency: currency ?? localisation.currency,
        maximumFractionDigits: 2,
    }).format(amount);
}

export function formatNumber(value) {
    if (value === null || value === undefined) return '—';
    return new Intl.NumberFormat(localisation.locale).format(value);
}

export function daysUntil(value) {
    if (!value) return null;
    const target = new Date(value);
    if (Number.isNaN(target.getTime())) return null;
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    target.setHours(0, 0, 0, 0);
    return Math.round((target - today) / 86400000);
}

export function truncate(text, length = 80) {
    if (!text) return '';
    return text.length > length ? `${text.slice(0, length)}…` : text;
}
