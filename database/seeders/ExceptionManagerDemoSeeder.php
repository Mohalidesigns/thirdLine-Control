<?php

namespace Database\Seeders;

use App\Models\Control;
use App\Models\ControlException;
use App\Models\ExceptionAction;
use App\Models\ExceptionEscalation;
use App\Models\ExceptionResponse;
use App\Models\ExceptionRoutingRule;
use App\Models\ExceptionSlaPolicy;
use App\Models\OrganisationUnit;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * CR-01 demo pack: the default SLA policy set (R1 — clocks are data), one
 * routing rule, and a register that demonstrates the whole loop the client
 * asked for — an escalation answered and accepted, one rejected and
 * re-issued through a second round, a completed-and-validated closure, and
 * one department sitting on an overdue clock for the chase engine and the
 * FR-8 ladder to find.
 */
class ExceptionManagerDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::first();

        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;

        // ── Default SLA policy set per severity (R1). ────────────────
        $policies = [];

        foreach ([
            ['Critical', 12, 3, [-1, 1, 2], 5, 2],
            ['High', 24, 5, [-2, -1, 1, 3], 5, 3],
            ['Medium', 48, 10, [-3, -1, 1, 3, 7], 5, 5],
            ['Low', 72, 20, [-5, -1, 3, 7], 4, 10],
        ] as [$severity, $ackHours, $respondDays, $offsets, $maxReminders, $escalateAfter]) {
            $policies[$severity] = ExceptionSlaPolicy::withoutGlobalScope('tenant')->firstOrCreate(
                ['tenant_id' => $tid, 'severity' => $severity, 'name' => "Standard {$severity} response SLA"],
                [
                    'acknowledge_within_hours' => $ackHours,
                    'respond_within_days' => $respondDays,
                    'reminder_offsets' => $offsets,
                    'max_reminders' => $maxReminders,
                    'escalate_after_days' => $escalateAfter,
                    'auto_escalate_to_matrix' => true,
                    'is_active' => true,
                ],
            );
        }

        $cfh = User::where('email', 'cfh@secondline.test')->first();
        $officer = User::where('email', 'officer1@secondline.test')->first();
        $owner1 = User::where('email', 'owner1@secondline.test')->first();
        $owner2 = User::where('email', 'owner2@secondline.test')->first();
        $ops = OrganisationUnit::withoutGlobalScopes()->where('tenant_id', $tid)->where('name', 'Operations')->first();
        $marina = OrganisationUnit::withoutGlobalScopes()->where('tenant_id', $tid)->where('name', 'Marina Branch')->first();

        if (! $cfh || ! $officer || ! $owner1 || ! $ops) {
            return;
        }

        // ── One routing rule: Operations exceptions land on the unit head.
        ExceptionRoutingRule::withoutGlobalScope('tenant')->firstOrCreate(
            ['tenant_id' => $tid, 'sequence' => 1],
            [
                'is_active' => true,
                'match_unit_id' => $ops->id,
                'route_to_type' => 'unit_head',
                'route_to_unit_id' => $ops->id,
                'sla_policy_id' => $policies['High']->id,
            ],
        );

        if (ExceptionEscalation::withoutGlobalScope('tenant')->where('tenant_id', $tid)->exists()) {
            return; // demo pack already seeded
        }

        $control = Control::withoutGlobalScopes()->where('tenant_id', $tid)->where('is_template', false)->first();

        // ── The demo exception: issued to two departments (CR-01 DoD). ──
        $exception = ControlException::withoutGlobalScopes()->create([
            'tenant_id' => $tid,
            'reference' => 'EXC-'.now()->year.'-901',
            'source_type' => 'Manual',
            'control_id' => $control?->id,
            'title' => 'Unreconciled suspense account balances beyond 30 days',
            'description' => 'Month-end review found suspense balances aged past the 30-day clearance rule in Operations and Marina Branch.',
            'severity' => 'High',
            'owner_id' => $owner1->id,
            'unit_id' => $ops->id,
            'raised_by' => $officer->id,
            'date_raised' => now()->subDays(30)->toDateString(),
            'target_closure_date' => now()->addDays(14)->toDateString(),
            'status' => 'In Progress',
        ]);

        // Escalation 1 — Operations: the closed loop (answered, accepted,
        // action completed, validated Effective, escalation Closed).
        $esc1 = ExceptionEscalation::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tid,
            'exception_id' => $exception->id,
            'reference' => 'EXE-'.now()->year.'-001',
            'round_no' => 1,
            'target_type' => 'unit',
            'unit_id' => $ops->id,
            'respondent_id' => $owner1->id,
            'severity' => 'High',
            'issued_by' => $officer->id,
            'issued_at' => now()->subDays(28),
            'issue_note' => 'Provide the root cause of the aged suspense balances in Operations and a dated clearance plan.',
            'required_response' => 'both',
            'acknowledge_due_at' => now()->subDays(27),
            'response_due_at' => now()->subDays(21),
            'sla_policy_id' => $policies['High']->id,
            'status' => 'Closed',
            'acknowledged_at' => now()->subDays(28)->addHours(4),
            'acknowledged_by' => $owner1->id,
            'first_response_at' => now()->subDays(24),
            'last_response_at' => now()->subDays(24),
            'closed_at' => now()->subDays(2),
            'closed_by' => $cfh->id,
            'closure_note' => 'Re-performed the reconciliation on the current ledger — no aged items remain.',
            'notified_to' => [['id' => $owner1->id, 'name' => $owner1->name, 'email' => $owner1->email, 'via' => 'respondent']],
        ]);

        $resp1 = ExceptionResponse::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tid,
            'escalation_id' => $esc1->id,
            'exception_id' => $exception->id,
            'round_no' => 1,
            'responder_id' => $owner1->id,
            'responder_name' => $owner1->name,
            'responder_email' => $owner1->email,
            'submitted_at' => now()->subDays(24),
            'channel' => 'portal',
            'position' => 'Accepted',
            'management_comment' => 'The backlog arose from the March core-banking migration; clearance was deprioritised during cutover.',
            'root_cause' => 'Reconciliation staff were reassigned to migration testing without backfill.',
            'root_cause_category' => 'process',
            'agreed_action_plan' => 'Clear all aged items and reinstate the daily suspense clearance report with unit-head sign-off.',
            'proposed_target_date' => now()->subDays(7)->toDateString(),
            'is_late' => false,
            'review_status' => 'Accepted',
            'reviewed_by' => $cfh->id,
            'reviewed_at' => now()->subDays(23),
            'review_note' => 'Root cause is credible and the plan is dated and owned.',
        ]);

        ExceptionAction::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tid,
            'exception_id' => $exception->id,
            'escalation_id' => $esc1->id,
            'response_id' => $resp1->id,
            'reference' => 'EXA-'.now()->year.'-001',
            'title' => 'Clear aged suspense items and reinstate the daily clearance report',
            'description' => 'Clear all items older than 30 days and reinstate the daily suspense clearance report with unit-head sign-off.',
            'action_type' => 'corrective',
            'owner_id' => $owner1->id,
            'unit_id' => $ops->id,
            'target_date' => now()->subDays(7)->toDateString(),
            'status' => 'Completed',
            'progress_percentage' => 100,
            'completed_at' => now()->subDays(8),
            'completed_by' => $owner1->id,
            'validation_status' => 'Effective',
            'validated_by' => $cfh->id,
            'validated_at' => now()->subDays(2),
            'validation_note' => 'Verified against the current-day reconciliation.',
        ]);

        // Escalation 2 — Marina Branch: rejected round 1, re-issued,
        // answered again, accepted; action still in progress.
        if ($marina && $owner2) {
            $esc2 = ExceptionEscalation::withoutGlobalScope('tenant')->create([
                'tenant_id' => $tid,
                'exception_id' => $exception->id,
                'reference' => 'EXE-'.now()->year.'-002',
                'round_no' => 2,
                'target_type' => 'unit',
                'unit_id' => $marina->id,
                'respondent_id' => $owner2->id,
                'severity' => 'High',
                'issued_by' => $officer->id,
                'issued_at' => now()->subDays(28),
                'issue_note' => 'Provide the root cause of the aged suspense balances originated at Marina Branch and a clearance plan.',
                'required_response' => 'both',
                'acknowledge_due_at' => now()->subDays(27),
                'response_due_at' => now()->addDays(2),
                'sla_policy_id' => $policies['High']->id,
                'status' => 'Closed',
                'acknowledged_at' => now()->subDays(26),
                'acknowledged_by' => $owner2->id,
                'is_acknowledgement_late' => true,
                'first_response_at' => now()->subDays(20),
                'last_response_at' => now()->subDays(4),
                'closed_at' => now()->subDay(),
                'closed_by' => $cfh->id,
                'closure_note' => 'All 14 items cleared and the daily proof certification verified on site.',
                'notified_to' => [['id' => $owner2->id, 'name' => $owner2->name, 'email' => $owner2->email, 'via' => 'respondent']],
            ]);

            ExceptionResponse::withoutGlobalScope('tenant')->create([
                'tenant_id' => $tid,
                'escalation_id' => $esc2->id,
                'exception_id' => $exception->id,
                'round_no' => 1,
                'responder_id' => $owner2->id,
                'responder_name' => $owner2->name,
                'responder_email' => $owner2->email,
                'submitted_at' => now()->subDays(20),
                'channel' => 'portal',
                'position' => 'Partially Accepted',
                'management_comment' => 'The items are being worked on as part of normal operations.',
                'root_cause' => 'Staffing.',
                'agreed_action_plan' => 'Will clear soon.',
                'is_late' => false,
                'review_status' => 'Rejected',
                'reviewed_by' => $cfh->id,
                'reviewed_at' => now()->subDays(18),
                'rejection_reason' => 'No dated plan and no named owner — "will clear soon" is not an action plan.',
            ]);

            $resp2b = ExceptionResponse::withoutGlobalScope('tenant')->create([
                'tenant_id' => $tid,
                'escalation_id' => $esc2->id,
                'exception_id' => $exception->id,
                'round_no' => 2,
                'responder_id' => $owner2->id,
                'responder_name' => $owner2->name,
                'responder_email' => $owner2->email,
                'submitted_at' => now()->subDays(4),
                'channel' => 'portal',
                'position' => 'Accepted',
                'management_comment' => 'Full aged-item listing attached; clearance is scheduled item by item.',
                'root_cause' => 'Teller proof exceptions parked to suspense during the branch system outage in April were never followed up.',
                'root_cause_category' => 'people',
                'agreed_action_plan' => 'Clear the 14 outstanding items by month end; branch ops supervisor to certify the daily proof.',
                'proposed_target_date' => now()->addDays(21)->toDateString(),
                'is_late' => false,
                'review_status' => 'Accepted',
                'reviewed_by' => $cfh->id,
                'reviewed_at' => now()->subDays(3),
                'review_note' => 'Round 2 answers what round 1 did not.',
            ]);

            ExceptionAction::withoutGlobalScope('tenant')->create([
                'tenant_id' => $tid,
                'exception_id' => $exception->id,
                'escalation_id' => $esc2->id,
                'response_id' => $resp2b->id,
                'reference' => 'EXA-'.now()->year.'-002',
                'title' => 'Clear 14 outstanding Marina Branch suspense items',
                'description' => 'Clear the 14 outstanding items by month end; branch ops supervisor to certify the daily proof.',
                'action_type' => 'corrective',
                'owner_id' => $owner2->id,
                'unit_id' => $marina->id,
                'target_date' => now()->addDays(21)->toDateString(),
                'status' => 'Completed',
                'progress_percentage' => 100,
                'progress_note' => 'All 14 items cleared; daily proof certification reinstated.',
                'completed_at' => now()->subDays(2),
                'completed_by' => $owner2->id,
                'validation_status' => 'Effective',
                'validated_by' => $cfh->id,
                'validated_at' => now()->subDay(),
                'validation_note' => 'Verified the cleared items against the branch proof.',
            ]);
        }

        // The whole loop is closed, so the control lapse itself reaches
        // Verified-Closed — the demo path the client will walk (CR-01 DoD).
        $exception->update([
            'status' => 'Remediated',
            'remediated_at' => now()->subDays(2),
            'remediated_by' => $owner1->id,
        ]);
        $exception->update([
            'status' => 'Verified-Closed',
            'verification_method' => 'Re-performed the suspense reconciliation on the current ledger for both units.',
            'verified_by' => $cfh->id,
            'verified_at' => now()->subDay(),
            'closure_type' => 'Remediated',
            'is_overdue' => false,
        ]);

        // Escalation 3 — a second exception whose department is sitting on
        // an overdue clock: the chase engine and FR-8 ladder demo path.
        $exception2 = ControlException::withoutGlobalScopes()->create([
            'tenant_id' => $tid,
            'reference' => 'EXC-'.now()->year.'-902',
            'source_type' => 'Manual',
            'control_id' => $control?->id,
            'title' => 'Dormant account reactivations processed without second approval',
            'severity' => 'Critical',
            'owner_id' => $owner1->id,
            'unit_id' => $ops->id,
            'raised_by' => $officer->id,
            'date_raised' => now()->subDays(12)->toDateString(),
            'target_closure_date' => now()->addDays(2)->toDateString(),
            'status' => 'Assigned',
            'is_response_overdue' => true,
            'escalation_status' => 'Awaiting Response',
            'open_escalation_count' => 1,
            'first_escalated_at' => now()->subDays(10),
        ]);

        ExceptionEscalation::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tid,
            'exception_id' => $exception2->id,
            'reference' => 'EXE-'.now()->year.'-003',
            'round_no' => 1,
            'target_type' => 'unit',
            'unit_id' => $ops->id,
            'respondent_id' => $owner1->id,
            'severity' => 'Critical',
            'issued_by' => $cfh->id,
            'issued_at' => now()->subDays(10),
            'issue_note' => 'Confirm which reactivations lack a second approval and commit to a remediation plan for each.',
            'required_response' => 'both',
            'acknowledge_due_at' => now()->subDays(9),
            'response_due_at' => now()->subDays(5),
            'sla_policy_id' => $policies['Critical']->id,
            'status' => 'Acknowledged',
            'acknowledged_at' => now()->subDays(9),
            'acknowledged_by' => $owner1->id,
            'is_response_late' => true,
            'reminder_count' => 2,
            'last_reminder_at' => now()->subDay(),
            'notified_to' => [['id' => $owner1->id, 'name' => $owner1->name, 'email' => $owner1->email, 'via' => 'respondent']],
        ]);

        // Denormalised caches on the demo exception (CR-01).
        $exception->updateQuietly([
            'escalation_status' => 'All Closed',
            'first_escalated_at' => now()->subDays(28),
            'last_response_at' => now()->subDays(4),
            'open_escalation_count' => 0,
        ]);
    }
}
