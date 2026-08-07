const SEVERITY_CLASSES = {
    Critical: 'badge-critical',
    High: 'badge-high',
    Medium: 'badge-medium',
    Low: 'badge-low',
};

export default function SeverityBadge({ severity, className = '' }) {
    if (!severity) return <span className="text-gray-400">—</span>;

    return (
        <span className={`badge ${SEVERITY_CLASSES[severity] ?? 'badge-status-draft'} ${className}`}>
            {severity}
        </span>
    );
}
