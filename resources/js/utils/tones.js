import {
    AlertTriangle,
    BadgeCheck,
    CheckCircle2,
    Circle,
    Clock,
    Eye,
    Shield,
    XCircle,
} from 'lucide-react';

/**
 * The one semantic colour mapping (§ design system). Every tinted icon
 * tile, status pill and severity accent derives from these six tones —
 * never from ad-hoc colour classes:
 *
 *   emerald  positive / effective / adequate / closed
 *   blue     informational / preventive / active / in progress
 *   amber    attention / medium / due soon / partially effective
 *   red      critical / ineffective / overdue / failed
 *   violet   detective / advisory / draft-adjacent classifications
 *   slate    inactive / retired / not applicable
 */
// Shades follow ThirdLine's tiles (x-50 / x-600) and badges (x-100 / x-700).
export const TONES = {
    emerald: { tile: 'bg-green-50', icon: 'text-green-600', badge: 'bg-green-100 text-green-700', value: 'text-green-600', dot: 'bg-green-500' },
    blue: { tile: 'bg-blue-50', icon: 'text-blue-600', badge: 'bg-blue-100 text-blue-700', value: 'text-blue-600', dot: 'bg-blue-500' },
    amber: { tile: 'bg-amber-50', icon: 'text-amber-600', badge: 'bg-yellow-100 text-yellow-800', value: 'text-amber-600', dot: 'bg-yellow-500' },
    red: { tile: 'bg-red-50', icon: 'text-red-600', badge: 'bg-red-100 text-red-700', value: 'text-red-600', dot: 'bg-red-500' },
    violet: { tile: 'bg-purple-50', icon: 'text-purple-600', badge: 'bg-purple-100 text-purple-700', value: 'text-purple-600', dot: 'bg-purple-500' },
    slate: { tile: 'bg-gray-100', icon: 'text-gray-500', badge: 'bg-gray-100 text-gray-700', value: 'text-gray-500', dot: 'bg-gray-400' },
};

const STATE_TONES = {
    emerald: [
        'positive', 'effective', 'adequate', 'closed', 'strong', 'passed', 'pass', 'approved',
        'completed', 'complete', 'resolved', 'verified', 'verified-closed', 'accepted',
        'notified', 'healthy', 'green', 'low', 'unsubstantiated', 'published', 'reviewed',
    ],
    blue: [
        'informational', 'preventive', 'active', 'in progress', 'scheduled', 'submitted',
        'pending', 'pending approval', 'pending review', 'under review', 'assigned', 'invited',
        'new', 'triaged', 'acknowledged', 'implemented', 'remediated', 'proposed', 'requested',
        'assessed', 'impact assessed', 'moderate',
    ],
    amber: [
        'attention', 'medium', 'due soon', 'partially effective', 'amber', 'warning', 'weak',
        'contained', 'under investigation', 'under revision', 'awaiting customer', 'reopened', 'high',
    ],
    red: [
        'critical', 'ineffective', 'inadequate', 'overdue', 'failed', 'fail', 'breached', 'red',
        'suspended', 'rejected', 'expired', 'reported', 'received', 'substantiated', 'referred',
        'escalated to regulator', 'severe', 'open',
    ],
    violet: ['detective', 'advisory', 'corrective', 'directive', 'draft'],
    slate: [
        'inactive', 'retired', 'n/a', 'not applicable', 'not started', 'not tested',
        'not assessed', 'not rated', 'not required', 'cancelled', 'superseded', 'withdrawn',
        'waived', 'risk accepted', 'unrated', 'none',
    ],
};

const TONE_BY_STATE = Object.fromEntries(
    Object.entries(STATE_TONES).flatMap(([tone, states]) => states.map((state) => [state, tone])),
);

/** Semantic tone for a status/rating word; slate when unknown. */
export function toneFor(state) {
    return TONE_BY_STATE[String(state ?? '').trim().toLowerCase()] ?? 'slate';
}

/** Default badge icon per tone — overridable per status below. */
const TONE_ICONS = {
    emerald: CheckCircle2,
    blue: Clock,
    amber: AlertTriangle,
    red: XCircle,
    violet: Eye,
    slate: Circle,
};

/** Statuses whose icon should say more than the tone default. */
const STATE_ICONS = {
    preventive: Shield,
    detective: Eye,
    'verified-closed': BadgeCheck,
    completed: BadgeCheck,
    approved: BadgeCheck,
};

export function iconFor(state) {
    return STATE_ICONS[String(state ?? '').trim().toLowerCase()] ?? TONE_ICONS[toneFor(state)];
}

/** The default icon for a tone — the StatCard fallback when no icon is given. */
export function toneIcon(tone) {
    return TONE_ICONS[tone] ?? Circle;
}
