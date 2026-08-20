<?php

namespace Database\Seeders;

use App\Models\NotificationEvent;
use Illuminate\Database\Seeder;

/**
 * The platform notification event catalogue (rule R1: events are seeded
 * data, never hard-coded). Later phases append their own events.
 */
class NotificationEventSeeder extends Seeder
{
    private const EVENTS = [
        [
            'key' => 'escalation.raised',
            'label' => 'Escalation raised to you',
            'description' => 'An exception or overdue test has been escalated to you by the escalation matrix.',
            'category' => 'escalations',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => true,
        ],
        [
            'key' => 'owner.digest',
            'label' => 'Open exceptions digest',
            'description' => 'Periodic summary of your open and overdue exceptions.',
            'category' => 'digests',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => true,
        ],
        [
            'key' => 'security.mfa-policy-changed',
            'label' => 'MFA policy changed',
            'description' => 'Multi-factor authentication enforcement was changed for a role. Sent to all System Administrators.',
            'category' => 'security',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => false,
        ],
        [
            'key' => 'security.sso-config-changed',
            'label' => 'SSO configuration changed',
            'description' => 'A single sign-on configuration was created, updated, or approved.',
            'category' => 'security',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => false,
        ],
        [
            'key' => 'obligation.reminder',
            'label' => 'Regulatory obligation falling due',
            'description' => 'Graduated reminder ahead of a filing or disclosure deadline you own.',
            'category' => 'obligations',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => true,
        ],
        [
            'key' => 'obligation.overdue',
            'label' => 'Regulatory obligation overdue',
            'description' => 'A filing you own has passed its deadline and is accruing penalty exposure.',
            'category' => 'obligations',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => false,
        ],
        [
            'key' => 'regulatory-change.published',
            'label' => 'Regulator published something new',
            'description' => 'A circular, guideline or directive was picked up from a regulator feed and needs impact assessment.',
            'category' => 'obligations',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => true,
        ],
        [
            'key' => 'security.break-glass-login',
            'label' => 'Break-glass login used',
            'description' => 'A break-glass local login occurred while SSO is enforced.',
            'category' => 'security',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => false,
        ],
        // Phase 9 — control library v2, CSA & surveys
        [
            'key' => 'document.review-due',
            'label' => 'Document review due',
            'description' => 'A governing document you own is approaching or past its review date.',
            'category' => 'documents',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => true,
        ],
        [
            'key' => 'distribution.assigned',
            'label' => 'Control distributed to your entity',
            'description' => 'A group control was distributed to an entity you own — acknowledge and implement it.',
            'category' => 'distribution',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => true,
        ],
        [
            'key' => 'campaign.assigned',
            'label' => 'Assessment or survey assigned',
            'description' => 'A CSA, attestation or survey response has been assigned to you.',
            'category' => 'campaigns',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => true,
        ],
        [
            'key' => 'campaign.variance-flagged',
            'label' => 'CSA rating variance flagged',
            'description' => 'A self-rating differed from the reviewer rating beyond the campaign threshold.',
            'category' => 'campaigns',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => true,
        ],
        [
            'key' => 'improvement.assigned',
            'label' => 'Improvement action assigned',
            'description' => 'An improvement action has been assigned to you or needs your verification.',
            'category' => 'improvements',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => true,
        ],
        // Phase 11 — policy, incident, complaints & case management
        [
            'key' => 'policy.review-due',
            'label' => 'Policy review due',
            'description' => 'A policy you own is approaching or past its scheduled review date.',
            'category' => 'policies',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => true,
        ],
        [
            'key' => 'policy.attestation-opened',
            'label' => 'Policy attestation required',
            'description' => 'A policy that applies to you has been published and needs your attestation.',
            'category' => 'policies',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => false,
        ],
        [
            'key' => 'incident.reported',
            'label' => 'Incident reported',
            'description' => 'A new incident has been reported in an area you are responsible for.',
            'category' => 'incidents',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => true,
        ],
        [
            'key' => 'incident.notification-due',
            'label' => 'Regulatory notification window closing',
            'description' => 'An incident owes a regulator a notification and the statutory window is closing.',
            'category' => 'incidents',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => false,
        ],
        [
            'key' => 'complaint.acknowledgement-due',
            'label' => 'Complaint acknowledgement window closing',
            'description' => 'A complaint assigned to you has not been acknowledged and its window is closing.',
            'category' => 'complaints',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => false,
        ],
        [
            'key' => 'complaint.sla-breached',
            'label' => 'Complaint SLA breached',
            'description' => 'A complaint has passed its acknowledgement or resolution deadline and is accruing penalty exposure.',
            'category' => 'complaints',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => false,
        ],
        [
            'key' => 'case.assigned',
            'label' => 'Case assigned to you',
            'description' => 'You have been added to a case allowlist or named as its lead investigator.',
            'category' => 'cases',
            'default_channels' => ['in_app'],
            'is_user_configurable' => false,
        ],
        [
            'key' => 'monitoring.source-failed',
            'label' => 'Data source paused by the circuit breaker',
            'description' => 'A data source has failed repeatedly and been paused. Every rule reading from it is dormant, '
                .'so the controls it tests are unmonitored until it is resumed.',
            'category' => 'monitoring',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => false,
        ],
        [
            'key' => 'report.ready',
            'label' => 'Scheduled report ready',
            'description' => 'A report you are a distribution recipient of has been generated and is available to download.',
            'category' => 'reporting',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => true,
        ],
        [
            'key' => 'submission.awaiting-action',
            'label' => 'Regulatory submission needs you',
            'description' => 'A submission pack is waiting for your review, approval or filing before its deadline.',
            'category' => 'submissions',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => false,
        ],
        // CR-01 — Exception Manager: departmental escalation, response &
        // closure. The issue notice is NOT user-configurable: a department
        // cannot mute an exception addressed to it.
        [
            'key' => 'exception.escalation.issued',
            'label' => 'Exception issued to your department',
            'description' => 'The control function has issued a control exception to you for a formal departmental response.',
            'category' => 'exception-manager',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => false,
        ],
        [
            'key' => 'exception.escalation.acknowledgement_due',
            'label' => 'Escalation acknowledgement due',
            'description' => 'An exception issued to you has not been acknowledged and its acknowledgement window is closing.',
            'category' => 'exception-manager',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => false,
        ],
        [
            'key' => 'exception.response.reminder',
            'label' => 'Exception response reminder',
            'description' => 'Graduated reminder around the response due date on an exception issued to you.',
            'category' => 'exception-manager',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => true,
        ],
        [
            'key' => 'exception.response.overdue',
            'label' => 'Exception response overdue',
            'description' => 'The response deadline on an exception issued to you has passed; unanswered escalations climb the escalation ladder.',
            'category' => 'exception-manager',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => false,
        ],
        [
            'key' => 'exception.response.submitted',
            'label' => 'Departmental response received',
            'description' => 'A department has submitted its response to an exception you issued — it is awaiting review.',
            'category' => 'exception-manager',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => true,
        ],
        [
            'key' => 'exception.response.accepted',
            'label' => 'Your response was accepted',
            'description' => 'The control function accepted your departmental response; committed actions are now tracked.',
            'category' => 'exception-manager',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => true,
        ],
        [
            'key' => 'exception.response.rejected',
            'label' => 'Your response was rejected and re-issued',
            'description' => 'The control function rejected your response; the exception has been re-issued with a new deadline.',
            'category' => 'exception-manager',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => false,
        ],
        [
            'key' => 'exception.escalation.closed',
            'label' => 'Escalation closed',
            'description' => 'The control function validated the committed actions and closed an escalation addressed to you.',
            'category' => 'exception-manager',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => true,
        ],
        [
            'key' => 'exception.escalation.withdrawn',
            'label' => 'Escalation withdrawn',
            'description' => 'An escalation addressed to you was withdrawn by the control function; no response is required.',
            'category' => 'exception-manager',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => true,
        ],
        // CR2-A — Internal control structure: cross-functional controls.
        [
            'key' => 'control.stakeholder.added',
            'label' => 'Your unit named a stakeholder on a control',
            'description' => 'Your unit was added as a co-owner, contributor or consulted party on a shared control.',
            'category' => 'control-structure',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => true,
        ],
        [
            'key' => 'control.shared.exception_raised',
            'label' => 'Shared control failed',
            'description' => 'An exception was raised on a control your unit co-owns. Not muteable — a co-owner cannot silence a failure it shares responsibility for.',
            'category' => 'control-structure',
            'default_channels' => ['in_app', 'email'],
            'is_user_configurable' => false,
        ],
    ];

    public function run(): void
    {
        foreach (self::EVENTS as $event) {
            NotificationEvent::updateOrCreate(
                ['tenant_id' => null, 'key' => $event['key']],
                $event,
            );
        }
    }
}
