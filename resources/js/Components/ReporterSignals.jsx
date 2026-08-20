import { AlertTriangle, Clock, Fingerprint, Globe, ShieldQuestion, Timer } from 'lucide-react';

/**
 * The "Reporter Signals" card body (CR §4): compact coloured-icon tiles in
 * the ThirdLine dashboard style. Tier 1 only — correlation counts and
 * anomaly indicators, never an identifying value. The parent card must
 * keep the standing caption that a signal is decision support, not
 * evidence of a false report.
 */
export default function ReporterSignals({ signals }) {
    if (!signals) return null;

    const prior = signals.prior_reports;
    const anomalies = signals.anomalies ?? {};

    const tiles = [];

    if (prior) {
        tiles.push({
            icon: Fingerprint,
            tone: prior.total > 0 ? 'amber' : 'green',
            label: 'Prior reports from this device',
            value: `${prior.total}`,
            detail: prior.total > 0 ? outcomeSummary(prior.outcomes) : 'None recorded',
        });

        tiles.push({
            icon: Clock,
            tone: prior.last_7d > 1 ? 'amber' : 'gray',
            label: 'Submission velocity',
            value: `${prior.last_24h} / ${prior.last_7d} / ${prior.last_30d}`,
            detail: 'last 24h / 7d / 30d',
        });

        if (prior.previously_unsubstantiated) {
            tiles.push({
                icon: ShieldQuestion,
                tone: 'red',
                label: 'History flag',
                value: 'Unsubstantiated history',
                detail: 'A prior report from this device closed unsubstantiated',
            });
        }
    } else {
        tiles.push({
            icon: Fingerprint,
            tone: 'gray',
            label: 'Device correlation',
            value: 'Unavailable',
            detail: 'No fingerprint could be derived for this submission',
        });
    }

    if (anomalies.fast_submission) {
        tiles.push({
            icon: Timer,
            tone: 'amber',
            label: 'Fast submission',
            value: 'Under 20 seconds',
            detail: 'Possible bot or copy-paste submission',
        });
    }

    if (anomalies.datacentre_or_vpn) {
        tiles.push({
            icon: AlertTriangle,
            tone: 'amber',
            label: 'Network origin',
            value: 'Datacentre / VPN / proxy',
            detail: 'The connection resolves to a hosting or anonymising network',
        });
    }

    if (anomalies.geo_timezone_mismatch) {
        tiles.push({
            icon: Globe,
            tone: 'amber',
            label: 'Location consistency',
            value: 'Timezone ≠ network country',
            detail: "The device's timezone does not match the geo-resolved country",
        });
    }

    return (
        <div className="grid grid-cols-1 gap-2">
            {tiles.map((tile) => (
                <Tile key={tile.label} {...tile} />
            ))}
        </div>
    );
}

const TONES = {
    green: 'bg-green-50 text-green-700',
    amber: 'bg-amber-50 text-amber-700',
    red: 'bg-red-50 text-red-700',
    gray: 'bg-gray-50 text-gray-500',
};

function Tile({ icon: Icon, tone, label, value, detail }) {
    return (
        <div className="flex items-start gap-3 rounded-lg border border-gray-100 p-3">
            <span className={`rounded-lg p-2 ${TONES[tone] ?? TONES.gray}`}>
                <Icon className="h-4 w-4" />
            </span>
            <span className="min-w-0">
                <span className="block text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">{label}</span>
                <span className="block text-sm font-semibold text-[var(--color-text-primary)]">{value}</span>
                {detail && <span className="block text-xs text-gray-400">{detail}</span>}
            </span>
        </div>
    );
}

function outcomeSummary(outcomes = {}) {
    const parts = [];
    if (outcomes.substantiated) parts.push(`${outcomes.substantiated} substantiated`);
    if (outcomes.unsubstantiated) parts.push(`${outcomes.unsubstantiated} unsubstantiated`);
    if (outcomes.closed) parts.push(`${outcomes.closed} closed`);
    if (outcomes.open) parts.push(`${outcomes.open} open`);
    return parts.join(' · ') || 'No outcomes recorded';
}
