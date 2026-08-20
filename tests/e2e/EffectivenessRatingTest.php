<?php

namespace Tests\e2e;

use App\Models\CompensatingControl;
use App\Models\Control;
use App\Models\EffectivenessRating;
use App\Models\RatingMatrixEntry;
use App\Models\Risk;
use App\Models\TestInstance;
use App\Services\ResidualRiskService;
use App\Services\TestingService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * TC-08 — control effectiveness ratings (FR-7.1 … FR-7.8).
 *
 * TC-08-03 requires the *full* design × operating matrix, so all sixteen
 * combinations are asserted individually rather than sampled. The plan
 * singles out one cell — design-effective + operating-ineffective must not
 * yield "Effective" — and FR-7.4 adds a rule of its own: a
 * design-ineffective control cannot be rated better than Partially
 * Effective overall. Both are asserted as properties over the whole
 * matrix, so they survive a reconfiguration of the seeded data.
 */
class EffectivenessRatingTest extends E2ETestCase
{
    private const SCALE = ['Effective', 'Partially Effective', 'Ineffective', 'Not Tested'];

    private const RANK = ['Ineffective' => 0, 'Not Tested' => 1, 'Partially Effective' => 2, 'Effective' => 3];

    private function service(): TestingService
    {
        return app(TestingService::class);
    }

    private function ratableInstance(): TestInstance
    {
        $instance = TestInstance::withoutGlobalScopes()->firstOrFail();
        $instance->forceFill(['status' => 'Submitted'])->saveQuietly();

        return $instance->refresh();
    }

    private function ratingPayload(string $design, string $operating): array
    {
        return [
            'design_effectiveness' => $design,
            'design_rationale' => $design === 'Effective' ? null : 'E2E rationale for design.',
            'operating_effectiveness' => $operating,
            'operating_rationale' => $operating === 'Effective' ? null : 'E2E rationale for operating.',
        ];
    }

    // -----------------------------------------------------------------
    // TC-08-03 — the full 16-cell matrix
    // -----------------------------------------------------------------

    public static function matrixProvider(): array
    {
        $expected = [
            'Effective' => ['Effective' => 'Effective', 'Partially Effective' => 'Partially Effective', 'Ineffective' => 'Ineffective', 'Not Tested' => 'Not Tested'],
            'Partially Effective' => ['Effective' => 'Partially Effective', 'Partially Effective' => 'Partially Effective', 'Ineffective' => 'Ineffective', 'Not Tested' => 'Not Tested'],
            'Ineffective' => ['Effective' => 'Ineffective', 'Partially Effective' => 'Ineffective', 'Ineffective' => 'Ineffective', 'Not Tested' => 'Ineffective'],
            'Not Tested' => ['Effective' => 'Not Tested', 'Partially Effective' => 'Not Tested', 'Ineffective' => 'Ineffective', 'Not Tested' => 'Not Tested'],
        ];

        $cases = [];

        foreach ($expected as $design => $row) {
            foreach ($row as $operating => $overall) {
                $cases["design={$design} / operating={$operating}"] = [$design, $operating, $overall];
            }
        }

        return $cases;
    }

    #[DataProvider('matrixProvider')]
    public function test_tc_08_03_every_matrix_cell_resolves_as_configured(string $design, string $operating, string $overall): void
    {
        $this->assertSame(
            $overall,
            RatingMatrixEntry::resolve($design, $operating, $this->account('cfh')->tenant_id),
            "Matrix cell design={$design}, operating={$operating}."
        );
    }

    /** The plan's named case, asserted end to end through the service. */
    public function test_tc_08_03_design_effective_plus_operating_ineffective_is_never_overall_effective(): void
    {
        $rating = $this->service()->rate(
            $this->ratableInstance(),
            $this->ratingPayload('Effective', 'Ineffective'),
            $this->account('officer')
        );

        $this->assertNotSame(
            'Effective',
            $rating->overall_rating,
            'TC-08-03: a control that is well designed but did not operate cannot be rated Effective overall.'
        );

        $this->assertSame('Ineffective', $rating->overall_rating);
    }

    /**
     * FR-7.4 as a property of the whole matrix, not one cell: whatever the
     * matrix is configured to say, a design-ineffective control must never
     * come out better than Partially Effective.
     */
    public function test_fr_7_4_a_design_ineffective_control_is_never_better_than_partially_effective(): void
    {
        $violations = [];

        foreach (self::SCALE as $operating) {
            $overall = RatingMatrixEntry::resolve('Ineffective', $operating, $this->account('cfh')->tenant_id);

            if (self::RANK[$overall] > self::RANK['Partially Effective']) {
                $violations[] = "design=Ineffective / operating={$operating} → {$overall}";
            }
        }

        $this->assertSame([], $violations, "FR-7.4 violated:\n  ".implode("\n  ", $violations));
    }

