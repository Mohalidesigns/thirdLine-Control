<?php

namespace Tests\Feature;

use App\Models\EscalationEvent;
use App\Models\Risk;
use App\Models\RiskAppetite;
use App\Models\RiskCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AppetiteService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RiskTaxonomySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RiskAppetiteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $exec;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->seed(RiskTaxonomySeeder::class);

        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->exec = $this->makeUser('exec@test.local', 'Executive Viewer');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');

        $this->actingAs($this->officer);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function category(string $code): RiskCategory
    {
        return RiskCategory::withoutGlobalScopes()->where('code', $code)->firstOrFail();
    }

    private function makeAppetite(string $categoryCode = 'OPS', array $overrides = []): RiskAppetite
    {
        return app(AppetiteService::class)->create([
            'tenant_id' => $this->tenant->id,
            'risk_category_id' => $this->category($categoryCode)->id,
            'statement' => 'We accept a low level of operational risk.',
            'appetite_level' => 'Cautious',
            'tolerance_lower' => 4,
            'tolerance_upper' => 12,
            'capacity' => 18,
            ...$overrides,
        ], $this->officer);
    }

    private function makeRisk(int $score, string $categoryCode = 'OPS-FRD'): Risk
    {
        return Risk::create([
            'tenant_id' => $this->tenant->id,
            'code' => Risk::nextReference('RSK', false, 'code'),
            'title' => 'Unauthorised payment release',
            'risk_category_id' => $this->category($categoryCode)->id,
            'inherent_likelihood' => 4,
            'inherent_impact' => 5,
            'inherent_rating' => 20,
            'residual_rating' => $score,
            'current_rating_band' => $score > 18 ? 'Critical' : ($score > 12 ? 'High' : 'Moderate'),
            'risk_owner_id' => $this->cfh->id,
            'status' => 'active',
        ]);
    }

    // ── Maker-checker on the statement (R2) ──────────────────────────

    public function test_the_author_cannot_approve_their_own_statement(): void
    {
        $appetite = $this->makeAppetite();
        $service = app(AppetiteService::class);

        $service->submitForApproval($appetite);

        $this->assertFalse($this->officer->can('approve', $appetite->fresh()));

        $this->expectException(ValidationException::class);
        $service->approve($appetite->fresh(), $this->officer);
    }

    public function test_a_system_administrator_who_authored_it_still_cannot_approve_it(): void
    {
        $admin = $this->makeUser('admin@test.local', 'System Administrator');

        $appetite = app(AppetiteService::class)->create([
            'tenant_id' => $this->tenant->id,
            'risk_category_id' => $this->category('OPS')->id,
            'statement' => 'Board statement',
            'appetite_level' => 'Cautious',
            'tolerance_upper' => 12,
        ], $admin);

        app(AppetiteService::class)->submitForApproval($appetite);

        $this->assertFalse($admin->can('approve', $appetite->fresh()), 'There is no administrator bypass on approval.');

        $this->expectException(ValidationException::class);
        app(AppetiteService::class)->approve($appetite->fresh(), $admin);
    }

    public function test_the_board_tier_approves_and_the_statement_goes_active(): void
    {
        $appetite = $this->makeAppetite();
        $service = app(AppetiteService::class);

        $service->submitForApproval($appetite);
        $service->approve($appetite->fresh(), $this->exec);

        $appetite->refresh();
        $this->assertSame('Active', $appetite->status);
        $this->assertSame($this->exec->id, $appetite->approved_by);
        $this->assertNotNull($appetite->effective_from);
    }

    // ── Versioning ───────────────────────────────────────────────────

    public function test_changing_a_statement_supersedes_rather_than_edits(): void
    {
        $service = app(AppetiteService::class);
        $original = $this->makeAppetite();

        $service->submitForApproval($original);
        $service->approve($original->fresh(), $this->exec);

        $next = $service->supersede($original->fresh(), ['tolerance_upper' => 8], $this->officer);

        $this->assertSame(2, $next->version_no);
        $this->assertSame($original->id, $next->supersedes_id);
        $this->assertSame('Draft', $next->status);
        $this->assertSame('Active', $original->fresh()->status, 'The approved statement stays intact until the new one is approved.');

        $service->submitForApproval($next);
        $service->approve($next->fresh(), $this->exec);

        $this->assertSame('Superseded', $original->fresh()->status);
        $this->assertNotNull($original->fresh()->effective_to);
        $this->assertSame(8.0, (float) $next->fresh()->tolerance_upper);
    }

    public function test_a_draft_statement_cannot_be_superseded(): void
    {
        $this->expectException(ValidationException::class);

        app(AppetiteService::class)->supersede($this->makeAppetite(), [], $this->officer);
    }

    // ── Applicability and breach ─────────────────────────────────────

    public function test_a_child_category_inherits_its_parent_statement(): void
    {
        $service = app(AppetiteService::class);
        $appetite = $this->makeAppetite('OPS');
        $service->submitForApproval($appetite);
        $service->approve($appetite->fresh(), $this->exec);

        // The risk is filed under Fraud, a child of Operational.
        $risk = $this->makeRisk(10, 'OPS-FRD');

        $this->assertSame($appetite->id, $service->applicableFor($risk)?->id);
    }

    public function test_an_appetite_breach_flags_the_risk_and_fires_the_escalation(): void
    {
        $service = app(AppetiteService::class);
        $appetite = $this->makeAppetite('OPS');
        $service->submitForApproval($appetite);
        $service->approve($appetite->fresh(), $this->exec);

        $risk = $this->makeRisk(16, 'OPS-FRD');

        $this->assertTrue($service->evaluate($risk), 'A residual of 16 exceeds a tolerance of 12.');

        $risk->refresh();
        $this->assertTrue($risk->appetite_breached);
        $this->assertNotNull($risk->appetite_breach_at);

        $this->assertTrue(
            EscalationEvent::withoutGlobalScopes()->where('risk_id', $risk->id)->exists(),
            'An appetite breach must fire the configured appetite_breach escalation.',
        );
    }

    public function test_a_risk_inside_tolerance_is_not_flagged(): void
    {
        $service = app(AppetiteService::class);
        $appetite = $this->makeAppetite('OPS');
        $service->submitForApproval($appetite);
        $service->approve($appetite->fresh(), $this->exec);

        $risk = $this->makeRisk(9, 'OPS-FRD');

        $this->assertFalse($service->evaluate($risk));
        $this->assertFalse($risk->fresh()->appetite_breached);
        $this->assertSame(0, EscalationEvent::withoutGlobalScopes()->whereNotNull('risk_id')->count());
    }

    public function test_a_risk_coming_back_inside_tolerance_clears_the_flag(): void
    {
        $service = app(AppetiteService::class);
        $appetite = $this->makeAppetite('OPS');
        $service->submitForApproval($appetite);
        $service->approve($appetite->fresh(), $this->exec);

        $risk = $this->makeRisk(16, 'OPS-FRD');
        $service->evaluate($risk);
        $this->assertTrue($risk->fresh()->appetite_breached);

        $risk->update(['residual_rating' => 8]);
        $service->evaluate($risk->fresh());

        $this->assertFalse($risk->fresh()->appetite_breached);
        $this->assertNull($risk->fresh()->appetite_breach_at);
    }

    public function test_beyond_capacity_reads_differently_from_outside_tolerance(): void
    {
        $appetite = $this->makeAppetite();

        $this->assertSame('Within appetite', $appetite->positionFor(10));
        $this->assertSame('Below appetite', $appetite->positionFor(2));
        $this->assertSame('Outside tolerance', $appetite->positionFor(15));
        $this->assertSame('Beyond capacity', $appetite->positionFor(20));
    }

    public function test_the_board_view_reports_position_per_category(): void
    {
        $service = app(AppetiteService::class);
        $appetite = $this->makeAppetite('OPS');
        $service->submitForApproval($appetite);
        $service->approve($appetite->fresh(), $this->exec);

        $risk = $this->makeRisk(16, 'OPS-FRD');
        $service->evaluate($risk);

        $positions = $service->positions($this->tenant->id);

        $this->assertCount(1, $positions);
        $this->assertSame('Operational', $positions[0]['category']);
        $this->assertSame(16.0, (float) $positions[0]['current_position']);
        $this->assertSame('Outside tolerance', $positions[0]['status']);
        $this->assertSame(1, $positions[0]['breach_count']);
    }

    public function test_categories_without_a_statement_are_reported_as_a_gap(): void
    {
        $service = app(AppetiteService::class);
        $appetite = $this->makeAppetite('OPS');
        $service->submitForApproval($appetite);
        $service->approve($appetite->fresh(), $this->exec);

        $uncovered = collect($service->categoriesWithoutAppetite($this->tenant->id))->pluck('code');

        $this->assertFalse($uncovered->contains('OPS'));
        $this->assertTrue($uncovered->contains('CYB'));
    }

    public function test_the_appetite_page_renders(): void
    {
        $this->actingAs($this->cfh)
            ->get(route('appetite.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Risks/Appetite')->has('positions'));
    }
}
