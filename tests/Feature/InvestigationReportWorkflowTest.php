<?php

namespace Tests\Feature;

use App\Models\Investigation;
use App\Models\InvestigationReport;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvestigationReportWorkflow;
use App\Services\InvestigationService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\ReportDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Spec §5.3 and §8.5 — the investigation report's review chain.
 *
 * Draft → Manager Review → Group Head Internal Control Review → Approved
 * → Issued. What is being tested is not that the states change, which is
 * trivial, but the three things that make the chain worth having: that no
 * node can be skipped, that no one person can occupy two of them, and that
 * an issued report stops changing.
 */
class InvestigationReportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    /** Prepares the report: the lead investigator. */
    private User $officer;

    /** Manager review: the head of the control function. */
    private User $head;

    /** Approves and issues: the Group Head Internal Control. */
    private User $ghic;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active', 'data_residency' => 'NG']);
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->head = $this->makeUser('head@test.local', 'Control Function Head');
        $this->ghic = $this->makeUser('ghic@test.local', 'Group Head Internal Control');

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

    private function workflow(): InvestigationReportWorkflow
    {
        return app(InvestigationReportWorkflow::class);
    }

    /** A completed case, which is what produces a report in the first place. */
    private function completedCase(array $overrides = []): Investigation
    {
        $this->actingAs($this->officer);

        $investigation = $this->service()->open([
            'title' => 'Suppressed cash lodgements at Branch 042',
            'category' => 'fraud',
            'source' => 'control_exception',
            'priority' => 'Critical',
            'chronology' => 'Between 3 and 14 May the lodgements were taken at the counter and not posted.',
            ...$overrides,
        ], $this->officer);

        $this->service()->addFinding($investigation, [
            'title' => 'Eleven lodgements were suppressed at the till',
            'severity' => 'Critical',
        ], $this->officer);

        $investigation = $this->service()->transition($investigation, 'reported', $this->officer);
        $investigation = $this->service()->transition($investigation, 'under_investigation', $this->officer);
        $investigation = $this->service()->transition($investigation, 'pending_review', $this->officer);

        return $this->service()->complete($investigation, $this->officer, [
            'risk_rating' => 'Critical',
            'conclusion' => 'The suppression is established and the loss is quantified.',
        ]);
    }

    private function report(Investigation $investigation): InvestigationReport
    {
        return $investigation->reports()->orderByDesc('version')->firstOrFail();
    }

    /** Walk a report all the way to approved. */
    private function toApproved(Investigation $investigation): InvestigationReport
    {
        $report = $this->report($investigation);

        $report = $this->workflow()->advance($report, $this->officer, 'manager_review');
        $report = $this->workflow()->advance($report, $this->head, 'ghic_review', 'All good');

        return $this->workflow()->advance($report, $this->ghic, 'approved');
    }

    // ── Creation ─────────────────────────────────────────────────────

    public function test_completing_a_case_opens_version_one_in_draft(): void
    {
        $investigation = $this->completedCase();
        $report = $this->report($investigation);

        $this->assertSame(1, $report->version);
        $this->assertSame('draft', $report->workflow_state);
        $this->assertSame("{$investigation->reference}-R01", $report->report_number);
        $this->assertSame(
            $investigation->lead_investigator_id,
            $report->prepared_by_id,
            'The report is prepared by whoever ran the investigation.',
        );
    }

    public function test_a_second_completion_does_not_mint_a_second_version_one(): void
    {
        $investigation = $this->completedCase();

        $this->workflow()->openDraft($investigation, $this->officer);
        $this->workflow()->openDraft($investigation, $this->officer);

        $this->assertSame(1, $investigation->reports()->count());
    }

    // ── The chain ────────────────────────────────────────────────────

    public function test_the_full_chain_runs_end_to_end(): void
    {
        $investigation = $this->completedCase();
        $report = $this->toApproved($investigation);

        $this->assertSame('approved', $report->workflow_state);
        $this->assertSame($this->head->id, $report->manager_reviewed_by_id);
        $this->assertSame('All good', $report->manager_comment);
        $this->assertSame($this->ghic->id, $report->ghic_reviewed_by_id);
        $this->assertSame($this->ghic->id, $report->approved_by_id);

        $report = $this->workflow()->issue($report, $this->ghic);

        $this->assertSame('issued', $report->workflow_state);
        $this->assertNotNull($report->issued_at);
        $this->assertSame(now()->toDateString(), $report->issue_date->toDateString());
        $this->assertNotEmpty($report->snapshot, 'Issue must freeze the document.');
    }

    public function test_a_node_cannot_be_skipped(): void
    {
        $investigation = $this->completedCase();
        $report = $this->report($investigation);

        try {
            $this->workflow()->advance($report, $this->ghic, 'approved');
            $this->fail('A draft must not jump straight to approved.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cannot move to', $e->getMessage());
        }

        $this->assertSame('draft', $report->refresh()->workflow_state);
    }

    public function test_each_step_needs_its_own_authority(): void
    {
        $investigation = $this->completedCase();
        $report = $this->workflow()->advance($this->report($investigation), $this->officer, 'manager_review');

        // The officer prepared it and cannot wave it past the manager.
        try {
            $this->workflow()->advance($report, $this->officer, 'ghic_review');
            $this->fail('An officer does not hold the manager review authority.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('authority', $e->getMessage());
        }

        $report = $this->workflow()->advance($report, $this->head, 'ghic_review');

        // The head reviews; approval belongs to the GHIC.
        try {
            $this->workflow()->advance($report, $this->head, 'approved');
            $this->fail('Approval is the Group Head Internal Control\'s act.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('authority', $e->getMessage());
        }
    }

    public function test_the_head_of_control_cannot_issue(): void
    {
        $investigation = $this->completedCase();
        $report = $this->toApproved($investigation);

        $this->expectException(ValidationException::class);
        $this->workflow()->issue($report, $this->head);
    }

    // ── Separation of duties, per person ─────────────────────────────

    public function test_a_preparer_cannot_review_their_own_report(): void
    {
        // The officer is given the reviewing authority outright, so what
        // is being tested is the PERSON check and not the permission one.
        $this->officer->givePermissionTo('review investigation-reports');

        $investigation = $this->completedCase();
        $report = $this->workflow()->advance($this->report($investigation), $this->officer, 'manager_review');

        try {
            $this->workflow()->advance($report, $this->officer, 'ghic_review');
            $this->fail('Holding the permission does not make one person into two.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('prepared this report', $e->getMessage());
        }
    }

    public function test_a_manager_reviewer_cannot_also_approve(): void
    {
        $this->head->givePermissionTo('approve investigation-reports');

        $investigation = $this->completedCase();
        $report = $this->workflow()->advance($this->report($investigation), $this->officer, 'manager_review');
        $report = $this->workflow()->advance($report, $this->head, 'ghic_review');

        try {
            $this->workflow()->advance($report, $this->head, 'approved');
            $this->fail('Approval is a second pair of eyes on the review, not the same pair twice.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('second pair of eyes', $e->getMessage());
        }
    }

    // ── Return ───────────────────────────────────────────────────────

    public function test_a_return_requires_a_reason_and_lands_on_draft(): void
    {
        $investigation = $this->completedCase();
        $report = $this->workflow()->advance($this->report($investigation), $this->officer, 'manager_review');

        try {
            $this->workflow()->returnToPreparer($report, $this->head, '   ');
            $this->fail('A return with no reason cannot be acted on.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('why the report is going back', $e->getMessage());
        }

        $report = $this->workflow()->returnToPreparer($report, $this->head, 'The financial implication table is out of date.');

        $this->assertSame('draft', $report->workflow_state);
        $this->assertSame('The financial implication table is out of date.', $report->returned_reason);
    }

    public function test_a_return_from_ghic_clears_an_approval_already_recorded(): void
    {
        $investigation = $this->completedCase();
        $report = $this->workflow()->advance($this->report($investigation), $this->officer, 'manager_review');
        $report = $this->workflow()->advance($report, $this->head, 'ghic_review');

        $report = $this->workflow()->returnToPreparer($report, $this->ghic, 'The conclusion overstates the recovery.');

        $this->assertSame('draft', $report->workflow_state);
        $this->assertNull($report->approved_by_id);
        $this->assertNull($report->approved_at);
    }

    public function test_a_returned_report_clears_its_reason_when_it_moves_on(): void
    {
        $investigation = $this->completedCase();
        $report = $this->workflow()->advance($this->report($investigation), $this->officer, 'manager_review');
        $report = $this->workflow()->returnToPreparer($report, $this->head, 'Needs the chronology.');

        $report = $this->workflow()->advance($report, $this->officer, 'manager_review');

        $this->assertNull(
            $report->returned_reason,
            'The reason describes the current return, not the last one that ever happened.',
        );
    }

    // ── Immutability, §8.5 ───────────────────────────────────────────

    public function test_an_issued_report_refuses_every_further_transition(): void
    {
        $investigation = $this->completedCase();
        $report = $this->workflow()->issue($this->toApproved($investigation), $this->ghic);

        foreach (['manager_review', 'ghic_review', 'approved'] as $state) {
            try {
                $this->workflow()->advance($report, $this->ghic, $state);
                $this->fail("An issued report must refuse a move to {$state}.");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('has been issued', $e->getMessage());
            }
        }
    }

    public function test_editing_the_case_after_issue_does_not_change_the_issued_report(): void
    {
        $investigation = $this->completedCase();
        $report = $this->workflow()->issue($this->toApproved($investigation), $this->ghic);

        $frozen = $report->snapshot;

        $investigation->update([
            'title' => 'Rewritten after the fact',
            'conclusion' => 'A different conclusion entirely.',
            'confirmed_financial_loss' => 99_000_000,
        ]);

        $this->assertSame(
            $frozen,
            $report->refresh()->snapshot,
            'The snapshot is what -R01 said on the day it was signed.',
        );

        $this->assertSame(
            $frozen,
            $this->workflow()->documentFor($report, $this->ghic),
            'A reader of an issued report must be shown the issued document, not the live case.',
        );
    }

    public function test_a_further_version_is_r02_and_leaves_r01_alone(): void
    {
        $investigation = $this->completedCase();
        $first = $this->workflow()->issue($this->toApproved($investigation), $this->ghic);

        $second = $this->workflow()->openNextVersion($investigation->refresh(), $this->officer);

        $this->assertSame(2, $second->version);
        $this->assertSame("{$investigation->reference}-R02", $second->report_number);
        $this->assertSame('draft', $second->workflow_state);

        $this->assertSame('issued', $first->refresh()->workflow_state);
        $this->assertSame("{$investigation->reference}-R01", $first->report_number);
    }

    public function test_a_further_version_is_refused_while_one_is_still_in_flight(): void
    {
        $investigation = $this->completedCase();

        try {
            $this->workflow()->openNextVersion($investigation, $this->officer);
            $this->fail('Two live reports on one case is two answers to the same question.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('has not been issued yet', $e->getMessage());
        }
    }

    // ── The case diary ───────────────────────────────────────────────

    public function test_every_step_lands_on_the_case_timeline(): void
    {
        $investigation = $this->completedCase();
        $this->workflow()->issue($this->toApproved($investigation), $this->ghic);

        $titles = $investigation->activities()
            ->whereIn('activity_type', ['report_reviewed', 'report_issued'])
            ->pluck('title')
            ->implode(' | ');

        $this->assertStringContainsString('Manager Review', $titles);
        $this->assertStringContainsString('Group Head Internal Control Review', $titles);
        $this->assertStringContainsString('Approved', $titles);
        $this->assertStringContainsString('issued', $titles);
    }

    public function test_the_issue_is_the_only_step_logged_as_an_issue(): void
    {
        $investigation = $this->completedCase();
        $this->workflow()->issue($this->toApproved($investigation), $this->ghic);

        $issued = $investigation->activities()
            ->where('activity_type', 'report_issued')
            ->where('linked_type', InvestigationReport::class)
            ->count();

        $this->assertSame(
            1,
            $issued,
            'Logging a review as an issue would print "Report Issued" in the report\'s own chronology for something that was not.',
        );
    }

    // ── Reach ────────────────────────────────────────────────────────

    public function test_an_outsider_cannot_open_the_report_of_a_confidential_case(): void
    {
        $investigation = $this->completedCase(['is_confidential' => true]);
        $report = $this->report($investigation);

        $outsider = $this->makeUser('outsider@test.local', 'Control Officer');

        $this->actingAs($outsider)
            ->get(route('investigations.reports.show', [$investigation->id, $report->id]))
            ->assertNotFound();
    }

    public function test_the_report_page_renders_the_document_and_the_available_actions(): void
    {
        $investigation = $this->completedCase();
        $report = $this->report($investigation);

        $this->actingAs($this->officer)
            ->get(route('investigations.reports.show', [$investigation->id, $report->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Investigations/Report')
                ->where('report.workflow_state', 'draft')
                ->where('report.report_number', "{$investigation->reference}-R01")
                // The preparer may submit it and nothing else.
                ->where('can.submit', true)
                ->where('can.approve', false)
                ->where('can.issue', false)
                ->where('can.download', false)
                ->has('document.sections'));
    }

    public function test_the_issued_page_serves_the_snapshot_and_offers_the_download(): void
    {
        $investigation = $this->completedCase();
        $report = $this->workflow()->issue($this->toApproved($investigation), $this->ghic);

        $investigation->update(['title' => 'Rewritten after the fact']);

        $this->actingAs($this->ghic)
            ->get(route('investigations.reports.show', [$investigation->id, $report->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('report.workflow_state', 'issued')
                ->where('can.submit', false)
                // The document served is the frozen one, not the retitled case.
                ->where('document.title', 'Investigation Report — Suppressed cash lodgements at Branch 042'));
    }

    public function test_a_report_id_from_another_case_does_not_resolve(): void
    {
        $mine = $this->completedCase();
        $theirs = $this->completedCase(['title' => 'A different matter entirely']);

        $this->actingAs($this->officer)
            ->get(route('investigations.reports.show', [$mine->id, $this->report($theirs)->id]))
            ->assertNotFound();
    }
}
