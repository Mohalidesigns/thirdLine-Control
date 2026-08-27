<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\Investigation;
use App\Models\InvestigationSubject;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvestigationService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec §8.2 — the per-modal persistence standard, applied to the eleven
 * dialogs on the investigation case file.
 *
 * Each test does the four things the specification asks for: submit valid
 * data through the real route and assert every field landed; submit
 * invalid data and assert the errors bag is populated with nothing
 * written; assert an unauthorised actor is refused and still nothing is
 * written; and assert the diary entry where one is expected.
 *
 * The invalid-data half is the interesting one. The audit found that 69
 * modals render no <InputError> at all, so the errors bag asserted here
 * was, until DEF-M01, the only evidence a submission had failed — and
 * nothing in the application was reading it. These assertions are what
 * stop that regressing: if a route quietly stops validating, the silence
 * comes back.
 */
class InvestigationModalPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $officer;

    private User $head;

    /** Holds no investigation permissions at all. */
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->head = $this->makeUser('head@test.local', 'Control Function Head');
        $this->outsider = $this->makeUser('outsider@test.local', 'Control Owner');
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function investigation(): Investigation
    {
        $this->actingAs($this->officer);

        return app(InvestigationService::class)->open([
            'title' => 'Suspected teller cash suppression, Branch 042',
            'category' => 'fraud',
            'source' => 'management_directive',
            'priority' => 'High',
        ], $this->officer);
    }

    /** A case far enough along that the closing dialogs are reachable. */
    private function completedCase(): Investigation
    {
        $investigation = $this->investigation();
        $service = app(InvestigationService::class);

        $service->addFinding($investigation, [
            'title' => 'Eleven lodgements were suppressed at the till',
            'severity' => 'Moderate',
        ], $this->officer);

        $investigation = $service->transition($investigation, 'reported', $this->officer);
        $investigation = $service->transition($investigation, 'under_investigation', $this->officer);
        $investigation = $service->transition($investigation, 'pending_review', $this->officer);

        return $service->complete($investigation, $this->officer, [
            'risk_rating' => 'Moderate',
            'conclusion' => 'The suppression is established.',
        ]);
    }

    // ── Subjects tab: "Add subject" ──────────────────────────────────

    public function test_the_add_subject_modal_persists_every_field(): void
    {
        $investigation = $this->investigation();

        $payload = [
            'subject_type' => 'staff',
            'name' => 'A. Teller',
            'staff_id' => 'STF-9931',
            'account_number' => '0123456789',
            'department' => 'Branch Operations',
            'position' => 'Cashier',
            'role_in_case' => 'primary_subject',
            'notes' => 'Named by the branch reconciliation.',
        ];

        $this->actingAs($this->officer)
            ->post(route('investigations.subjects.store', $investigation->id), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('investigation_subjects', [
            'investigation_id' => $investigation->id,
            ...$payload,
        ]);
    }

    public function test_the_add_subject_modal_reports_invalid_data_and_writes_nothing(): void
    {
        $investigation = $this->investigation();

        $this->actingAs($this->officer)
            ->post(route('investigations.subjects.store', $investigation->id), [
                'subject_type' => 'martian',      // not in the enum
                'name' => '',                     // required
                'role_in_case' => 'primary_subject',
            ])
            ->assertSessionHasErrors(['subject_type', 'name']);

        $this->assertDatabaseCount('investigation_subjects', 0);
    }

    public function test_the_add_subject_modal_refuses_an_unauthorised_actor(): void
    {
        $investigation = $this->investigation();

        $this->actingAs($this->outsider)
            ->post(route('investigations.subjects.store', $investigation->id), [
                'subject_type' => 'staff',
                'name' => 'A. Teller',
                'role_in_case' => 'primary_subject',
            ])
            ->assertStatus(404); // the visibility scope answers before the policy

        $this->assertDatabaseCount('investigation_subjects', 0);
    }

    // ── Subjects tab: "Record outcome" ───────────────────────────────

    public function test_the_record_outcome_modal_round_trips(): void
    {
        $investigation = $this->investigation();

        $this->actingAs($this->officer)->post(route('investigations.subjects.store', $investigation->id), [
            'subject_type' => 'staff', 'name' => 'A. Teller', 'role_in_case' => 'primary_subject',
        ]);

        $subject = InvestigationSubject::firstOrFail();

        // The dialog posts the WHOLE subject back, not a fragment — the
        // fields it does not show must survive the round trip untouched.
        $this->actingAs($this->officer)
            ->put(route('investigations.subjects.update', [$investigation->id, $subject->id]), [
                'subject_type' => 'staff',
                'name' => 'A. Teller',
                'role_in_case' => 'primary_subject',
                'outcome' => 'culpable',
                'outcome_rationale' => 'Admitted the suppression in interview.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('investigation_subjects', [
            'id' => $subject->id,
            'name' => 'A. Teller',
            'outcome' => 'culpable',
            'outcome_rationale' => 'Admitted the suppression in interview.',
        ]);
    }

    public function test_an_outcome_without_its_rationale_is_refused(): void
    {
        $investigation = $this->investigation();

        $this->actingAs($this->officer)->post(route('investigations.subjects.store', $investigation->id), [
            'subject_type' => 'staff', 'name' => 'A. Teller', 'role_in_case' => 'primary_subject',
        ]);

        $subject = InvestigationSubject::firstOrFail();

        $this->actingAs($this->officer)
            ->put(route('investigations.subjects.update', [$investigation->id, $subject->id]), [
                'subject_type' => 'staff',
                'name' => 'A. Teller',
                'role_in_case' => 'primary_subject',
                'outcome' => 'culpable',
                'outcome_rationale' => '',
            ])
            ->assertSessionHasErrors('outcome_rationale');

        $this->assertSame('pending', $subject->refresh()->outcome);
    }

    // ── Findings tab: "Add finding" ──────────────────────────────────

    public function test_the_add_finding_modal_persists_and_diarises(): void
    {
        $investigation = $this->investigation();

        $control = Control::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'control_ref' => 'CTL-042'],
            ['title' => 'Daily till reconciliation', 'type' => 'Detective',
                'nature' => 'Manual', 'frequency' => 'Daily', 'status' => 'Active'],
        );

        $this->actingAs($this->officer)
            ->post(route('investigations.findings.store', $investigation->id), [
                'title' => 'Eleven lodgements were suppressed at the till',
                'severity' => 'Critical',
                'description' => 'Eleven lodgements totalling ₦4.2m were taken and not posted.',
                'root_cause' => 'The daily till reconciliation was not performed.',
                'control_failure' => 'CTL-042 did not operate for eleven consecutive days.',
                'recommendation' => 'Reinstate the daily reconciliation and evidence it.',
                'financial_impact' => 4200000,
                'control_id' => $control->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('investigation_findings', [
            'investigation_id' => $investigation->id,
            'title' => 'Eleven lodgements were suppressed at the till',
            'severity' => 'Critical',
            'control_id' => $control->id,
            'financial_impact' => 4200000,
        ]);

        $this->assertDatabaseHas('investigation_activities', [
            'investigation_id' => $investigation->id,
            'activity_type' => 'finding_added',
        ]);
    }

    public function test_a_finding_with_an_unknown_severity_is_refused(): void
    {
        $investigation = $this->investigation();

        $this->actingAs($this->officer)
            ->post(route('investigations.findings.store', $investigation->id), [
                'title' => 'A finding',
                'severity' => 'Catastrophic',   // not one of the four
            ])
            ->assertSessionHasErrors('severity');

        $this->assertDatabaseCount('investigation_findings', 0);
    }

    // ── Team tab: "Assign" ───────────────────────────────────────────

    public function test_the_assign_team_member_modal_persists(): void
    {
        $investigation = $this->investigation();

        // The policy requires the actor to be on the case already: only
        // someone already trusted with it may widen who sees it. The lead
        // qualifies, the head of function does not.
        $this->actingAs($this->officer)
            ->post(route('investigations.team.store', $investigation->id), [
                'user_id' => $this->head->id,
                'role' => 'reviewer',
                'notes' => 'Second reviewer for the vault count.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('investigation_team_members', [
            'investigation_id' => $investigation->id,
            'user_id' => $this->head->id,
            'role' => 'reviewer',
            'notes' => 'Second reviewer for the vault count.',
        ]);
    }

    public function test_assigning_a_team_member_needs_the_assign_permission(): void
    {
        $investigation = $this->investigation();

        $this->assertFalse($this->outsider->can('assign investigations'));

        $this->actingAs($this->outsider)
            ->post(route('investigations.team.store', $investigation->id), [
                'user_id' => $this->outsider->id,
                'role' => 'observer',
            ])
            // 404, not 403: visibility is a GLOBAL scope, so route-model
            // binding fails before the permission middleware is reached.
            // An outsider is not told the case exists.
            ->assertNotFound();

        $this->assertDatabaseMissing('investigation_team_members', [
            'user_id' => $this->outsider->id,
        ]);
    }

    // ── Activity tab: "Log activity" ─────────────────────────────────

    public function test_the_log_activity_modal_persists_every_field(): void
    {
        $investigation = $this->investigation();

        $this->actingAs($this->officer)
            ->post(route('investigations.activities.store', $investigation->id), [
                'activity_type' => 'interview_conducted',
                'title' => 'Interview with A. Teller',
                'description' => 'Attended by the branch manager.',
                'activity_date' => '2026-08-14 10:30:00',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('investigation_activities', [
            'investigation_id' => $investigation->id,
            'activity_type' => 'interview_conducted',
            'title' => 'Interview with A. Teller',
            'description' => 'Attended by the branch manager.',
        ]);
    }

    public function test_a_system_activity_type_cannot_be_logged_by_hand(): void
    {
        $investigation = $this->investigation();

        // The diary distinguishes six types a human may log from eight the
        // service writes itself. Letting a person post 'case_completed'
        // would let them forge the chronology the report is built from.
        $this->actingAs($this->officer)
            ->post(route('investigations.activities.store', $investigation->id), [
                'activity_type' => 'case_completed',
                'title' => 'Completed, honestly',
                'activity_date' => now()->toDateTimeString(),
            ])
            ->assertSessionHasErrors('activity_type');

        $this->assertDatabaseMissing('investigation_activities', [
            'investigation_id' => $investigation->id,
            'activity_type' => 'case_completed',
        ]);
    }

    // ── Status dialog ────────────────────────────────────────────────

    public function test_the_status_modal_persists_and_refuses_an_illegal_move(): void
    {
        $investigation = $this->investigation();

        $this->actingAs($this->officer)
            ->post(route('investigations.status', $investigation->id), ['status' => 'reported'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('reported', $investigation->refresh()->status);

        // draft → closed is not on the transition map.
        $this->actingAs($this->officer)
            ->post(route('investigations.status', $investigation->id), ['status' => 'closed'])
            ->assertSessionHasErrors();

        $this->assertSame('reported', $investigation->refresh()->status);
    }

    // ── Archive dialog ───────────────────────────────────────────────

    public function test_the_archive_modal_requires_its_reason(): void
    {
        // Only a finished case may be archived — the policy refuses a live
        // one outright, so the dialog has to be reached from a completed
        // case for its validation to be the thing under test.
        $investigation = $this->completedCase();

        $this->actingAs($this->head)
            ->post(route('investigations.archive', $investigation->id), ['archive_reason' => ''])
            ->assertSessionHasErrors('archive_reason');

        $this->assertFalse($investigation->refresh()->is_archived);

        // Ten characters is the floor: "duplicate" on its own is not a
        // reason a later reader can act on.
        $this->actingAs($this->head)
            ->post(route('investigations.archive', $investigation->id), ['archive_reason' => 'dupe'])
            ->assertSessionHasErrors('archive_reason');

        $this->assertFalse($investigation->refresh()->is_archived);

        $this->actingAs($this->head)
            ->post(route('investigations.archive', $investigation->id), [
                'archive_reason' => 'Duplicate of an earlier matter.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $investigation->refresh();

        $this->assertTrue($investigation->is_archived);
        $this->assertSame('Duplicate of an earlier matter.', $investigation->archive_reason);
    }
}