    /** No cell may rate better than its own weakest dimension. */
    public function test_no_matrix_cell_rates_better_than_its_weakest_dimension(): void
    {
        $violations = [];

        foreach (self::SCALE as $design) {
            foreach (self::SCALE as $operating) {
                $overall = RatingMatrixEntry::resolve($design, $operating, $this->account('cfh')->tenant_id);
                $weakest = min(self::RANK[$design], self::RANK[$operating]);

                if (self::RANK[$overall] > $weakest) {
                    $violations[] = "design={$design} / operating={$operating} → {$overall}";
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "An overall rating exceeded the weaker of its two dimensions:\n  ".implode("\n  ", $violations)
        );
    }

    public function test_the_matrix_is_complete_no_combination_falls_through(): void
    {
        $missing = [];

        foreach (self::SCALE as $design) {
            foreach (self::SCALE as $operating) {
                $entry = RatingMatrixEntry::withoutGlobalScopes()
                    ->where('design_effectiveness', $design)
                    ->where('operating_effectiveness', $operating)
                    ->first();

                if (! $entry) {
                    $missing[] = "design={$design} / operating={$operating}";
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "The matrix has gaps; RatingMatrixEntry::resolve() silently returns 'Not Tested' for these:\n  "
            .implode("\n  ", $missing)
        );
    }

    // -----------------------------------------------------------------
    // TC-08-01 / TC-08-02 — the two dimensions are recorded separately
    // -----------------------------------------------------------------

    public function test_tc_08_01_and_02_design_and_operating_are_stored_as_separate_attributes(): void
    {
        $rating = $this->service()->rate(
            $this->ratableInstance(),
            $this->ratingPayload('Partially Effective', 'Effective'),
            $this->account('officer')
        );

        $this->assertSame('Partially Effective', $rating->design_effectiveness);
        $this->assertSame('Effective', $rating->operating_effectiveness);
        $this->assertSame('Partially Effective', $rating->overall_rating, 'Overall must derive from the matrix.');
        $this->assertNotNull($rating->design_rationale);
    }

    // -----------------------------------------------------------------
    // TC-08-04 — rationale
    // -----------------------------------------------------------------

    public static function nonEffectiveProvider(): array
    {
        return [
            'design not Effective' => ['Partially Effective', 'Effective', 'design_rationale'],
            'operating not Effective' => ['Effective', 'Ineffective', 'operating_rationale'],
        ];
    }

    #[DataProvider('nonEffectiveProvider')]
    public function test_tc_08_04_a_rating_other_than_effective_requires_a_rationale(string $design, string $operating, string $missingField): void
    {
        $payload = $this->ratingPayload($design, $operating);
        $payload[$missingField] = null;

        $this->expectException(ValidationException::class);

        $this->service()->rate($this->ratableInstance(), $payload, $this->account('officer'));
    }

    public function test_an_effective_rating_needs_no_rationale(): void
    {
        $rating = $this->service()->rate(
            $this->ratableInstance(),
            ['design_effectiveness' => 'Effective', 'operating_effectiveness' => 'Effective'],
            $this->account('officer')
        );

        $this->assertSame('Effective', $rating->overall_rating);
    }

    // -----------------------------------------------------------------
    // FR-7.6 — approval before publication, and SoD on it
    // -----------------------------------------------------------------

    public function test_fr_7_6_a_new_rating_is_not_published_until_approved(): void
    {
        $instance = $this->ratableInstance();

        // Start from a genuinely unrated instance, so this measures a new
        // rating rather than whatever the seed left behind.
        EffectivenessRating::withoutGlobalScopes()->where('test_instance_id', $instance->id)->forceDelete();

        $rating = $this->service()->rate($instance, $this->ratingPayload('Effective', 'Effective'), $this->account('officer'));

        $this->assertSame('Pending Approval', $rating->status, 'FR-7.6: a rating must not publish itself.');
        $this->assertNull($rating->approved_by);
        $this->assertNull($rating->approved_at);
    }

    /**
     * Re-rating correctly returns the record to Pending Approval, but the
     * approver of the *previous* values stays attached to it.
     */
    public function test_re_rating_clears_the_previous_approver(): void
    {
        $instance = $this->ratableInstance();
        EffectivenessRating::withoutGlobalScopes()->where('test_instance_id', $instance->id)->forceDelete();

        $rating = $this->service()->rate($instance, $this->ratingPayload('Effective', 'Effective'), $this->account('officer'));
        $this->service()->approveRating($rating, $this->account('cfh'));

        $this->service()->rate($instance->refresh(), $this->ratingPayload('Ineffective', 'Ineffective'), $this->account('officer'));

        $rating->refresh();

        $this->assertSame('Pending Approval', $rating->status);

        $this->assertNull(
            $rating->approved_by,
            'A re-rated record still names the approver of the superseded values. The row now reads '
            .'"Ineffective, Pending Approval, approved by the Control Function Head" — who approved '
            .'"Effective", not this. approved_at is likewise stale.'
        );
    }

    public function test_fr_7_6_a_rating_cannot_be_approved_by_the_person_who_proposed_it(): void
    {
        $officer = $this->account('officer');

        $rating = $this->service()->rate($this->ratableInstance(), $this->ratingPayload('Effective', 'Effective'), $officer);

        $this->expectException(ValidationException::class);

        $this->service()->approveRating($rating, $officer);
    }

    public function test_fr_7_6_approval_by_a_second_person_publishes_the_rating(): void
    {
        $rating = $this->service()->rate($this->ratableInstance(), $this->ratingPayload('Effective', 'Effective'), $this->account('officer'));

        $this->service()->approveRating($rating, $this->account('cfh'));

        $rating->refresh();

        $this->assertSame('Published', $rating->status);
        $this->assertSame($this->account('cfh')->id, $rating->approved_by);
        $this->assertNotNull($rating->approved_at);
    }

    // -----------------------------------------------------------------
    // FR-7.8 / TC-08-06 — the rating feeds residual risk
    // -----------------------------------------------------------------

    public function test_fr_7_8_approving_a_rating_recomputes_residual_risk(): void
    {
        $risk = Risk::withoutGlobalScopes()
            ->whereNotNull('inherent_rating')
            ->whereHas('controls', fn ($q) => $q->where('status', 'Active'))
            ->firstOrFail();

        $control = $risk->controls()->withoutGlobalScopes()->where('status', 'Active')->firstOrFail();

        $instance = TestInstance::withoutGlobalScopes()->where('control_id', $control->id)->first()
            ?? $this->ratableInstance();
        $instance->forceFill(['control_id' => $control->id, 'status' => 'Submitted'])->saveQuietly();

        $rating = $this->service()->rate($instance->refresh(), $this->ratingPayload('Effective', 'Effective'), $this->account('officer'));
        $this->service()->approveRating($rating, $this->account('cfh'));

        $risk->refresh();

        $this->assertNotNull($risk->residual_rating, 'FR-7.8: residual risk must be recomputed on approval.');
        $this->assertLessThanOrEqual((float) $risk->inherent_rating, (float) $risk->residual_rating, 'Residual must never exceed inherent.');
        $this->assertGreaterThanOrEqual((float) $risk->inherent_rating * 0.2, (float) $risk->residual_rating, 'FR-2.4: residual is floored at 20% of inherent.');
    }

    /**
     * TC-05-03 / FR-2.4 — the plan requires every calculation to be
     * independently recomputed from the database and compared to what the
     * application stored. This reimplements the documented formula and
     * reconciles it against every risk that has a residual rating.
     *
     *   residual = max(inherent x (1 - meanReduction), inherent x 0.2)
     *   meanReduction = SUM(weight x min(0.5, reduction + compensation)) / SUM(weight)
     *   reduction: Effective 0.5, Partially Effective 0.25, otherwise 0
     *   compensation: +0.15 where the control has an open exception and an
     *                 approved, in-date compensating control
     */
    public function test_fr_2_4_residual_risk_reconciles_to_an_independent_recomputation(): void
    {
        $reduction = ['Effective' => 0.5, 'Partially Effective' => 0.25, 'Ineffective' => 0.0, 'Not Tested' => 0.0];
        $mismatches = [];

        $risks = Risk::withoutGlobalScopes()->whereNotNull('inherent_rating')->get();

        $this->assertGreaterThan(0, $risks->count(), 'No risks carry an inherent rating.');

        // Drive the engine over every risk first. Some demo seeders write
        // residual_rating literally (Phase16DemoSeeder:224 sets 8 on a risk
        // with no controls at all; Phase17DemoSeeder:524 sets 12) — states
        // the engine cannot produce. Recomputing first means this
        // reconciles the *calculation*, not the fixture.
        $service = app(ResidualRiskService::class);

        foreach ($risks as $risk) {
            $service->recompute($risk);
        }

        $risks = $risks->map(fn ($r) => $r->fresh());

        foreach ($risks as $risk) {
            $controls = $risk->controls()->withoutGlobalScopes()->where('status', 'Active')->get();

            if ($controls->isEmpty()) {
                $expected = (float) $risk->inherent_rating;
            } else {
                $totalWeight = 0.0;
                $weighted = 0.0;

                foreach ($controls as $control) {
                    $weight = (float) ($control->pivot->contribution_weight ?? 1);
                    $rated = $control->latestPublishedRating()?->overall_rating ?? 'Not Tested';

                    $hasOpenException = $control->exceptions()->withoutGlobalScopes()
                        ->whereIn('status', ['Open', 'Assigned', 'In Progress', 'Remediated'])->exists();

                    $compensated = $hasOpenException && CompensatingControl::withoutGlobalScopes()
                        ->where('primary_control_id', $control->id)
                        ->whereIn('status', ['Approved', 'Active'])
                        ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()))
                        ->exists();

                    $totalWeight += $weight;
                    $weighted += $weight * min(0.5, ($reduction[$rated] ?? 0.0) + ($compensated ? 0.15 : 0.0));
                }

                $mean = $totalWeight > 0 ? $weighted / $totalWeight : 0.0;
                $expected = round(max($risk->inherent_rating * (1 - $mean), $risk->inherent_rating * 0.2), 2);
            }

            if (abs($expected - (float) $risk->residual_rating) > 0.01) {
                $mismatches[] = sprintf(
                    '%s: inherent=%s stored residual=%s, recomputed=%s',
                    $risk->code ?? $risk->id, $risk->inherent_rating, $risk->residual_rating, $expected
                );
            }
        }

        $this->assertSame(
            [],
            $mismatches,
            "FR-2.4: stored residual risk does not reconcile to an independent recomputation:\n  "
            .implode("\n  ", $mismatches)
        );
    }

    // -----------------------------------------------------------------
    // TC-08-05 — history
    // -----------------------------------------------------------------

    public function test_tc_08_05_rating_history_is_retained_per_period(): void
    {
        $control = Control::withoutGlobalScopes()->where('status', 'Active')->firstOrFail();

        $periods = EffectivenessRating::withoutGlobalScopes()
            ->where('control_id', $control->id)
            ->pluck('period_label');

        $this->assertSame(
            $periods->count(),
            $periods->unique()->count(),
            'TC-08-05: a control must hold at most one rating per period, so the trend is well defined.'
        );
    }

    public function test_re_rating_the_same_test_instance_updates_rather_than_duplicates(): void
    {
        $instance = $this->ratableInstance();

        $first = $this->service()->rate($instance, $this->ratingPayload('Effective', 'Effective'), $this->account('officer'));
        $second = $this->service()->rate($instance->refresh(), $this->ratingPayload('Ineffective', 'Ineffective'), $this->account('officer'));

        $this->assertSame($first->id, $second->id, 'A re-rating must update the instance rating, not create a second one.');

        $this->assertSame(
            1,
            EffectivenessRating::withoutGlobalScopes()->where('test_instance_id', $instance->id)->count(),
            'Exactly one rating per test instance.'
        );
    }

    /**
     * A published rating carries an approval; re-rating after publication
     * must not silently leave it published with new values.
     */
    public function test_re_rating_after_publication_returns_the_rating_to_pending_approval(): void
    {
        $instance = $this->ratableInstance();

        $rating = $this->service()->rate($instance, $this->ratingPayload('Effective', 'Effective'), $this->account('officer'));
        $this->service()->approveRating($rating, $this->account('cfh'));

        $this->assertSame('Published', $rating->fresh()->status);

        $this->service()->rate($instance->refresh(), $this->ratingPayload('Ineffective', 'Ineffective'), $this->account('officer'));

        $rating->refresh();

        $this->assertSame('Ineffective', $rating->overall_rating, 'The new values must be stored.');

        $this->assertSame(
            'Pending Approval',
            $rating->status,
            'FR-7.6: a published rating that is changed must return for approval. Otherwise a rating '
            .'approved as Effective can be silently rewritten to Ineffective while still reading as '
            .'approved, and the recorded approver did not approve what is now stored.'
        );
    }
}
