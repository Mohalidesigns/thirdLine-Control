<?php

namespace Tests\Feature;

use App\Models\Investigation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvestigationService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Spec §7.3, §7.5 and §7.6 — the three defects that let a case assert more
 * than its record supports.
 *
 * The reference implementation shows one investigation marked Completed
 * and rated High with Subjects (0), Findings (0), Consequence Management
 * (0) and Evidence (0); a confirmed loss half again its own estimate with
 * nothing said about it; and a days-open counter still running on a case
 * that finished a fortnight ago. Each is a number that reads as a
 * conclusion and is not one.
 */
class InvestigationCompletionGateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->officer = User::factory()->create([
            'email' => 'officer@test.local',
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);
        $this->officer->assignRole('Control Officer');
    }

    private function service(): InvestigationService
    {
        return app(InvestigationService::class);
    }

    private function pendingReview(array $overrides = []): Investigation
    {
        $this->actingAs($this->officer);

        $investigation = $this->service()->open([
            'title' => 'Suspected teller cash suppression, Branch 042',
            'category' => 'fraud',
            'source' => 'management_directive',
            'priority' => 'High',
            ...$overrides,
        ], $this->officer);

        $investigation = $this->service()->transition($investigation, 'reported', $this->officer);
        $investigation = $this->service()->transition($investigation, 'under_investigation', $this->officer);

        return $this->service()->transition($investigation, 'pending_review', $this->officer);
    }

    private function addFinding(Investigation $investigation): void
    {
        $this->service()->addFinding($investigation, [
            'title' => 'Eleven lodgements were suppressed at the till',
            'severity' => 'Moderate',
        ], $this->officer);
    }

    // ── §7.5 — the hard half of the gate ─────────────────────────────

    public function test_completion_is_blocked_without_a_finding(): void
    {
        $investigation = $this->pendingReview();

        try {
            $this->service()->complete($investigation, $this->officer, [
                'risk_rating' => 'High',
                'conclusion' => 'Established.',
            ]);
            $this->fail('A completed case with no findings produces a report with nothing in it.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('at least one finding', $e->getMessage());
        }

        $this->assertSame('pending_review', $investigation->refresh()->status);
    }

    public function test_completion_is_blocked_without_a_conclusion(): void
    {
        $investigation = $this->pendingReview();
        $this->addFinding($investigation);

        try {
            $this->service()->complete($investigation, $this->officer, ['risk_rating' => 'High']);
            $this->fail('The conclusion is the one report section that cannot be generated from the record.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('without a conclusion', $e->getMessage());
        }

        $this->assertSame('pending_review', $investigation->refresh()->status);
    }

    public function test_a_whitespace_conclusion_does_not_satisfy_the_gate(): void
    {
        $investigation = $this->pendingReview();
        $this->addFinding($investigation);

        $this->expectException(ValidationException::class);

        $this->service()->complete($investigation, $this->officer, [
            'risk_rating' => 'High',
            'conclusion' => "   \n  ",
        ]);
    }

    // ── §7.5 — the soft half: warn, never block ──────────────────────

    public function test_a_case_with_no_subject_or_evidence_completes_but_warns(): void
    {
        $investigation = $this->pendingReview();
        $this->addFinding($investigation);

        $warnings = $this->service()->completionWarnings($investigation);

        $this->assertContains('no subject was named', $warnings);
        $this->assertContains('no evidence was attached', $warnings);

        $investigation = $this->service()->complete($investigation, $this->officer, [
            'risk_rating' => 'High',
            'conclusion' => 'A process failure with no culpable individual.',
        ]);

        $this->assertSame(
            'completed',
            $investigation->status,
            'A process failure with nobody named is a legitimate outcome — warn, do not block.',
        );
    }

    // ── §7.3 — confirmed loss past the opening estimate ──────────────

    public function test_a_confirmed_loss_well_past_the_estimate_is_flagged_not_blocked(): void
    {
        // The reference case: ₦32.9m estimated, ₦50m confirmed.
        $investigation = $this->pendingReview(['estimated_financial_impact' => 32_900_000]);
        $investigation->update(['confirmed_financial_loss' => 50_000_000]);

        $this->assertTrue($investigation->confirmedLossExceedsEstimate());

        $this->addFinding($investigation);

        $investigation = $this->service()->complete($investigation, $this->officer, [
            'risk_rating' => 'High',
            'conclusion' => 'The loss is larger than first estimated.',
        ]);

        $this->assertSame('completed', $investigation->status);
    }

    public function test_a_confirmed_loss_within_tolerance_is_not_flagged(): void
    {
        $investigation = $this->pendingReview(['estimated_financial_impact' => 10_000_000]);

        // Ten per cent over — an ordinary refinement, not a re-scoping.
        $investigation->update(['confirmed_financial_loss' => 11_000_000]);
        $this->assertFalse($investigation->confirmedLossExceedsEstimate());

        // A loss below the estimate is the common case and never flags.
        $investigation->update(['confirmed_financial_loss' => 4_000_000]);
        $this->assertFalse($investigation->confirmedLossExceedsEstimate());
    }

    public function test_the_financial_impact_figure_says_which_basis_it_is_on(): void
    {
        $investigation = $this->pendingReview(['estimated_financial_impact' => 32_900_000]);

        $this->assertSame(
            ['amount' => 32_900_000.0, 'basis' => 'Estimated'],
            $investigation->financialImpact(),
        );

        $investigation->update(['confirmed_financial_loss' => 50_000_000]);

        $this->assertSame(
            ['amount' => 50_000_000.0, 'basis' => 'Confirmed'],
            $investigation->refresh()->financialImpact(),
            'Labelling a confirmed loss "exposure" reports a finding as an estimate.',
        );
    }

    // ── §7.6 — the clock stops when the case does ────────────────────

    public function test_days_open_freezes_once_the_case_is_completed(): void
    {
        $investigation = $this->pendingReview();
        $this->addFinding($investigation);

        $investigation->update(['reported_date' => now()->subDays(40)->toDateString()]);

        $investigation = $this->service()->complete($investigation, $this->officer, [
            'risk_rating' => 'High',
            'conclusion' => 'Established.',
            'completed_date' => now()->subDays(14)->toDateString(),
        ]);

        $this->assertSame(
            26,
            $investigation->daysOpen(),
            'Days open must measure how long the investigation took, not how long ago it started.',
        );

        // Travelling forward must not move a finished case's counter.
        $this->travel(10)->days();

        $this->assertSame(26, $investigation->fresh()->daysOpen());
    }

    public function test_days_open_still_accrues_on_a_live_case(): void
    {
        $investigation = $this->pendingReview();
        $investigation->update(['reported_date' => now()->subDays(9)->toDateString()]);

        $this->assertSame(9, $investigation->refresh()->daysOpen());
    }
}
