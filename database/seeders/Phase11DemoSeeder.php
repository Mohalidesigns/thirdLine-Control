<?php

namespace Database\Seeders;

use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\Control;
use App\Models\Incident;
use App\Models\OrganisationUnit;
use App\Models\Policy;
use App\Models\PolicyCategory;
use App\Models\User;
use App\Services\CaseService;
use App\Services\ComplaintService;
use App\Services\IncidentService;
use App\Services\LinkageService;
use App\Services\PolicyService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * A demoable Nigerian governance position: a published policy set with a
 * live attestation campaign and an approved waiver, an operational incident
 * that failed a control and owes NDPC a 72-hour breach notification, a
 * complaints desk with one breached acknowledgement accruing exposure, and
 * a whistleblowing case nobody outside its allowlist can open.
 */
class Phase11DemoSeeder extends Seeder
{
    use Concerns\ResolvesDemoTenant;

    public function run(): void
    {
        $tenant = $this->demoTenantOrFail();
        $tid = $tenant->id;

        $user = fn (string $email) => User::withoutGlobalScopes()->where('email', $email)->first();

        $cfh = $user('cfh@secondline.test');
        $officer1 = $user('officer1@secondline.test');
        $officer2 = $user('officer2@secondline.test');
        $owner1 = $user('owner1@secondline.test');
        $exec = $user('exec@secondline.test');

        if (! $cfh || ! $officer1 || ! $officer2) {
            return;
        }

        // The services stamp tenant_id and audit rows from the acting user,
        // so the seeder runs as a real person rather than as nobody.
        Auth::login($officer1);

        $policies = app(PolicyService::class);
        $incidents = app(IncidentService::class);
        $complaints = app(ComplaintService::class);
        $cases = app(CaseService::class);
        $linkage = app(LinkageService::class);

        $entity = OrganisationUnit::withoutGlobalScopes()
            ->where('tenant_id', $tid)->where('is_legal_entity', true)->first()
            ?? OrganisationUnit::withoutGlobalScopes()->where('tenant_id', $tid)->first();

        $category = fn (string $code) => PolicyCategory::withoutGlobalScopes()->where('code', $code)->first();
        $control = fn (string $ref) => Control::withoutGlobalScopes()
            ->where('tenant_id', $tid)->where('control_ref', $ref)->first();

        // ── 1. Policies ──────────────────────────────────────────────

        $whistleblowing = $policies->create([
            'title' => 'Whistleblowing and Speak-Up Policy',
            'description' => 'How concerns about wrongdoing are raised, handled and protected from retaliation.',
            'policy_type' => 'policy',
            'category_id' => $category('HRM')?->id,
            'owner_id' => $officer2->id,
            'review_frequency' => 'annual',
            'requires_attestation' => true,
            'attestation_frequency' => 'annual',
            'mandatory_training' => true,
            'applies_to' => ['all_staff' => true],
            'sections' => [
                [
                    'heading' => '1. Purpose and scope',
                    'body' => 'This policy applies to every member of staff, contractor and director. It sets out how '
                        .'a concern about fraud, corruption, regulatory breach or misconduct may be raised, and the '
                        .'protection available to anyone who raises one in good faith.',
                    'is_mandatory' => true,
                ],
                [
                    'heading' => '2. Channels',
                    'body' => 'Concerns may be raised with a line manager, the Control Function Head, or anonymously '
                        .'through the Speak-Up portal. Anonymous reports carry a one-way reference token so the '
                        .'reporter can follow progress without identifying themselves.',
                    'is_mandatory' => true,
                ],
                [
                    'heading' => '3. Non-retaliation',
                    'body' => 'Retaliation against anyone who raises a concern in good faith is a disciplinary offence. '
                        .'A report made in good faith that turns out to be unfounded attracts no sanction.',
                    'is_mandatory' => true,
                ],
                [
                    'heading' => '4. Handling and confidentiality',
                    'body' => 'Every case is assessed within five working days. Access to a case file is restricted to '
                        .'an explicit allowlist; no role, including system administration, has automatic access.',
                    'is_mandatory' => true,
                ],
            ],
        ], $officer2);

        $policies->submitForReview($whistleblowing, $officer2);
        $policies->submitForApproval($whistleblowing->fresh(), $officer2);
        $policies->approve($whistleblowing->fresh(), $cfh);
        $policies->publish($whistleblowing->fresh(), $cfh);

        $complaintsPolicy = $policies->create([
            'title' => 'Consumer Complaints Handling Policy',
            'description' => 'Complaint channels, acknowledgement, tracking, resolution and reporting under the '
                .'CBN Consumer Protection Framework.',
            'policy_type' => 'policy',
            'category_id' => $category('CUS')?->id,
            'owner_id' => $officer1->id,
            'review_frequency' => 'annual',
            'requires_attestation' => false,
            'applies_to' => ['all_staff' => true],
            'sections' => [
                [
                    'heading' => '1. Channels',
                    'body' => 'Complaints are accepted by letter, email, telephone, social media, branch, mobile app, '
                        .'USSD and the web portal. No channel may be closed without the approval of the Control '
                        .'Function Head.',
                    'is_mandatory' => true,
                ],
                [
                    'heading' => '2. Acknowledgement',
                    'body' => 'Every complaint is acknowledged within the window set by the applicable regulatory '
                        .'obligation, and issued a unique tracking identifier at the point of acknowledgement.',
                    'is_mandatory' => true,
                ],
                [
                    'heading' => '3. Resolution and root cause',
                    'body' => 'A complaint may not be closed without a recorded root cause. Root causes are reviewed '
                        .'monthly against the control library.',
                    'is_mandatory' => true,
                ],
            ],
        ], $officer1);

        $policies->submitForApproval($complaintsPolicy->fresh(), $officer1);
        $policies->approve($complaintsPolicy->fresh(), $cfh);
        $policies->publish($complaintsPolicy->fresh(), $cfh);

        $dataProtection = $policies->create([
            'title' => 'Data Protection and Breach Response Policy',
            'description' => 'Personal data handling, retention and the breach response runbook.',
            'policy_type' => 'standard',
            'category_id' => $category('DAT')?->id,
            'owner_id' => $officer2->id,
            'review_frequency' => 'annual',
            'requires_attestation' => false,
            'sections' => [
                [
                    'heading' => '1. Breach response',
                    'body' => 'A suspected personal data breach is escalated to the Data Protection Officer '
                        .'immediately. Notification duties are computed from the obligation register and tracked '
                        .'against a live countdown; the window is never assumed.',
                    'is_mandatory' => true,
                ],
            ],
        ], $officer2);

        $policies->submitForApproval($dataProtection->fresh(), $officer2);
        $policies->approve($dataProtection->fresh(), $cfh);
        $policies->publish($dataProtection->fresh(), $cfh);

        // A live, approved waiver against the complaints policy.
        $waiver = $policies->requestException($complaintsPolicy->fresh(), [
            'entity_id' => $entity?->id,
            'justification' => 'The WhatsApp complaint channel cannot go live at Marina Branch until the '
                .'anonymising bridge is deployed; complaints arriving there are being redirected to email.',
            'risk_assessment' => 'Low: no customer is left without a channel, and volumes on the affected route '
                .'are under twenty a month.',
            'compensating_measures' => 'Daily manual sweep of the branch WhatsApp inbox with same-day transfer '
                .'into the complaints register.',
            'requested_from' => now()->subDays(10)->toDateString(),
            'requested_to' => now()->addDays(50)->toDateString(),
        ], $owner1 ?? $officer1);

        $policies->decideException($waiver, $cfh, true, 'Approved for one quarter; revisit at the next review.');

        // ── 2. Incidents ─────────────────────────────────────────────

        $breach = $incidents->report([
            'tenant_id' => $tid,
            'title' => 'Customer statement PDFs exposed on a misconfigured storage bucket',
            'description' => 'A batch job wrote 2,140 customer statement PDFs to a storage bucket whose default '
                .'access policy had been left public during a migration. Discovered by an engineer during a '
                .'routine review; the bucket was made private within eleven minutes.',
            'incident_type' => 'data_breach',
            'basel_event_type' => 'Execution Delivery & Process Mgmt',
            'severity' => 'Critical',
            'occurred_at' => now()->subHours(30),
            'detected_at' => now()->subHours(26),
            'detection_method' => 'Internal engineering review',
            'entity_id' => $entity?->id,
            'owner_id' => $officer2->id,
            'investigator_id' => $officer2->id,
            'currency' => 'NGN',
            'gross_loss_minor' => 0,
            'recovery_minor' => 0,
            'control_failure_ids' => array_values(array_filter([
                $control('CTL-004')?->id,
                $control('CTL-005')?->id,
            ])),
        ], $officer1);

        $incidents->triage($breach->fresh(), [
            'severity' => 'Critical',
            'incident_type' => 'data_breach',
            'basel_event_type' => 'Execution Delivery & Process Mgmt',
            'owner_id' => $officer2->id,
            'investigator_id' => $officer2->id,
            'near_miss' => false,
        ], $cfh);

        $incidents->addAction($breach->fresh(), [
            'action_type' => 'containment',
            'title' => 'Revoke public access on the affected bucket and rotate the access keys',
            'owner_id' => $officer2->id,
            'due_at' => now()->subHours(25),
            'is_mandatory' => true,
        ]);

        $incidents->addAction($breach->fresh(), [
            'action_type' => 'preventive',
            'title' => 'Add a deploy-time check that fails any bucket created without a private default policy',
            'owner_id' => $officer2->id,
            'due_at' => now()->addDays(14),
            'is_mandatory' => true,
        ]);

        $incidents->addAction($breach->fresh(), [
            'action_type' => 'communication',
            'title' => 'Prepare data-subject notification for affected customers',
            'owner_id' => $officer1->id,
            'due_at' => now()->addDays(2),
            'is_mandatory' => false,
        ]);

        $incidents->startInvestigation($breach->fresh(), $officer2);

        $fraudNearMiss = $incidents->report([
            'tenant_id' => $tid,
            'title' => 'Attempted vendor bank-detail change caught at second approval',
            'description' => 'A payment instruction changing a vendor’s account details was submitted with a '
                .'spoofed letterhead. The second approver called the vendor on the number held on file and '
                .'stopped the payment before release.',
            'incident_type' => 'fraud',
            'basel_event_type' => 'External Fraud',
            'severity' => 'High',
            'occurred_at' => now()->subDays(6),
            'detected_at' => now()->subDays(6),
            'detection_method' => 'Maker-checker approval',
            'entity_id' => $entity?->id,
            'owner_id' => $officer1->id,
            'near_miss' => true,
            'currency' => 'NGN',
            'gross_loss_minor' => 0,
            'recovery_minor' => 0,
        ], $owner1 ?? $officer1);

        $incidents->triage($fraudNearMiss->fresh(), [
            'severity' => 'High',
            'incident_type' => 'fraud',
            'basel_event_type' => 'External Fraud',
            'owner_id' => $officer1->id,
            'near_miss' => true,
        ], $cfh);

        $outage = $incidents->report([
            'tenant_id' => $tid,
            'title' => 'Card switch unavailable for 47 minutes during evening peak',
            'description' => 'A failed failover during scheduled maintenance left the card switch unavailable, '
                .'declining an estimated 9,400 transactions.',
            'incident_type' => 'continuity',
            'basel_event_type' => 'Business Disruption & System Failures',
            'severity' => 'High',
            'occurred_at' => now()->subDays(20),
            'detected_at' => now()->subDays(20),
            'detection_method' => 'Monitoring alert',
            'entity_id' => $entity?->id,
            'owner_id' => $officer2->id,
            'currency' => 'NGN',
            'gross_loss_minor' => 4_850_000_00,
            'recovery_minor' => 1_200_000_00,
        ], $officer2);

        $incidents->triage($outage->fresh(), [
            'severity' => 'High',
            'incident_type' => 'continuity',
            'basel_event_type' => 'Business Disruption & System Failures',
            'owner_id' => $officer2->id,
        ], $cfh);
        $incidents->startInvestigation($outage->fresh(), $officer2);
        $incidents->contain($outage->fresh(), $officer2, 'Traffic restored on the secondary switch at 20:14.');
        $incidents->markRemediated($outage->fresh(), $officer2, [
            'root_cause' => 'The failover runbook had not been re-tested after the June firmware upgrade, and the '
                .'secondary switch was running an incompatible configuration.',
            'root_cause_category' => 'Process design weakness',
            'contributing_factors' => ['Untested runbook', 'Change executed inside peak hours'],
            'lessons_learned' => 'Failover tests move to a monthly cycle and no switch change is scheduled inside '
                .'the 17:00–21:00 window.',
        ]);

        // ── 3. Complaints ────────────────────────────────────────────

        $cat = fn (string $code) => ComplaintCategory::withoutGlobalScopes()
            ->where('kind', 'category')->where('code', $code)->first();
        $cause = fn (string $code) => ComplaintCategory::withoutGlobalScopes()
            ->where('kind', 'root_cause')->where('code', $code)->first();

        /**
         * ComplaintService::acknowledge() and resolve() stamp the server
         * clock and take no time argument — that is the whole point of the
         * rule. Seed data is reconstructed history, so the demo backdates
         * those two columns directly afterwards. This is the only place in
         * the codebase that writes them by hand, and it is not reachable
         * from a request.
         */
        $backdate = function (Complaint $complaint, array $times) use ($complaints): Complaint {
            $complaint->updateQuietly($times);

            return $complaints->refreshSla($complaint->fresh());
        };

        // Acknowledged inside the window and resolved — the happy path.
        $resolved = $complaints->intake([
            'tenant_id' => $tid,
            'channel' => 'app',
            'received_at' => now()->subDays(9),
            'customer_name' => 'Adaeze Okonkwo',
            'customer_reference' => '0123456789',
            'customer_contact' => ['email' => 'a.okonkwo@example.com', 'phone' => '+234 802 000 0000'],
            'category_id' => $cat('EFT')?->id,
            'subject' => 'Transfer debited twice',
            'description' => 'A ₦85,000 transfer to another bank was debited twice on the same evening.',
            'product' => 'Mobile app transfers',
            'amount_disputed_minor' => 85_000_00,
            'currency' => 'NGN',
            'entity_id' => $entity?->id,
            'assigned_to' => $officer1->id,
        ], $officer1);

        $complaints->acknowledge($resolved->fresh(), $officer1);
        $backdate($resolved->fresh(), ['acknowledged_at' => now()->subDays(9)->addHours(3)]);
        $complaints->investigate($resolved->fresh(), $officer1, 'Duplicate posting confirmed against the switch log.');
        $complaints->resolve($resolved->fresh(), $officer1, [
            'resolution_summary' => 'Duplicate debit reversed within one working day and ₦2,000 goodwill credit applied.',
            'resolution_type' => 'upheld',
            'root_cause_id' => $cause('SYS')?->id,
            'redress_amount_minor' => 87_000_00,
            'redress_currency' => 'NGN',
            'customer_satisfied' => true,
            'linked_control_id' => $control('CTL-002')?->id,
        ]);
        $backdate($resolved->fresh(), ['resolved_at' => now()->subDays(7)]);

        // Acknowledgement window missed and still unresolved — the screen
        // that shows live penalty exposure has something to show.
        $breached = $complaints->intake([
            'tenant_id' => $tid,
            'channel' => 'branch',
            'received_at' => now()->subDays(23),
            'customer_name' => 'Emeka Balogun',
            'customer_reference' => '0987654321',
            'customer_contact' => ['phone' => '+234 803 111 1111'],
            'category_id' => $cat('FEE')?->id,
            'subject' => 'Undisclosed account maintenance charges',
            'description' => 'Maintenance charges applied monthly without notice or a rate disclosure.',
            'product' => 'Current account',
            'amount_disputed_minor' => 42_500_00,
            'currency' => 'NGN',
            'entity_id' => $entity?->id,
            'assigned_to' => $officer2->id,
        ], $officer2);

        $complaints->refreshSla($breached->fresh());

        $awaiting = $complaints->intake([
            'tenant_id' => $tid,
            'channel' => 'social_media',
            'received_at' => now()->subDays(2),
            'customer_name' => 'Ngozi Adeyemi',
            'category_id' => $cat('DIG')?->id,
            'subject' => 'USSD session fails at PIN entry',
            'description' => 'The *966# session drops at PIN entry on one network only.',
            'product' => 'USSD banking',
            'entity_id' => $entity?->id,
            'assigned_to' => $officer1->id,
        ], $officer1);

        $complaints->acknowledge($awaiting->fresh(), $officer1);
        $backdate($awaiting->fresh(), ['acknowledged_at' => now()->subDays(2)->addHours(2)]);
        $complaints->awaitCustomer($awaiting->fresh(), 'Asked the customer for the handset model and network.');

        // A complaint that turned out to be systemic, linked to the outage.
        $systemic = $complaints->intake([
            'tenant_id' => $tid,
            'channel' => 'phone',
            'received_at' => now()->subDays(19),
            'customer_name' => 'Ibrahim Sani',
            'category_id' => $cat('ATM')?->id,
            'subject' => 'Card declined repeatedly on the evening of the outage',
            'description' => 'Card declined at three merchants over an hour on the evening of the switch outage.',
            'product' => 'Debit card',
            'entity_id' => $entity?->id,
            'assigned_to' => $officer2->id,
        ], $officer2);

        $complaints->acknowledge($systemic->fresh(), $officer2);
        $backdate($systemic->fresh(), ['acknowledged_at' => now()->subDays(19)->addHours(4)]);
        $complaints->linkIncident($systemic->fresh(), $outage->fresh());
        $complaints->investigate($systemic->fresh(), $officer2, 'Matched to incident timeline.');
        $complaints->resolve($systemic->fresh(), $officer2, [
            'resolution_summary' => 'Declines confirmed as a consequence of the switch outage; no charges were '
                .'applied and the customer was informed of the remediation.',
            'resolution_type' => 'upheld',
            'root_cause_id' => $cause('SYS')?->id,
            'customer_satisfied' => true,
            'linked_control_id' => $control('CTL-005')?->id,
        ]);
        $backdate($systemic->fresh(), ['resolved_at' => now()->subDays(11)]);

        // ── 4. Cases ─────────────────────────────────────────────────

        // Anonymous whistleblowing report — no reporter identity is stored,
        // and the allowlist is exactly two people.
        $blow = $cases->open([
            'case_type' => 'whistleblowing',
            'title' => 'Concern about procurement awards at a single vendor',
            'description' => 'Three consecutive facilities contracts were awarded to the same vendor without a '
                .'competitive process, apparently at the direction of one manager.',
            'confidentiality' => 'Highly Restricted',
            'severity' => 'High',
            'channel' => 'web',
            'received_at' => now()->subDays(12),
            'entity_id' => $entity?->id,
            'lead_investigator_id' => $cfh->id,
            'access_user_ids' => [$cfh->id, $officer2->id],
        ], null, $tid, anonymous: true);

        $cases->assess($blow['case'], $cfh, [
            'severity' => 'High',
            'confidentiality' => 'Highly Restricted',
            'lead_investigator_id' => $cfh->id,
        ]);

        $cases->addNote(
            $blow['case']->fresh(),
            $cfh,
            'Procurement records for the last four quarters requested from Finance.',
            privileged: false,
            reporterVisible: true,
        );

        // A named investigation, linked to the fraud near miss.
        $investigation = $cases->open([
            'case_type' => 'fraud',
            'title' => 'Vendor bank-detail change attempt — source investigation',
            'description' => 'Establish how the spoofed letterhead was produced and whether internal information '
                .'was used.',
            'confidentiality' => 'Restricted',
            'severity' => 'High',
            'channel' => 'internal',
            'received_at' => now()->subDays(5),
            'entity_id' => $entity?->id,
            'lead_investigator_id' => $officer2->id,
            'access_user_ids' => [$officer2->id, $cfh->id],
            'related_incident_id' => $fraudNearMiss->id,
        ], $officer1, $tid);

        $cases->assess($investigation['case'], $officer2, [
            'severity' => 'High',
            'confidentiality' => 'Restricted',
            'lead_investigator_id' => $officer2->id,
        ]);
        $cases->startInvestigation(
            $investigation['case']->fresh(),
            $officer2,
            'Interview the two approvers, pull the mail gateway logs for the sending domain, and confirm the '
                .'vendor contact number held on file has not been altered.',
        );
        $cases->addNote(
            $investigation['case']->fresh(),
            $officer2,
            'Counsel advises the mail gateway extract be treated as privileged pending the outcome.',
            privileged: true,
        );

        // ── 5. Cross-module linkage (11.5) ───────────────────────────

        $linkage->link('policy', $dataProtection->id, 'incident', $breach->id, 'governs');
        $linkage->link('policy', $complaintsPolicy->id, 'complaint', $breached->id, 'governs');
        $linkage->link('policy', $whistleblowing->id, 'case', $blow['case']->id, 'governs');
        $linkage->link('incident', $fraudNearMiss->id, 'case', $investigation['case']->id, 'escalates_to');
        $linkage->link('complaint', $systemic->id, 'incident', $outage->id, 'escalates_to');

        Auth::logout();
    }
}
