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
export const TONES = {
    emerald: { tile: 'bg-emerald-50', icon: 'text-emerald-600', badge: 'bg-emerald-100 text-emerald-800', value: 'text-emerald-600' },
    blue: { tile: 'bg-blue-50', icon: 'text-blue-600', badge: 'bg-blue-100 text-blue-800', value: 'text-blue-600' },
    amber: { tile: 'bg-amber-50', icon: 'text-amber-600', badge: 'bg-amber-100 text-amber-800', value: 'text-amber-600' },
    red: { tile: 'bg-red-50', icon: 'text-red-600', badge: 'bg-red-100 text-red-800', value: 'text-red-600' },
    violet: { tile: 'bg-violet-50', icon: 'text-violet-600', badge: 'bg-violet-100 text-violet-800', value: 'text-violet-600' },
    slate: { tile: 'bg-slate-100', icon: 'text-slate-500', badge: 'bg-slate-100 text-slate-600', value: 'text-slate-500' },
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
