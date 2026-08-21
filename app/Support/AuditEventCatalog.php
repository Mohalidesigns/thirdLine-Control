<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Single source of truth for turning machine event keys into the human
 * layer the Activity Log renders: a label and a badge class. Event keys
 * are stable evidence; labels are presentation and may be refined here
 * without touching stored rows (event_label is also denormalised at write
 * time so an export remains readable even if this map changes later).
 *
 * Badge classes: success (green, successful auth), danger (red,
 * failed/denied), warning (amber, escalation/overdue), neutral (slate,
 * generic CRUD), info (blue, workflow transitions & integration).
 */
class AuditEventCatalog
{
    private const LABELS = [
        // Auth
        'login' => ['Login', 'success'],
        'logout' => ['Logout', 'neutral'],
        'login_failed' => ['Failed Login', 'danger'],
        'login_locked_out' => ['Login Locked Out', 'danger'],
        'password_reset' => ['Password Reset', 'info'],
        'denied' => ['Access Denied', 'danger'],

        // Generic CRUD (Auditable trait)
        'created' => ['Created', 'neutral'],
        'updated' => ['Updated', 'neutral'],
        'deleted' => ['Deleted', 'neutral'],
        'restored' => ['Restored', 'neutral'],

        // Controls & library
        'approved' => ['Approved', 'info'],
        'retired' => ['Retired', 'info'],
        'controls-attached' => ['Controls Mapped', 'info'],
        'control-detached' => ['Control Unmapped', 'info'],
        'rated' => ['Effectiveness Rated', 'info'],
        'owner_reassigned' => ['Owner Reassigned', 'info'],

        // Controls & linkage
        'rejected' => ['Rejected', 'warning'],
        'assessed' => ['Assessed', 'info'],
        'linked' => ['Mapped', 'info'],
        'unlinked' => ['Unmapped', 'info'],

        // Testing, spot checks & evidence
        'test_started' => ['Test Started', 'info'],
        'test_submitted' => ['Test Submitted', 'info'],
        'review_signed_off' => ['Review Signed Off', 'info'],
        'returned_to_tester' => ['Returned To Tester', 'warning'],
        'reopened' => ['Reopened', 'warning'],
        'report_issued' => ['Report Issued', 'info'],
        'uploaded_chunked' => ['Evidence Uploaded', 'neutral'],
        'disposal_queued' => ['Disposal Queued', 'warning'],
        'disposal_first_approval' => ['Disposal First Approval', 'info'],
        'disposed' => ['Evidence Disposed', 'warning'],
        'legal_hold_applied' => ['Legal Hold Applied', 'warning'],
        'legal_hold_lifted' => ['Legal Hold Lifted', 'info'],

        // Exceptions & escalation
        'closed' => ['Closed', 'info'],
        'acknowledged' => ['Acknowledged', 'info'],
        'responded' => ['Response Submitted', 'info'],
        'completed' => ['Completed', 'info'],
        'issued' => ['Escalation Issued', 'warning'],
        'reissued' => ['Escalation Reissued', 'warning'],
        'response_accepted' => ['Escalation Response Accepted', 'info'],
        'response_rejected' => ['Escalation Response Rejected', 'warning'],
        'validation_failed' => ['Validation Failed', 'warning'],
        'withdrawn' => ['Withdrawn', 'neutral'],
        'escalation_fired' => ['Escalation Fired', 'warning'],
        'appetite_breached' => ['Appetite Breached', 'warning'],
        'appetite_restored' => ['Appetite Restored', 'success'],
        'acceptance_expired' => ['Acceptance Expired', 'warning'],

        // Integration & platform
        'received-from-integration' => ['Received From ThirdLine', 'info'],
        'published-to-integration' => ['Published To ThirdLine', 'info'],
        'connection_tested' => ['Connection Tested', 'info'],
        'circuit_breaker_tripped' => ['Circuit Breaker Tripped', 'warning'],
        'circuit_breaker_reset' => ['Circuit Breaker Reset', 'info'],
        'sso_login' => ['SSO Login', 'success'],
        'break_glass_login' => ['Break-Glass Login', 'warning'],
        'audit_log_exported' => ['Activity Log Exported', 'info'],
        'audit_log_archived' => ['Activity Log Archived', 'info'],
    ];

    public static function label(string $event): string
    {
        if (isset(self::LABELS[$event])) {
            return self::LABELS[$event][0];
        }

        // Readable fallback for domain keys ("report_schedule.created" →
        // "Report Schedule Created"). Never a raw route name: the request
        // fallback layer supplies its own "{METHOD} {path}" label instead.
        return Str::of($event)->replace(['.', '_', '-', ':'], ' ')->title()->toString();
    }

    public static function badgeClass(string $event): string
    {
        return self::LABELS[$event][1] ?? 'neutral';
    }
}
