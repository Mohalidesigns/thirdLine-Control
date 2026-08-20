<?php

namespace Database\Seeders;

use App\Models\SmsTemplate;
use App\Models\WhatsappTemplate;
use Illuminate\Database\Seeder;

/**
 * Phase 15 message template catalogue (R1: templates are data, never
 * hard-coded strings in a driver).
 *
 * Every WhatsApp template ships as `draft`: Meta must approve a template
 * before it can be sent out-of-session, and pretending otherwise would be
 * inventing an approval (the R10 instinct applied to Meta instead of a
 * regulator). Submit them in Meta Business Manager, then "Sync from Meta"
 * on the admin messaging screen picks up the real statuses.
 *
 * Bodies carry a notification and a {{link}} — never names of customers,
 * case content or account data. OutboundContentGuard enforces the same
 * rule at send time.
 */
class Phase15MessagingSeeder extends Seeder
{
    private const WHATSAPP_TEMPLATES = [
        [
            'key' => 'task_reminder',
            'name' => 'task_reminder',
            'category' => 'utility',
            'body' => "You have a pending item on SecondLine: {{title}}.\nOpen it here: {{link}}",
            'variables' => ['title', 'link'],
        ],
        [
            'key' => 'attestation_reminder',
            'name' => 'attestation_reminder',
            'category' => 'utility',
            'body' => "An attestation is waiting for your signature: {{title}} (due {{due_date}}).\nConfirm in one tap: {{link}}",
            'variables' => ['title', 'due_date', 'link'],
        ],
        [
            'key' => 'overdue_task',
            'name' => 'overdue_task',
            'category' => 'utility',
            'body' => "A task assigned to you is now overdue: {{title}}.\nPlease act today: {{link}}",
            'variables' => ['title', 'link'],
        ],
        [
            'key' => 'approval_request',
            'name' => 'approval_request',
            'category' => 'utility',
            'body' => "An item needs your review on SecondLine: {{title}}.\nReview it here: {{link}}",
            'variables' => ['title', 'link'],
        ],
        [
            'key' => 'evidence_request',
            'name' => 'evidence_request',
            'category' => 'utility',
            'body' => "Supporting evidence has been requested for: {{title}}.\nUpload it here: {{link}}",
            'variables' => ['title', 'link'],
        ],
        [
            'key' => 'complaint_acknowledgement',
            'name' => 'complaint_acknowledgement',
            'category' => 'utility',
            'body' => "We have received your complaint and it is being handled under reference {{reference}}.\nTrack progress: {{link}}",
            'variables' => ['reference', 'link'],
        ],
        [
            'key' => 'policy_acknowledgement',
            'name' => 'policy_acknowledgement',
            'category' => 'utility',
            'body' => "A policy that applies to you has been published: {{title}}.\nRead and acknowledge it: {{link}}",
            'variables' => ['title', 'link'],
        ],
    ];

    private const SMS_TEMPLATES = [
        [
            'key' => 'task_reminder',
            'name' => 'Task reminder',
            'body' => 'SecondLine: pending item — {{title}}. Open: {{link}}',
            'variables' => ['title', 'link'],
        ],
        [
            'key' => 'attestation_reminder',
            'name' => 'Attestation reminder',
            'body' => 'SecondLine: attestation due — {{title}}. Confirm: {{link}}',
            'variables' => ['title', 'link'],
        ],
        [
            'key' => 'overdue_task',
            'name' => 'Overdue task nudge',
            'body' => 'SecondLine: OVERDUE — {{title}}. Act today: {{link}}',
            'variables' => ['title', 'link'],
        ],
        [
            'key' => 'complaint_acknowledgement',
            'name' => 'Complaint acknowledgement',
            'body' => 'Your complaint is received, ref {{reference}}. Track: {{link}}',
            'variables' => ['reference', 'link'],
        ],
    ];

    public function run(): void
    {
        foreach (self::WHATSAPP_TEMPLATES as $template) {
            WhatsappTemplate::updateOrCreate(
                ['tenant_id' => null, 'key' => $template['key'], 'language' => 'en'],
                [...$template, 'language' => 'en', 'approval_status' => 'draft'],
            );
        }

        foreach (self::SMS_TEMPLATES as $template) {
            SmsTemplate::updateOrCreate(
                ['tenant_id' => null, 'key' => $template['key']],
                [...$template, 'is_active' => true],
            );
        }
    }
}
