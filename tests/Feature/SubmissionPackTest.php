<?php

namespace Tests\Feature;

use App\Models\BusinessProcess;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\Framework;
use App\Models\FrameworkRequirement;
use App\Models\ObligationAssignment;
use App\Models\ObligationInstance;
use App\Models\OrganisationUnit;
use App\Models\Regulator;
use App\Models\RegulatoryObligation;
use App\Models\SubmissionPack;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubmissionPackService;
use App\Submissions\DeficiencyAggregator;
use App\Submissions\SubmissionPackRegistry;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SubmissionPackTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    private User $secondOfficer;

    private User $owner;

    private OrganisationUnit $entity;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active', 'data_residency' => 'NG']);

        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->secondOfficer = $this->makeUser('officer2@test.local', 'Control Officer');
        $this->owner = $this->makeUser('owner@test.local', 'Control Owner');

        $this->actingAs($this->officer);

        $this->entity = OrganisationUnit::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Demo Bank Plc',
            'type' => 'Head Office',
            'code' => 'HO',
            'is_legal_entity' => true,
            'entity_type' => 'commercial_bank',
            'rc_number' => 'RC123456',
            'incorporated_on' => '2004-06-01',
            'fiscal_year_end_month' => 12,
            'fiscal_year_end_day' => 31,
        ]);

        Regulator::firstOrCreate(['code' => 'FRC-NG'], [
            'name' => 'Financial Reporting Council of Nigeria', 'jurisdiction' => 'NG', 'is_active' => true,
        ]);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    /** A framework with N principles, at the given verification status. */
    private function makeFramework(string $code, int $principles, string $verification = 'verified'): Framework
    {
        $framework = Framework::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'code' => $code,
            'name' => $code.' framework',
            'jurisdiction' => 'NG',
            'is_active' => true,
            'verification_status' => $verification,
        ]);

        foreach (range(1, $principles) as $index) {
            FrameworkRequirement::create([
                'framework_id' => $framework->id,
                'ref_code' => 'P'.$index,
                'title' => 'Principle '.$index,
                'level' => 1,
                'sort_order' => $index,
                'requirement_type' => 'principle',
                'is_testable' => true,
                'verification_status' => $verification,
            ]);
        }

        return $framework;
    }

    private function service(): SubmissionPackService
    {
        return app(SubmissionPackService::class);
    }

    // ── FRC/CG/001 — the apply-and-explain rules ─────────────────────────

    public function test_the_governance_return_needs_its_content_pack(): void
    {
        $pack = $this->service()->generate('FRC/CG/001', $this->officer);

        $this->assertSame('Draft', $pack->status);
        $this->assertSame('framework_missing', $pack->validation_errors[0]['code']);
    }

    public function test_unverified_principles_are_excluded_from_the_return_and_listed(): void
    {
        $this->makeFramework('FRC-NCCG-2018', 28, 'unverified');

        $pack = $this->service()->generate('FRC/CG/001', $this->officer);

        $this->assertCount(28, $pack->unverified_items);
        $this->assertSame('P1', $pack->unverified_items[0]['ref']);
        $this->assertStringContainsString('not been verified', $pack->unverified_items[0]['reason']);

        // Excluded means excluded: Section E carries no principle fields.
        $sectionE = collect($pack->content['sections'])->firstWhere('key', 'E');
        $this->assertSame([], $sectionE['fields']);

        $this->assertContains(
            'principles_unverified',
            array_column($pack->validation_errors, 'code'),
        );
    }

    public function test_generation_blocks_while_a_principle_is_unanswered(): void
    {
        $this->makeFramework('FRC-NCCG-2018', 3);

        $pack = $this->service()->generate('FRC/CG/001', $this->officer);

        $codes = array_column($pack->validation_errors, 'code');

        $this->assertContains('principle_unanswered', $codes);
        $this->assertTrue(
            collect($pack->validation_errors)->firstWhere('code', 'principle_unanswered')['blocking'],
        );
    }

    /**
     * The rule the Code is explicit about: a principle is applied or it is
     * not, and "N/A" is not an answer. It has to be refused as hard as a
     * blank, or the return becomes 28 shrugs.
     */
    public function test_answering_a_principle_not_applicable_blocks_the_return(): void
    {
        $this->makeFramework('FRC-NCCG-2018', 2);

        $pack = $this->service()->generate('FRC/CG/001', $this->officer);

        $pack = $this->service()->update($pack, $this->officer, [
            'e_p1_applied' => 'Yes',
            'e_p1_explanation' => 'Applied in full through the board charter.',
            'e_p2_applied' => 'N/A',
            'e_p2_explanation' => 'Not relevant to us.',
        ]);

        $codes = array_column($pack->validation_errors, 'code');

        $this->assertContains('principle_not_applicable', $codes);
        $this->assertNotContains('principle_unanswered', $codes);
    }

    public function test_a_fully_answered_principle_set_clears_the_principle_errors(): void
    {
        $this->makeFramework('FRC-NCCG-2018', 2);

        $pack = $this->service()->generate('FRC/CG/001', $this->officer);

        $pack = $this->service()->update($pack, $this->officer, [
            'e_p1_applied' => 'Yes',
            'e_p1_explanation' => 'Applied in full.',
            'e_p2_applied' => 'No',
            'e_p2_explanation' => 'Deviated because the committee was constituted mid-year.',
        ]);

        $codes = array_column($pack->validation_errors, 'code');

        $this->assertNotContains('principle_unanswered', $codes);
        $this->assertNotContains('principle_not_applicable', $codes);
    }

    public function test_the_governance_return_carries_four_signatories(): void
    {
        $this->makeFramework('FRC-NCCG-2018', 1);

        $pack = $this->service()->generate('FRC/CG/001', $this->officer);

        $roles = array_column($pack->content['signatories'], 'role');

        $this->assertCount(4, $roles);
        $this->assertContains('Chairman of the Board', $roles);
        $this->assertContains('Company Secretary', $roles);
    }

    // ── NDPC Compliance Audit Return ─────────────────────────────────────

    public function test_the_ndpc_return_carries_every_register_the_article_asks_for(): void
    {
        $this->makeFramework('NDPA-GAID-2025', 6);

        $pack = $this->service()->generate('NDPC/CAR', $this->officer);

        $keys = array_column($pack->content['sections'], 'key');

        foreach (['TIER', 'DPO', 'ROPA', 'DPIA', 'BRCH', 'XBORDER', 'PROC', 'TRAIN', 'FEE'] as $section) {
            $this->assertContains($section, $keys, "The NDPC return is missing its {$section} section.");
        }
    }

    public function test_an_ohl_entity_is_told_it_does_not_file_at_all(): void
    {
        $this->makeFramework('NDPA-GAID-2025', 6);

        $pack = $this->service()->generate('NDPC/CAR', $this->officer);
        $pack = $this->service()->update($pack, $this->officer, ['tier' => 'OHL']);

        $this->assertContains('tier_not_filing', array_column($pack->validation_errors, 'code'));
    }

    public function test_a_uhl_entity_without_a_licensed_dpco_cannot_file(): void
    {
        $this->makeFramework('NDPA-GAID-2025', 6);

        $pack = $this->service()->generate('NDPC/CAR', $this->officer);
        $pack = $this->service()->update($pack, $this->officer, ['tier' => 'UHL']);

        $this->assertContains('dpco_missing', array_column($pack->validation_errors, 'code'));

        $pack = $this->service()->update($pack, $this->officer, [
            'tier' => 'UHL',
            'dpco_name' => 'Verified DPCO Limited',
        ]);

        $this->assertNotContains('dpco_missing', array_column($pack->validation_errors, 'code'));
    }

    /**
     * The fee band and the late-filing rate are the regulator's numbers. With
     * an unverified obligation the exposure is left to the officer and named
     * as excluded; only a verified rate is applied as arithmetic (R10).
     */
    public function test_the_late_filing_exposure_is_computed_only_from_a_verified_penalty_rate(): void
    {
        $this->makeFramework('NDPA-GAID-2025', 6);
        $regulator = Regulator::firstOrCreate(['code' => 'NDPC'], [
            'name' => 'Nigeria Data Protection Commission', 'jurisdiction' => 'NG', 'is_active' => true,
        ]);

        $obligation = RegulatoryObligation::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'regulator_id' => $regulator->id,
            'obligation_ref' => 'NDPA-CAR-ANNUAL',
            'title' => 'Compliance Audit Return',
            'obligation_type' => 'filing',
            'trigger_type' => 'calendar',
            'frequency' => 'annual',
            'penalty_amount_minor' => 5000, // 50% in basis points
            'penalty_currency' => 'NGN',
            'penalty_basis' => 'percentage',
            'due_rule' => ['type' => 'fixed_date', 'month' => 3, 'day' => 31],
            'verification_status' => 'unverified',
            'is_active' => true,
        ]);

        $pack = $this->service()->generate('NDPC/CAR', $this->officer);

        $this->assertContains(
            'NDPA-CAR-ANNUAL',
            array_column($pack->unverified_items, 'ref'),
            'An unverified penalty rate must be named as excluded.',
        );

        // Verified: the same fee now produces a computed exposure.
        $obligation->forceFill(['verification_status' => 'verified'])->saveQuietly();

        $pack = $this->service()->generate('NDPC/CAR', $this->officer, [
            'existing' => ['sections' => [[
                'fields' => [[
                    'key' => 'fee_amount_minor', 'value' => 100_000_00, 'source' => 'input',
                ]],
            ]]],
        ]);

        $fee = collect(collect($pack->content['sections'])->firstWhere('key', 'FEE')['fields'])
            ->firstWhere('key', 'late_penalty_exposure');

        // 50% of ₦100,000.00 is ₦50,000.00.
        $this->assertSame('₦50,000.00', $fee['value']);
        $this->assertSame('system', $fee['source']);
    }

    // ── ICFR deficiency aggregation ──────────────────────────────────────

    public function test_a_single_finding_is_classified_by_severity_and_key_control_status(): void
    {
        $aggregator = new DeficiencyAggregator(2);

        $this->assertSame(DeficiencyAggregator::MATERIAL_WEAKNESS, $aggregator->classify('Critical'));
        $this->assertSame(DeficiencyAggregator::SIGNIFICANT_DEFICIENCY, $aggregator->classify('High'));
        $this->assertSame(DeficiencyAggregator::CONTROL_DEFICIENCY, $aggregator->classify('Medium'));
        $this->assertSame(DeficiencyAggregator::SIGNIFICANT_DEFICIENCY, $aggregator->classify('Medium', true));
        $this->assertSame(DeficiencyAggregator::CONTROL_DEFICIENCY, $aggregator->classify('Low', true));
    }

    public function test_several_significant_deficiencies_in_one_area_aggregate_to_a_material_weakness(): void
    {
        $aggregator = new DeficiencyAggregator(2);

        $result = $aggregator->aggregate([
            ['area' => 'Revenue', 'tier' => DeficiencyAggregator::SIGNIFICANT_DEFICIENCY],
            ['area' => 'Revenue', 'tier' => DeficiencyAggregator::SIGNIFICANT_DEFICIENCY],
            ['area' => 'Payroll', 'tier' => DeficiencyAggregator::SIGNIFICANT_DEFICIENCY],
            ['area' => 'Payroll', 'tier' => DeficiencyAggregator::CONTROL_DEFICIENCY],
        ]);

        $this->assertSame(2, $result['counts'][DeficiencyAggregator::MATERIAL_WEAKNESS]);
        $this->assertSame(1, $result['counts'][DeficiencyAggregator::SIGNIFICANT_DEFICIENCY]);
        $this->assertCount(1, $result['escalations']);
        $this->assertSame('Revenue', $result['escalations'][0]['area']);

        // The escalation is a prompt for a human, never a conclusion.
        $this->assertTrue($result['escalations'][0]['requires_human_review']);
    }

    public function test_deficiencies_below_the_threshold_do_not_escalate(): void
    {
        $result = (new DeficiencyAggregator(3))->aggregate([
            ['area' => 'Revenue', 'tier' => DeficiencyAggregator::SIGNIFICANT_DEFICIENCY],
            ['area' => 'Revenue', 'tier' => DeficiencyAggregator::SIGNIFICANT_DEFICIENCY],
        ]);

        $this->assertSame(0, $result['counts'][DeficiencyAggregator::MATERIAL_WEAKNESS]);
        $this->assertSame([], $result['escalations']);
    }

    public function test_the_icfr_report_escalates_from_live_control_failures(): void
    {
        $this->makeFramework('FRC-ICFR-2024', 4);

        $process = BusinessProcess::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Revenue recognition', 'code' => 'REV',
        ]);

        $control = Control::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'control_ref' => 'CTL-REV-1', 'title' => 'Revenue cut-off review',
            'type' => 'Detective', 'nature' => 'Manual', 'frequency' => 'Monthly', 'is_key_control' => true,
            'status' => 'Active', 'process_id' => $process->id, 'operating_effectiveness' => 'Effective',
        ]);

        foreach (['High', 'High'] as $index => $severity) {
            ControlException::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id,
                'reference' => 'EXC-REV-'.$index,
                'source_type' => 'Test',
                'control_id' => $control->id,
                'title' => 'Cut-off error '.$index,
                'severity' => $severity,
                'status' => 'Open',
                'date_raised' => now()->subDays(5),
            ]);
        }

        $pack = $this->service()->generate('FRC/ICFR', $this->officer);

        $this->assertSame(2, $pack->content['meta']['deficiency_counts'][DeficiencyAggregator::MATERIAL_WEAKNESS] ?? null);
        $this->assertCount(1, $pack->content['meta']['escalations']);
        $this->assertSame('Revenue recognition', $pack->content['meta']['escalations'][0]['area']);

        // With a material weakness on the register, concluding "Effective" is
        // a contradiction the pack refuses to file.
        $pack = $this->service()->update($pack, $this->officer, ['conclusion' => 'Effective']);

        $this->assertContains('conclusion_contradicts_evidence', array_column($pack->validation_errors, 'code'));
    }

    // ── Maker-checker ────────────────────────────────────────────────────

    public function test_the_generator_cannot_review_their_own_pack(): void
    {
        $pack = $this->readyPack();

        $this->service()->sendForReview($pack, $this->officer);
        $pack->refresh();

        $this->assertFalse($this->officer->can('review', $pack));

        $this->expectException(ValidationException::class);
        $this->service()->review($pack, $this->officer, 'accept');
    }

    public function test_the_reviewer_cannot_also_approve(): void
    {
        $pack = $this->readyPack();

        $this->service()->sendForReview($pack, $this->officer);
        $this->service()->review($pack->fresh(), $this->secondOfficer, 'accept');

        $pack->refresh();

        // The reviewer holds the approve permission through the CFH role only
        // if granted it; give it explicitly to prove the SoD gate, not the
        // permission gate, is what refuses them.
        $this->secondOfficer->givePermissionTo('approve submissions');
        $this->secondOfficer->refresh();

        $this->assertFalse($this->secondOfficer->can('approve', $pack));

        $this->expectException(ValidationException::class);
        $this->service()->approve($pack, $this->secondOfficer);
    }

    public function test_the_generator_cannot_approve_even_with_every_permission(): void
    {
        $pack = $this->readyPack();

        $this->service()->sendForReview($pack, $this->officer);
        $this->service()->review($pack->fresh(), $this->secondOfficer, 'accept');

        $pack->refresh();

        // No administrator bypass: the widest role in the platform still
        // cannot approve a pack it generated (R2).
        $this->officer->assignRole('System Administrator');
        $this->officer->refresh();

        $this->assertFalse($this->officer->can('approve', $pack));

        $this->expectException(ValidationException::class);
        $this->service()->approve($pack, $this->officer);
    }

    public function test_three_distinct_people_take_a_pack_all_the_way_to_filed(): void
    {
        $pack = $this->readyPack();

        $this->service()->sendForReview($pack, $this->officer);
        $this->service()->review($pack->fresh(), $this->secondOfficer, 'accept');
        $this->service()->approve($pack->fresh(), $this->cfh);

        $pack->refresh();

        $this->assertSame('Approved', $pack->status);
        $this->assertNotNull($pack->locked_at);
        $this->assertNotNull($pack->checksum);

        $this->service()->file($pack, $this->cfh, ['submission_reference' => 'FRC/CG/2026/0042']);

        $pack->refresh();

        $this->assertSame('Submitted', $pack->status);
        $this->assertSame('FRC/CG/2026/0042', $pack->submission_reference);
        $this->assertNotSame($pack->generated_by, $pack->reviewed_by);
        $this->assertNotSame($pack->generated_by, $pack->approved_by);
        $this->assertNotSame($pack->reviewed_by, $pack->approved_by);
    }

    public function test_approval_requires_a_review_first(): void
    {
        $pack = $this->readyPack();
        $this->service()->sendForReview($pack, $this->officer);

        $this->expectException(ValidationException::class);
        $this->service()->approve($pack->fresh(), $this->cfh);
    }

    public function test_an_approved_pack_is_immutable(): void
    {
        $pack = $this->readyPack();

        $this->service()->sendForReview($pack, $this->officer);
        $this->service()->review($pack->fresh(), $this->secondOfficer, 'accept');
        $this->service()->approve($pack->fresh(), $this->cfh);

        $this->expectException(ValidationException::class);
        $this->service()->update($pack->fresh(), $this->officer, ['a_statement' => 'Changed after approval']);
    }

    public function test_a_rejected_pack_is_replaced_by_a_new_version_rather_than_edited(): void
    {
        $pack = $this->readyPack();

        $this->service()->sendForReview($pack, $this->officer);
        $this->service()->review($pack->fresh(), $this->secondOfficer, 'accept');
        $this->service()->approve($pack->fresh(), $this->cfh);
        $this->service()->file($pack->fresh(), $this->cfh, []);
        $this->service()->reject($pack->fresh(), $this->cfh, 'Section C attendance figures do not reconcile.');

        $replacement = $this->service()->resubmit($pack->fresh(), $this->officer);

        $this->assertSame('Resubmitted', $pack->fresh()->status);
        $this->assertSame(2, $replacement->version_no);
        $this->assertSame($pack->id, $replacement->supersedes_id);
        $this->assertSame('Draft', $replacement->status);
    }

    // ── Completeness floor ───────────────────────────────────────────────

    public function test_a_pack_below_the_completeness_floor_cannot_be_filed(): void
    {
        config(['dashboards.submission_completeness_threshold' => 95]);

        $this->makeFramework('FRC-NCCG-2018', 2);

        $pack = $this->service()->generate('FRC/CG/001', $this->officer);
        $this->service()->sendForReview($pack, $this->officer);
        $this->service()->review($pack->fresh(), $this->secondOfficer, 'accept');
        $this->service()->approve($pack->fresh(), $this->cfh);

        $pack->refresh();

        $this->assertLessThan(95, $pack->completeness_score);

        $this->expectException(ValidationException::class);
        $this->service()->file($pack, $this->cfh, []);
    }

    public function test_a_blocking_validation_error_stops_filing_even_at_full_completeness(): void
    {
        $pack = $this->readyPack();

        $pack->forceFill([
            'completeness_score' => 100,
            'validation_errors' => [['code' => 'x', 'message' => 'Something is wrong.', 'blocking' => true]],
            'status' => 'Approved',
            'approved_at' => now(),
            'approved_by' => $this->cfh->id,
            'reviewed_at' => now(),
            'reviewed_by' => $this->secondOfficer->id,
        ])->save();

        $this->expectException(ValidationException::class);
        $this->service()->file($pack->fresh(), $this->cfh, []);
    }

    // ── Downstream effects ───────────────────────────────────────────────

    public function test_filing_a_pack_discharges_the_obligation_instance_behind_it(): void
    {
        $regulator = Regulator::firstOrCreate(['code' => 'FRC-NG'], [
            'name' => 'FRC', 'jurisdiction' => 'NG', 'is_active' => true,
        ]);

        $obligation = RegulatoryObligation::withoutGlobalScopes()->create([
            'tenant_id' => null, 'regulator_id' => $regulator->id,
            'obligation_ref' => 'FRC-CG-001-RETURN', 'title' => 'Corporate governance return',
            'obligation_type' => 'filing', 'trigger_type' => 'calendar', 'frequency' => 'annual',
            'due_rule' => ['type' => 'relative', 'anchor' => 'fiscal_year_end', 'offset_days' => 180],
            'verification_status' => 'unverified', 'is_active' => true,
        ]);

        $assignment = ObligationAssignment::create([
            'tenant_id' => $this->tenant->id,
            'obligation_id' => $obligation->id,
            'entity_id' => $this->entity->id,
            'owner_id' => $this->officer->id,
            'is_applicable' => true,
        ]);

        $instance = ObligationInstance::create([
            'tenant_id' => $this->tenant->id,
            'obligation_id' => $obligation->id,
            'assignment_id' => $assignment->id,
            'period_label' => 'FY 2025',
            'due_at' => now()->addDays(30),
            'status' => 'In Progress',
        ]);

        $pack = $this->readyPack(['obligation_instance_id' => $instance->id]);

        $this->service()->sendForReview($pack, $this->officer);
        $this->service()->review($pack->fresh(), $this->secondOfficer, 'accept');
        $this->service()->approve($pack->fresh(), $this->cfh);
        $this->service()->file($pack->fresh(), $this->cfh, ['submission_reference' => 'FRC/2026/1']);

        $instance->refresh();

        $this->assertSame('Submitted', $instance->status);
        $this->assertSame('FRC/2026/1', $instance->submission_reference);
    }

    public function test_the_pack_renders_as_a_document_with_an_unverified_appendix(): void
    {
        $this->makeFramework('FRC-NCCG-2018', 3, 'unverified');

        $pack = $this->service()->generate('FRC/CG/001', $this->officer);
        $run = $this->service()->render($pack, $this->officer, 'html');

        $this->assertSame('Completed', $run->status);

        $html = Storage::disk('local')->get($run->output_path);

        $this->assertStringContainsString('Appendix — content excluded as unverified', $html);
        $this->assertStringContainsString('P1', $html);
    }

    public function test_the_catalogue_reports_which_content_packs_are_installed(): void
    {
        $catalogue = app(SubmissionPackRegistry::class)->catalogue();

        $this->assertCount(6, $catalogue);
        $this->assertSame([false, false, false, false, false, false], array_column($catalogue, 'installed'));

        $this->makeFramework('FRC-NCCG-2018', 1);

        $installed = collect(app(SubmissionPackRegistry::class)->catalogue())
            ->firstWhere('pack_type', 'FRC/CG/001');

        $this->assertTrue($installed['installed']);
    }

    public function test_an_unknown_pack_type_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->generate('MADE/UP', $this->officer);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    /** A governance pack answered well enough to travel the whole chain. */
    private function readyPack(array $options = []): SubmissionPack
    {
        config(['dashboards.submission_completeness_threshold' => 0]);

        $this->makeFramework('FRC-NCCG-2018', 1);

        $pack = $this->service()->generate('FRC/CG/001', $this->officer, $options);

        return $this->service()->update($pack, $this->officer, [
            'a_statement' => 'The board applied the Code throughout the period.',
            'b_registered_address' => '1 Marina, Lagos',
            'e_p1_applied' => 'Yes',
            'e_p1_explanation' => 'Applied in full through the board charter.',
        ]);
    }
}
