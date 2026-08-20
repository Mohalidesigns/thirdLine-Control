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
    // Risk management v2 (Phase 10)
    'Pending Review': 'badge-status-pending',
    Implemented: 'badge-status-pending',
    Verified: 'badge-status-completed',
    Cancelled: 'badge-status-draft',
    Superseded: 'badge-status-draft',
    Pending: 'badge-status-draft',
    Green: 'badge-low',
    Amber: 'badge-medium',
    Red: 'badge-high',
    Low: 'badge-low',
    High: 'badge-high',
    Critical: 'badge-critical',
    // Regulatory changes (Phase 8)
    New: 'badge-status-pending',
    'Impact Assessed': 'badge-status-pending',
    Actioned: 'badge-status-completed',
    'Not Applicable': 'badge-status-draft',
    // Policy, incident, complaints & cases (Phase 11)
    'Under Revision': 'badge-status-pending',
    Requested: 'badge-status-pending',
    Revoked: 'badge-status-draft',
    Reported: 'badge-status-overdue',
    Triaged: 'badge-status-pending',
    'Under Investigation': 'badge-status-pending',
    Contained: 'badge-status-pending',
    Received: 'badge-status-overdue',
    Acknowledged: 'badge-status-pending',
    'Awaiting Customer': 'badge-status-draft',
    Resolved: 'badge-status-completed',
    'Escalated to Regulator': 'badge-critical',
    Assessed: 'badge-status-pending',
    Substantiated: 'badge-critical',
    Unsubstantiated: 'badge-status-completed',
    Referred: 'badge-high',
    Notified: 'badge-status-completed',
    'Not Required': 'badge-status-draft',
    // User lifecycle (admin Users area)
    Invited: 'badge-status-pending',
    Suspended: 'badge-status-overdue',
};

export default function StatusBadge({ status, className = '' }) {
    if (!status) return <span className="text-gray-400">—</span>;

    return (
        <span className={`badge ${STATUS_CLASSES[status] ?? 'badge-status-draft'} ${className}`}>
            {status}
        </span>
    );
}
