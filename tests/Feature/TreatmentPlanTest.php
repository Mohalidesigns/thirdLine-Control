<?php

namespace Tests\Feature;

use App\Models\Risk;
use App\Models\RiskTreatment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TreatmentService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RiskTaxonomySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TreatmentPlanTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->seed(RiskTaxonomySeeder::class);

        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->owner = $this->makeUser('owner@test.local', 'Control Owner');

        $this->actingAs($this->officer);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makeRisk(): Risk
    {
        return Risk::create([
            'tenant_id' => $this->tenant->id,
            'code' => Risk::nextReference('RSK', false, 'code'),
            'title' => 'Unauthorised production change',
            'inherent_likelihood' => 3,
            'inherent_impact' => 5,
            'inherent_rating' => 15,
            'residual_rating' => 15,
            'current_rating_band' => 'High',
            'status' => 'active',
        ]);
    }

    private function propose(array $overrides = [], array $milestones = []): RiskTreatment
    {
        return app(TreatmentService::class)->propose($this->makeRisk(), [
            'strategy' => 'Reduce',
            'title' => 'Enforce change advisory board approval',
            'owner_id' => $this->owner->id,
            'due_at' => now()->addDays(30)->toDateString(),
            'milestones' => $milestones,
            ...$overrides,
        ], $this->officer);
    }

    // ── The rule the phase brief names ───────────────────────────────

    public function test_the_treatment_verifier_cannot_be_the_treatment_owner(): void
    {
        $treatment = $this->propose(['owner_id' => $this->cfh->id]);
        $service = app(TreatmentService::class);

        $service->approve($treatment->fresh(), $this->officer);
        $service->start($treatment->fresh());
        $service->markImplemented($treatment->fresh());

        $this->assertFalse(
            $this->cfh->can('verify', $treatment->fresh()),
            'The owner must never be able to verify their own plan.',
        );

        $this->expectException(ValidationException::class);
        $service->verify($treatment->fresh(), $this->cfh);
    }

    public function test_a_system_administrator_who_owns_the_plan_still_cannot_verify_it(): void
    {
        $admin = $this->makeUser('admin@test.local', 'System Administrator');
        $treatment = $this->propose(['owner_id' => $admin->id]);
        $service = app(TreatmentService::class);

        $service->approve($treatment->fresh(), $this->cfh);
        $service->start($treatment->fresh());
        $service->markImplemented($treatment->fresh());

        $this->assertFalse($admin->can('verify', $treatment->fresh()), 'There is no administrator bypass (R2).');

        $this->expectException(ValidationException::class);
        $service->verify($treatment->fresh(), $admin);
    }

    public function test_an_independent_verifier_completes_the_plan(): void
    {
        $treatment = $this->propose();
        $service = app(TreatmentService::class);

        $service->approve($treatment->fresh(), $this->cfh);
        $service->start($treatment->fresh());
        $service->markImplemented($treatment->fresh());
        $service->verify($treatment->fresh(), $this->cfh, 'Change gate observed in ITSM.');

        $treatment->refresh();
        $this->assertSame('Verified', $treatment->status);
        $this->assertSame($this->cfh->id, $treatment->verified_by);
        $this->assertSame(100, $treatment->progress);
    }

    public function test_the_plan_owner_cannot_approve_their_own_plan(): void
    {
        $treatment = $this->propose(['owner_id' => $this->cfh->id]);

        $this->assertFalse($this->cfh->can('approve', $treatment->fresh()));

        $this->expectException(ValidationException::class);
        app(TreatmentService::class)->approve($treatment->fresh(), $this->cfh);
    }

    // ── Risk acceptance ──────────────────────────────────────────────

    public function test_risk_acceptance_requires_the_control_function_head(): void
    {
        $treatment = $this->propose(['strategy' => 'Accept']);

        $this->assertFalse(
            $this->officer->can('approve', $treatment->fresh()),
            'A Control Officer cannot accept a risk.',
        );

        $this->expectException(ValidationException::class);
        app(TreatmentService::class)->approve($treatment->fresh(), $this->officer, [
            'acceptance_expiry' => now()->addMonths(6)->toDateString(),
        ]);
    }

    public function test_risk_acceptance_must_carry_an_expiry_date(): void
    {
        $treatment = $this->propose(['strategy' => 'Accept']);

        $this->expectException(ValidationException::class);
        app(TreatmentService::class)->approve($treatment->fresh(), $this->cfh, ['acceptance_reason' => 'Upgrade pending']);
    }

    public function test_an_accepted_risk_is_reopened_when_the_acceptance_expires(): void
    {
        $treatment = $this->propose(['strategy' => 'Accept']);
        $service = app(TreatmentService::class);

        $service->approve($treatment->fresh(), $this->cfh, [
            'acceptance_reason' => 'Core banking upgrade scheduled',
            'acceptance_expiry' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertSame('Approved', $treatment->fresh()->status);

        // Backdate the expiry and run the daily sweep.
        $treatment->fresh()->updateQuietly(['acceptance_expiry' => now()->subDay()->toDateString()]);
        $service->refreshOverdue($this->tenant->id);

        $this->assertSame('Overdue', $treatment->fresh()->status);
    }

    // ── Milestones and progress ──────────────────────────────────────

    public function test_milestones_are_created_with_the_plan_and_drive_progress(): void
    {
        $treatment = $this->propose([], [
            ['title' => 'Draft policy', 'due_at' => now()->addDays(7)->toDateString()],
            ['title' => 'Configure gate', 'due_at' => now()->addDays(14)->toDateString()],
        ]);

        $this->assertCount(2, $treatment->milestones);

        $service = app(TreatmentService::class);
        $service->completeMilestone($treatment->milestones->first());

        $this->assertSame(50, $treatment->fresh()->progress);
    }

    public function test_a_plan_cannot_be_marked_implemented_with_an_open_milestone(): void
    {
        $treatment = $this->propose([], [['title' => 'Draft policy']]);
        $service = app(TreatmentService::class);

        $service->approve($treatment->fresh(), $this->cfh);
        $service->start($treatment->fresh());

        $this->expectException(ValidationException::class);
        $service->markImplemented($treatment->fresh());
    }

    public function test_the_lifecycle_cannot_be_skipped(): void
    {
        $treatment = $this->propose();

        $this->expectException(ValidationException::class);
        app(TreatmentService::class)->verify($treatment, $this->cfh);
    }

    // ── Overdue alerting ─────────────────────────────────────────────

    public function test_the_daily_sweep_flags_overdue_plans_and_milestones(): void
    {
        $treatment = $this->propose([], [['title' => 'Draft policy', 'due_at' => now()->subDays(3)->toDateString()]]);
        $service = app(TreatmentService::class);

        $service->approve($treatment->fresh(), $this->cfh);
        $treatment->fresh()->updateQuietly(['due_at' => now()->subDay()->toDateString()]);

        $this->artisan('atheris:refresh-risk-posture')->assertSuccessful();

        $this->assertSame('Overdue', $treatment->fresh()->status);
        $this->assertSame('Overdue', $treatment->fresh()->milestones->first()->status);
    }

    public function test_the_treatment_pages_render(): void
    {
        $treatment = $this->propose();

        $this->actingAs($this->cfh)
            ->get(route('treatments.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Treatments/Index')->has('treatments.data', 1));

        $this->actingAs($this->cfh)
            ->get(route('treatments.show', $treatment))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Treatments/Show'));
    }
}
