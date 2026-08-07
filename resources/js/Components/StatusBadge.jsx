const STATUS_CLASSES = {
    // Generic lifecycle
    Draft: 'badge-status-draft',
    'Pending Approval': 'badge-status-pending',
    Active: 'badge-status-active',
    'Under Review': 'badge-status-pending',
    Retired: 'badge-status-draft',
    // Testing
    Scheduled: 'badge-status-pending',
    'In Progress': 'badge-status-pending',
    Submitted: 'badge-status-pending',
    Reviewed: 'badge-status-completed',
    Closed: 'badge-status-completed',
    Reopened: 'badge-status-overdue',
    // Exceptions
    Open: 'badge-status-overdue',
    Assigned: 'badge-status-pending',
    Remediated: 'badge-status-pending',
    'Verified-Closed': 'badge-status-completed',
    'Risk Accepted': 'badge-status-draft',
    // Spot checks / misc
    Completed: 'badge-status-completed',
    'Report Issued': 'badge-status-completed',
    Proposed: 'badge-status-pending',
    Approved: 'badge-status-active',
    Expired: 'badge-status-overdue',
    Withdrawn: 'badge-status-draft',
    Published: 'badge-status-active',
    // Ratings
    Effective: 'badge-low',
    'Partially Effective': 'badge-medium',
    Ineffective: 'badge-critical',
    'Not Tested': 'badge-status-draft',
    // Design effectiveness
    Adequate: 'badge-low',
    Inadequate: 'badge-critical',
    'Not Assessed': 'badge-status-draft',
    // Overall score
    Strong: 'badge-low',
    Moderate: 'badge-medium',
    Weak: 'badge-critical',
    // Obligation instances (Phase 8)
    'Not Started': 'badge-status-draft',
    Overdue: 'badge-status-overdue',
    Accepted: 'badge-status-completed',
    Rejected: 'badge-critical',
    Waived: 'badge-status-draft',
    // Regulatory changes (Phase 8)
    New: 'badge-status-pending',
    'Impact Assessed': 'badge-status-pending',
    Actioned: 'badge-status-completed',
    'Not Applicable': 'badge-status-draft',
};

export default function StatusBadge({ status, className = '' }) {
    if (!status) return <span className="text-gray-400">—</span>;

    return (
        <span className={`badge ${STATUS_CLASSES[status] ?? 'badge-status-draft'} ${className}`}>
            {status}
        </span>
    );
}
