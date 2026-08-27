<?php

namespace Tests\Feature;

use App\Models\CaseFollowUp;
use App\Models\Investigation;
use App\Models\SpeakUpCase;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CaseService;
use App\Services\InvestigationReportBuilder;
use App\Services\InvestigationService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\ReportDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Spec §5.4 — the Speak Up follow-up surface, and the anonymity guarantee
 * that runs underneath it.
 *
 * The surface exists so a concern can be worked, chased and reported back
 * on WITHOUT opening the investigation it may have produced. The tests
 * that matter most here are the ones about what does NOT cross the
 * boundary: a reporter's identity must not reach the investigation, its
 * report, the frozen snapshot or the PDF, and an officer handling the
 * submission must not gain the case file by handling it.
 */
class SpeakUpFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $officer;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active', 'data_residency' => 'NG']);
        $this->officer = $this->makeUser('officer@test.local', 'Control Function Head');
        $this->other = $this->makeUser('other@test.local', 'Control Function Head');
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function cases(): CaseService
    {
        return app(CaseService::class);
    }

    /** @return array{case: SpeakUpCase, token: ?string} */
    private function submission(bool $anonymous = false, ?User $reporter = null): array
    {
        return $this->cases()->open([
            'case_type' => 'whistleblowing',
            'title' => 'Lodgements suppressed at the till',
            'description' => 'Eleven lodgements with no matching credit.',
            'confidentiality' => 'Highly Restricted',
            'severity' => 'High',
            'channel' => 'web',
            'access_user_ids' => [$this->officer->id],
        ], $reporter, $this->tenant->id, $anonymous);
    }

    // ── Screening ────────────────────────────────────────────────────

    public function test_screening_records_the_decision_its_reasoning_and_when(): void
    {
        $case = $this->submission()['case'];
        $this->actingAs($this->officer);

        $case = $this->cases()->triage($case, $this->officer, [
            'triage_decision' => 'refer_to_investigation',
            'triage_note' => 'Specific, first-hand and corroborated by the branch reconciliation.',
        ]);

        $this->assertSame('refer_to_investigation', $case->triage_decision);
        $this->assertNotNull($case->triaged_at);
        $this->assertSame($this->officer->id, $case->triaged_by);
        $this->assertSame(0, $case->daysToScreen());
    }

    public function test_a_screening_decision_without_reasoning_is_refused(): void
    {
        $case = $this->submission()['case'];
        $this->actingAs($this->officer);

        $this->expectException(ValidationException::class);

        $this->cases()->triage($case, $this->officer, [
            'triage_decision' => 'close_unsubstantiated',
            'triage_note' => '   ',
        ]);
    }

    public function test_revising_the_decision_does_not_restart_the_screening_clock(): void
    {
        $case = $this->submission()['case'];
        $this->actingAs($this->officer);

        $case = $this->cases()->triage($case, $this->officer, [
            'triage_decision' => 'monitor',
            'triage_note' => 'Watching for a second report before acting.',
        ]);

        $first = $case->triaged_at;

        $this->travel(5)->days();

        $case = $this->cases()->triage($case, $this->officer, [
            'triage_decision' => 'refer_to_investigation',
            'triage_note' => 'A second report has arrived naming the same till.',
        ]);

        $this->assertEquals(
            $first->timestamp,
            $case->triaged_at->timestamp,
            'Time-to-screen measures the FIRST decision, or looking at something twice would flatter the metric.',
        );
        $this->assertSame('refer_to_investigation', $case->triage_decision);
    }

    // ── Acknowledgement ──────────────────────────────────────────────

    public function test_acknowledging_stamps_once_and_writes_a_reporter_visible_note(): void
    {
        $case = $this->submission()['case'];
        $this->actingAs($this->officer);

        $case = $this->cases()->acknowledge($case, $this->officer, 'We have received your report and are reviewing it.');

        $first = $case->acknowledged_at;
        $this->assertNotNull($first);

        $this->assertDatabaseHas('case_notes', [
            'case_id' => $case->id,
            'is_reporter_visible' => true,
            'is_privileged' => false,
        ]);

        $this->travel(2)->days();
        $case = $this->cases()->acknowledge($case, $this->officer, 'A further update.');

        $this->assertEquals(
            $first->timestamp,
            $case->acknowledged_at->timestamp,
            'The question is when the reporter was FIRST told.',
        );
    }

    // ── Follow-up log ────────────────────────────────────────────────

    public function test_a_follow_up_is_tracked_completed_and_reported_overdue(): void
    {
        $case = $this->submission()['case'];
        $this->actingAs($this->officer);

        $followUp = $this->cases()->addFollowUp($case, $this->officer, [
            'action' => 'Pull the till tapes for 3–14 May',
            'owner_id' => $this->officer->id,
            'due_date' => now()->subDay()->toDateString(),
        ]);

        $this->assertDatabaseHas('case_follow_ups', [
            'case_id' => $case->id,
            'action' => 'Pull the till tapes for 3–14 May',
            'owner_id' => $this->officer->id,
        ]);

        $this->assertTrue($followUp->isOverdue());
        $this->assertSame(1, CaseFollowUp::overdue()->count());

        $followUp = $this->cases()->completeFollowUp($followUp, $this->officer);

        $this->assertTrue($followUp->isComplete());
        $this->assertFalse($followUp->isOverdue(), 'A completed action is never overdue.');
        $this->assertSame(0, CaseFollowUp::overdue()->count());
    }

    public function test_a_reporter_cannot_screen_or_follow_up_their_own_report(): void
    {
        $reporter = $this->makeUser('reporter@test.local', 'Control Function Head');
        $case = $this->submission(anonymous: false, reporter: $reporter)['case'];

        // The reporter is on their own case's allowlist so they can follow
        // it, and holds `investigate cases` through their role. Neither may
        // let them screen the concern they raised.
        $this->assertTrue($case->grantsAccessTo($reporter));
        $this->assertTrue($reporter->can('investigate cases'));

        $this->assertFalse($reporter->can('followUp', $case));
        $this->assertTrue($this->officer->can('followUp', $case));
    }

    // ── The link, both ways ──────────────────────────────────────────

    public function test_the_submission_and_the_investigation_link_both_ways(): void
    {
        $case = $this->submission()['case'];
        $this->actingAs($this->officer);

        $investigation = app(InvestigationService::class)->open([
            'title' => 'Suppressed lodgements',
            'category' => 'fraud',
            'source' => 'whistleblowing',
            'priority' => 'High',
        ], $this->officer, $case);

        $this->assertSame(SpeakUpCase::class, $investigation->origin_type);
        $this->assertSame($case->id, $investigation->origin_id);

        $this->assertSame(
            $investigation->id,
            $case->fresh()->investigation?->id,
            'The submission must be able to reach its investigation without a second column that could disagree.',
        );
    }

    public function test_the_linked_investigation_is_invisible_to_someone_not_on_its_team(): void
    {
        $case = $this->submission()['case'];
        $this->actingAs($this->officer);

        app(InvestigationService::class)->open([
            'title' => 'Suppressed lodgements',
            'category' => 'fraud',
            'source' => 'whistleblowing',
            'priority' => 'High',
        ], $this->officer, $case);

        // An intake officer with no confidential authority: granted the
        // SUBMISSION, never the investigation.
        $intake = $this->makeUser('intake@test.local', 'Control Officer');
        $this->cases()->grantAccess($case, $intake, $this->officer);

        $this->assertFalse(
            $intake->can('view confidential-investigations'),
            'This test is only meaningful for someone without the confidential override.',
        );

        $this->actingAs($intake);

        $this->assertTrue($case->fresh()->grantsAccessTo($intake));
        $this->assertNull(
            $case->fresh()->investigation,
            'Being on the submission must not open the case file — the investigation has its own visibility scope.',
        );

        // The other side of the same rule: the named confidential authority
        // does reach it. `other` is a Control Function Head, which holds
        // 'view confidential-investigations'.
        $this->cases()->grantAccess($case->fresh(), $this->other, $this->officer);
        $this->actingAs($this->other);

        $this->assertNotNull(
            $case->fresh()->investigation,
            'The confidential authority is exactly who should still see it.',
        );
    }

    // ── The anonymity guarantee, end to end ──────────────────────────

    /**
     * Spec §5.4 asks for this one explicitly: create an anonymous
     * submission, raise an investigation, issue the report, and assert the
     * reporter's identity and device fields appear nowhere in the
     * investigation record, the snapshot, the PDF text or any export.
     */
    public function test_no_reporter_trace_survives_from_an_anonymous_submission_to_an_issued_report(): void
    {
        $this->seed(ReportDefinitionSeeder::class);

        $result = $this->submission(anonymous: true);
        $case = $result['case'];

        $this->assertNull($case->reporter_id, 'An anonymous submission has no reporter to leak.');
        $this->assertNotNull($result['token'], 'The reporter gets a token, and only its hash is stored.');

        $this->actingAs($this->officer);

        $this->cases()->triage($case, $this->officer, [
            'triage_decision' => 'refer_to_investigation',
            'triage_note' => 'Corroborated by the branch reconciliation.',
        ]);

        $service = app(InvestigationService::class);

        $investigation = $service->open([
            'title' => 'Suppressed lodgements',
            'category' => 'fraud',
            'source' => 'whistleblowing',
            'priority' => 'High',
        ], $this->officer, $case);

        $service->addFinding($investigation, [
            'title' => 'Eleven lodgements were suppressed at the till',
            'severity' => 'Critical',
        ], $this->officer);

        $investigation = $service->transition($investigation, 'reported', $this->officer);
        $investigation = $service->transition($investigation, 'under_investigation', $this->officer);
        $investigation = $service->transition($investigation, 'pending_review', $this->officer);
        $investigation = $service->complete($investigation, $this->officer, [
            'risk_rating' => 'Critical',
            'conclusion' => 'The suppression is established.',
        ]);

        $document = json_encode(app(InvestigationReportBuilder::class)->document($investigation->fresh(), $this->officer));
        $record = json_encode($investigation->fresh()->toArray());

        // The plaintext token is the reporter's only credential. It must
        // not appear anywhere on the investigation side.
        foreach ([$document, $record] as $haystack) {
            $this->assertStringNotContainsString($result['token'], $haystack);
            $this->assertStringNotContainsString($case->reporter_token_hash ?? 'never-null', $haystack);
        }

        // No device metadata row exists for an anonymous submission at all,
        // so there is nothing that could be copied across.
        $this->assertFalse(
            $case->metadata()->exists(),
            'An anonymous submission captures no device metadata — the 11.4 guarantee is load-bearing.',
        );

        // The origin is named by TYPE, never by reporter.
        $this->assertStringContainsString('a Speak Up report', $document);

        $this->assertSame(
            0,
            $investigation->teamMembers()->whereNotIn('user_id', [$this->officer->id])->count(),
            'Only the officer who raised it is on the team; no reporter is seeded across.',
        );
    }
}
