const SEVERITY = {
    Critical: { badge: 'badge-critical', dot: 'bg-red-500' },
    High: { badge: 'badge-high', dot: 'bg-orange-500' },
    Medium: { badge: 'badge-medium', dot: 'bg-yellow-500' },
    Low: { badge: 'badge-low', dot: 'bg-green-500' },
};

/** Severity pill with ThirdLine's leading status dot. */
export default function SeverityBadge({ severity, className = '' }) {
    if (!severity) return <span className="text-gray-400">—</span>;

    const style = SEVERITY[severity];

    return (
        <span className={`badge ${style?.badge ?? 'badge-status-draft'} ${className}`}>
            <span className={`mr-1.5 h-1.5 w-1.5 rounded-full ${style?.dot ?? 'bg-gray-400'}`} aria-hidden="true" />
            {severity}
        </span>
    );
}
