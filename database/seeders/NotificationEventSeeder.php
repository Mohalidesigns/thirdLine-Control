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
