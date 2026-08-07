const CLASSES = {
    verified: 'badge-low',
    unverified: 'badge-medium',
    draft: 'badge-status-draft',
};

const TITLES = {
    verified: 'Confirmed against the regulator’s primary document — usable in a generated submission.',
    unverified: 'Not yet confirmed against the regulator’s primary document — excluded from generated submissions.',
    draft: 'Shell record pending research — excluded from generated submissions.',
};

/**
 * Rule R10 made visible: anything not "verified" is badged everywhere it
 * appears and never reaches a regulatory filing.
 */
export default function VerificationBadge({ status, className = '' }) {
    if (!status) return null;

    return (
        <span className={`badge ${CLASSES[status] ?? 'badge-status-draft'} ${className}`} title={TITLES[status] ?? ''}>
            {status === 'verified' ? 'Verified' : status === 'draft' ? 'Draft' : 'Unverified'}
        </span>
    );
}
