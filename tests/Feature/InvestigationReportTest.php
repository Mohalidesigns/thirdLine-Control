<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\Evidence;
use App\Models\Investigation;
use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CaseService;
use App\Services\ConsequenceService;
use App\Services\InvestigationReportBuilder;
use App\Services\InvestigationService;
use App\Services\ReportDesignerService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\ReportDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The investigation report (§E.3).
 *
 * Two structural claims are tested here. Nine of the thirteen sections are
 * generated from the record rather than typed, so the report cannot drift
 * from the case. And report generation can never strand a completed
 * investigation — a failed render is a report to re-run, not a case stuck
 * in pending_review.
 */
class InvestigationReportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $head;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active', 'data_residency' => 'NG']);
        $this->head = $this->makeUser('head@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');

        $this->seed(ReportDefinitionSeeder::class);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function service(): InvestigationService
    {
        return app(InvestigationService::class);
    }

    private function builder(): InvestigationReportBuilder
    {
        return app(InvestigationReportBuilder::class);
    }

    /** A fully populated investigation: subjects, findings, consequences, evidence, diary. */
    private function fullCase(): Investigation
    {
        $this->actingAs($this->officer);

        $investigation = $this->service()->open([
            'title' => 'Suppressed cash lodgements at Branch 042',
            'category' => 'fraud',
            'source' => 'control_exception',
            'priority' => 'Critical',
            'estimated_financial_impact' => 4200000,
            'background' => 'A branch reconciliation flagged eleven lodgements with no matching credit.',
            'scope' => 'Branch 042, 1 January to 30 June.',
            'objectives' => 'Establish whether lodgements were suppressed and by whom.',
            'methodology' => 'CBS extract review, CCTV review, four interviews.',
        ], $this->officer);

        $control = Control::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'control_ref' => 'CTL-042'],
            [
                'title' => 'Daily till reconciliation', 'type' => 'Detective',
                'nature' => 'Manual', 'frequency' => 'Daily', 'status' => 'Active',
            ],
        );

        $subject = $this->service()->addSubject($investigation, [
            'subject_type' => 'staff', 'name' => 'A. Teller',
            'staff_id' => 'STF-9931', 'account_number' => '0123456789',
            'department' => 'Branch Operations', 'role_in_case' => 'primary_subject',
        ], $this->officer);

        $this->service()->addFinding($investigation, [
            'title' => 'Eleven lodgements were suppressed at the till',
            'severity' => 'Critical',
            'description' => 'Eleven lodgements totalling ₦4.2m were taken and not posted.',
            'root_cause' => 'The daily till reconciliation was not performed.',
            'control_failure' => 'CTL-042 did not operate for eleven consecutive days.',
            'recommendation' => 'Reinstate the daily reconciliation and evidence it.',
            'financial_impact' => 4200000,
            'control_id' => $control->id,
        ], $this->officer);

        $this->service()->recordActivity($investigation, [
            'activity_type' => 'interview_conducted',
            'title' => 'Interview with A. Teller',
        ], $this->officer);

        Evidence::create([
            'tenant_id' => $this->tenant->id,
            'linked_type' => Investigation::class,
            'linked_id' => $investigation->id,
            'file_name' => 'cbs-extract.csv',
            'storage_path' => 'evidence/2026/08/cbs-extract.csv',
            'checksum' => str_repeat('a', 64),
            'uploaded_by' => $this->officer->id,
            'uploaded_at' => now(),
            'collected_by' => $this->officer->id,
            'collected_on' => now()->toDateString(),
            'collection_source' => 'CBS extract, Branch 042',
        ]);

        $action = app(ConsequenceService::class)->recommend($investigation, [
            'action_type' => 'dismissal',
            'investigation_subject_id' => $subject->id,
        ], $this->officer);
        app(ConsequenceService::class)->approve($action, $this->head);

        $this->service()->recordSubjectOutcome($subject, 'culpable', 'Admitted the suppression in interview.', $this->officer);

        $investigation = $this->service()->transition($investigation, 'reported', $this->officer);
        $investigation = $this->service()->transition($investigation, 'under_investigation', $this->officer);

        return $this->service()->transition($investigation, 'pending_review', $this->officer);
    }

    // ── The thirteen sections ────────────────────────────────────────

    public function test_all_thirteen_sections_build(): void
    {
        $sections = $this->builder()->sections($this->fullCase());

        $this->assertSame(
            InvestigationReportBuilder::SECTIONS,
            array_column($sections, 'key'),
            'The report is thirteen sections in a fixed order — a missing one is a missing answer.',
        );
    }

    public function test_the_generated_sections_carry_the_record_not_a_placeholder(): void
    {
        $investigation = $this->fullCase();
        $sections = collect($this->builder()->sections($investigation))->keyBy('key');

        // Spec §5.3-2: the investigating team leads the parties section,
        // then the people the investigation named.
        $parties = $sections['parties']['table']['rows'];

        $this->assertSame($investigation->leadInvestigator->name, $parties[0][0]);
        $this->assertSame('Investigation team', $parties[0][1]);
        $this->assertSame('Lead', $parties[0][2]);

        $this->assertContains('A. Teller', array_column($parties, 0));

        // §7.2 — the lead reaches this table from two directions and must
        // still appear once.
        $names = array_column($parties, 0);
        $this->assertSame(
            count($names),
            count(array_unique($names)),
            'The lead is both a team member and lead_investigator_id — the report must print them once.',
        );

        $this->assertNotEmpty($sections['chronology']['table']['rows']);
        $this->assertSame('CTL-042', $sections['findings_of_fact']['table']['rows'][0][3]);
        $this->assertStringContainsString('CTL-042', $sections['root_cause']['body']);
        $this->assertSame('Dismissal', $sections['consequence_management']['table']['rows'][0][1]);
        $this->assertSame('cbs-extract.csv', $sections['evidence_register']['table']['rows'][0][1]);
        $this->assertStringContainsString('CBS extract, Branch 042', json_encode($sections['evidence_register']));
    }

    public function test_the_cover_prints_a_recorded_segregation_of_duties_conflict(): void
    {
        $investigation = $this->fullCase();
        $investigation->update(['has_sod_conflict' => true, 'sod_conflict_note' => 'The lead owns CTL-042.']);

        $document = $this->builder()->document($investigation->refresh(), $this->officer);

        $this->assertStringContainsString('The lead owns CTL-042.', json_encode($document['sections'][0]));
    }

    // ── Generation and the pipeline ──────────────────────────────────

    public function test_generation_produces_a_run_through_the_shared_pipeline(): void
    {
        $investigation = $this->service()->complete($this->fullCase(), $this->officer, ['risk_rating' => 'Critical', 'conclusion' => 'The suppression is established and the loss is quantified.']);

        $run = ReportRun::query()->latest('id')->first();

        $this->assertNotNull($run, 'Completing an investigation generates its draft report.');
        $this->assertSame('Completed', $run->status, (string) $run->error_message);
        $this->assertNotNull($run->checksum, 'The checksum comes free with the shared pipeline.');
        $this->assertNotNull($run->download_token);
        $this->assertTrue(Storage::disk('local')->exists($run->output_path));

        $this->assertSame(
            'INV-REPORT',
            ReportDefinition::withoutGlobalScopes()->find($run->report_definition_id)->code,
        );
    }

    public function test_the_report_renders_in_every_format_it_declares(): void
    {
        foreach (['pdf', 'docx'] as $format) {
            $investigation = $this->fullCase();

            $run = $this->builder()->generate($investigation, $this->officer, $format);

            $this->assertSame('Completed', $run->status, "The investigation report failed to render as {$format}: {$run->error_message}");
            $this->assertGreaterThan(0, $run->file_size, "The {$format} came out empty.");
        }
    }

    public function test_the_run_lands_on_the_case_chronology(): void
    {
        $investigation = $this->service()->complete($this->fullCase(), $this->officer, ['risk_rating' => 'Critical', 'conclusion' => 'The suppression is established and the loss is quantified.']);

        $this->assertTrue($investigation->activities()->where('activity_type', 'report_issued')->exists());
        $this->assertTrue($this->builder()->hasReport($investigation));
        $this->assertCount(1, $this->builder()->runsFor($investigation));
    }

    public function test_regeneration_is_blocked_once_a_run_exists(): void
    {
        $investigation = $this->service()->complete($this->fullCase(), $this->officer, ['risk_rating' => 'Critical', 'conclusion' => 'The suppression is established and the loss is quantified.']);

        $this->expectException(ValidationException::class);
        $this->builder()->generate($investigation->refresh(), $this->officer);
    }

    /**
     * The rule the source module got right and this one keeps: a report
     * that fails to render must not leave the investigation stranded in
     * pending_review, because the investigation genuinely IS complete.
     */
    public function test_a_failed_report_does_not_roll_back_the_completion(): void
    {
        $investigation = $this->fullCase();

        $this->app->bind(InvestigationReportBuilder::class, fn () => new class(app(ReportDesignerService::class)) extends InvestigationReportBuilder
        {
            public function generate(Investigation $investigation, User $user, string $format = 'pdf'): ReportRun
            {
                throw new \RuntimeException('dompdf fell over');
            }
        });

        $investigation = app(InvestigationService::class)->complete($investigation, $this->officer, ['risk_rating' => 'High', 'conclusion' => 'The suppression is established and the loss is quantified.']);

        $this->assertSame('completed', $investigation->status);
        $this->assertSame('High', $investigation->risk_rating);
    }

    // ── The Speak Up boundary in the report ──────────────────────────

    public function test_no_reporter_identity_appears_in_a_speak_up_origin_report(): void
    {
        $reporter = $this->makeUser('reporter@test.local', 'Control Owner');

        $case = app(CaseService::class)->open([
            'case_type' => 'whistleblowing',
            'title' => 'Lodgements suppressed at the till',
            'description' => 'Eleven lodgements with no matching credit.',
            'confidentiality' => 'Highly Restricted',
            'severity' => 'High',
            'channel' => 'web',
            'lead_investigator_id' => $this->officer->id,
            'access_user_ids' => [$this->officer->id],
        ], $reporter, $this->tenant->id, false)['case'];

        $this->actingAs($this->officer);

        $investigation = $this->service()->open([
            'title' => 'Suppressed lodgements',
            'category' => 'fraud',
            'source' => 'whistleblowing',
            'priority' => 'High',
        ], $this->officer, $case);

        // The leak this test was written to catch lived in the RECORD, not
        // in the rendering: the Speak Up allowlist carries the reporter so
        // they can follow their own report, and the investigation team was
        // seeded from that allowlist wholesale. It went unnoticed while the
        // report declined to print its own team. Assert the record first.
        $this->assertFalse(
            $investigation->teamMembers()->where('user_id', $reporter->id)->exists(),
            'A Speak Up reporter must not be seeded onto the investigation their report opened.',
        );

        $document = json_encode($this->builder()->document($investigation, $this->officer));

        $this->assertStringNotContainsString($reporter->name, $document);
        $this->assertStringNotContainsString($reporter->email, $document);
        $this->assertStringContainsString(
            'a Speak Up report',
            $document,
            'The origin is named by type — that is all a reader of the report needs.',
        );
    }

    public function test_a_confidential_investigation_report_is_classified_board(): void
    {
        $this->actingAs($this->officer);

        $investigation = $this->service()->open([
            'title' => 'Confidential matter', 'category' => 'fraud',
            'source' => 'management_directive', 'priority' => 'High', 'is_confidential' => true,
        ], $this->officer);

        $document = $this->builder()->document($investigation, $this->officer);

        $this->assertSame('Board', $document['confidentiality']);
    }
}
