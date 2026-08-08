<?php

namespace Tests\Feature;

use App\Models\CsaCampaign;
use App\Models\Policy;
use App\Models\PolicyException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PolicyService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\GovernanceTaxonomySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PolicyLifecycleTest extends TestCase
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
        $this->seed(GovernanceTaxonomySeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->owner = $this->makeUser('owner@test.local', 'Control Owner');

        $this->actingAs($this->officer);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function draft(array $overrides = []): Policy
    {
        return app(PolicyService::class)->create([
            'title' => 'Anti-Bribery and Corruption Policy',
            'description' => 'How we prevent, detect and respond to bribery.',
            'policy_type' => 'policy',
            'owner_id' => $this->officer->id,
            'review_frequency' => 'annual',
            'applies_to' => ['all_staff' => true],
            'sections' => [
                ['heading' => '1. Scope', 'body' => 'Everyone.', 'is_mandatory' => true],
                ['heading' => '2. Gifts', 'body' => 'Declare anything over ₦20,000.', 'is_mandatory' => true],
            ],
            ...$overrides,
        ], $this->officer);
    }

    // ── The rule the phase brief names ───────────────────────────────

    public function test_a_policy_cannot_be_approved_by_its_own_owner(): void
    {
        $policy = $this->draft();
        $service = app(PolicyService::class);

        $service->submitForApproval($policy->fresh(), $this->officer);

        $this->assertFalse(
            $this->officer->can('approve', $policy->fresh()),
            'The owner must never be able to approve their own policy.',
        );

        $this->expectException(ValidationException::class);
        $service->approve($policy->fresh(), $this->officer);
    }

    public function test_a_policy_cannot_be_published_by_its_own_owner(): void
    {
        $policy = $this->draft();
        $service = app(PolicyService::class);

        $service->submitForApproval($policy->fresh(), $this->officer);
        $service->approve($policy->fresh(), $this->cfh);

        $this->assertFalse($this->officer->can('publish', $policy->fresh()));

        $this->expectException(ValidationException::class);
        $service->publish($policy->fresh(), $this->officer);
    }

    public function test_a_system_administrator_who_owns_the_policy_still_cannot_publish_it(): void
    {
        $admin = $this->makeUser('admin@test.local', 'System Administrator');
        $policy = $this->draft(['owner_id' => $admin->id]);
        $service = app(PolicyService::class);

        $service->submitForApproval($policy->fresh(), $admin);
        $service->approve($policy->fresh(), $this->cfh);

        $this->assertFalse($admin->can('publish', $policy->fresh()), 'There is no administrator bypass (R2).');

        $this->expectException(ValidationException::class);
        $service->publish($policy->fresh(), $admin);
    }

    // ── Attestation on publication ───────────────────────────────────

    public function test_publishing_a_policy_that_requires_attestation_opens_a_campaign(): void
    {
        $policy = $this->draft(['requires_attestation' => true, 'attestation_frequency' => 'annual']);
        $service = app(PolicyService::class);

        $service->submitForApproval($policy->fresh(), $this->officer);
        $service->approve($policy->fresh(), $this->cfh);
        $published = $service->publish($policy->fresh(), $this->cfh);

        $this->assertNotNull($published->attestation_campaign_id, 'Publication must open the campaign automatically.');

        $campaign = CsaCampaign::find($published->attestation_campaign_id);

        $this->assertSame('attestation', $campaign->campaign_type);
        $this->assertSame('Open', $campaign->status);
        $this->assertGreaterThan(0, $campaign->responses()->count(), 'Every user in scope gets a response row.');
    }

    public function test_publishing_a_policy_that_does_not_require_attestation_opens_no_campaign(): void
    {
        $policy = $this->draft(['requires_attestation' => false]);
        $service = app(PolicyService::class);

        $service->submitForApproval($policy->fresh(), $this->officer);
        $service->approve($policy->fresh(), $this->cfh);
        $published = $service->publish($policy->fresh(), $this->cfh);

        $this->assertNull($published->attestation_campaign_id);
    }

    public function test_the_attestation_campaign_snapshot_is_the_policy_text(): void
    {
        $policy = $this->draft(['requires_attestation' => true, 'attestation_frequency' => 'on_publication']);

        $this->assertStringContainsString('Declare anything over', $policy->fresh(['sections'])->attestationText());
    }

    // ── Lifecycle and supersession ───────────────────────────────────

    public function test_publication_sets_the_next_review_date_from_the_policy_frequency(): void
    {
        $policy = $this->draft(['review_frequency' => 'quarterly']);
        $service = app(PolicyService::class);

        $service->submitForApproval($policy->fresh(), $this->officer);
        $service->approve($policy->fresh(), $this->cfh);
        $published = $service->publish($policy->fresh(), $this->cfh);

        $this->assertSame(
            now()->addMonthsNoOverflow(3)->toDateString(),
            $published->next_review_at->toDateString(),
        );
    }

    public function test_publishing_a_successor_supersedes_its_predecessor(): void
    {
        $service = app(PolicyService::class);

        $first = $this->draft();
        $service->submitForApproval($first->fresh(), $this->officer);
        $service->approve($first->fresh(), $this->cfh);
        $service->publish($first->fresh(), $this->cfh);

        $second = $this->draft(['title' => 'Anti-Bribery and Corruption Policy v2', 'supersedes_policy_id' => $first->id]);
        $service->submitForApproval($second->fresh(), $this->officer);
        $service->approve($second->fresh(), $this->cfh);
        $service->publish($second->fresh(), $this->cfh);

        $this->assertSame('Superseded', $first->fresh()->status);
        $this->assertNotNull($first->fresh()->effective_to);
    }

    public function test_a_published_policy_text_cannot_be_edited_without_a_revision(): void
    {
        $policy = $this->draft();
        $service = app(PolicyService::class);

        $service->submitForApproval($policy->fresh(), $this->officer);
        $service->approve($policy->fresh(), $this->cfh);
        $service->publish($policy->fresh(), $this->cfh);

        $this->expectException(ValidationException::class);
        $service->syncSections($policy->fresh(), [['heading' => 'Rewritten', 'body' => 'Different rules.']]);
    }

    public function test_the_lifecycle_cannot_be_skipped(): void
    {
        $policy = $this->draft();

        $this->expectException(ValidationException::class);
        app(PolicyService::class)->publish($policy, $this->cfh);
    }

    public function test_a_policy_needs_at_least_one_section_before_approval(): void
    {
        $policy = $this->draft(['sections' => []]);

        $this->expectException(ValidationException::class);
        app(PolicyService::class)->submitForApproval($policy->fresh(), $this->officer);
    }

    // ── Waivers ──────────────────────────────────────────────────────

    private function publishedPolicy(): Policy
    {
        $service = app(PolicyService::class);
        $policy = $this->draft();

        $service->submitForApproval($policy->fresh(), $this->officer);
        $service->approve($policy->fresh(), $this->cfh);
        $service->publish($policy->fresh(), $this->cfh);

        return $policy->fresh();
    }

    public function test_a_waiver_cannot_be_approved_by_the_person_who_requested_it(): void
    {
        $service = app(PolicyService::class);

        $waiver = $service->requestException($this->publishedPolicy(), [
            'justification' => 'Legacy system cannot enforce the limit until Q4.',
            'requested_from' => now()->toDateString(),
            'requested_to' => now()->addDays(60)->toDateString(),
        ], $this->cfh);

        $this->assertFalse($this->cfh->can('decide', $waiver->fresh()));

        $this->expectException(ValidationException::class);
        $service->decideException($waiver->fresh(), $this->cfh, true);
    }

    public function test_an_approved_waiver_expires_on_the_daily_sweep(): void
    {
        $service = app(PolicyService::class);

        $waiver = $service->requestException($this->publishedPolicy(), [
            'justification' => 'Interim arrangement.',
            'requested_from' => now()->subDays(30)->toDateString(),
            'requested_to' => now()->addDays(5)->toDateString(),
        ], $this->owner);

        $service->decideException($waiver->fresh(), $this->cfh, true, 'Approved for one quarter.');
        $this->assertSame('Approved', $waiver->fresh()->status);

        $waiver->fresh()->updateQuietly(['requested_to' => now()->subDay()->toDateString()]);

        $this->assertSame(1, $service->expireWaivers($this->tenant->id));
        $this->assertSame('Expired', $waiver->fresh()->status);
    }

    public function test_a_waiver_can_only_be_raised_against_a_published_policy(): void
    {
        $this->expectException(ValidationException::class);

        app(PolicyService::class)->requestException($this->draft(), [
            'justification' => 'Not yet in force.',
            'requested_from' => now()->toDateString(),
            'requested_to' => now()->addDays(10)->toDateString(),
        ], $this->owner);
    }

    // ── Gap analysis ─────────────────────────────────────────────────

    public function test_gap_analysis_reports_requirements_no_published_policy_governs(): void
    {
        $analysis = app(PolicyService::class)->gapAnalysis();

        $this->assertArrayHasKey('gaps', $analysis);
        $this->assertArrayHasKey('coverage_percent', $analysis);
    }

    // ── Pages ────────────────────────────────────────────────────────

    public function test_the_policy_pages_render(): void
    {
        $policy = $this->publishedPolicy();

        $this->actingAs($this->cfh)
            ->get(route('policies.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Policies/Index')->has('policies.data', 1));

        $this->actingAs($this->cfh)
            ->get(route('policies.show', $policy))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Policies/Show'));

        $this->actingAs($this->cfh)
            ->get(route('policies.gaps'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Policies/Gaps'));

        $this->actingAs($this->cfh)
            ->get(route('policy-exceptions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Policies/Exceptions'));
    }

    public function test_publishing_through_the_route_is_blocked_for_the_owner(): void
    {
        $policy = $this->draft(['owner_id' => $this->cfh->id]);
        $service = app(PolicyService::class);

        $service->submitForApproval($policy->fresh(), $this->cfh);
        $service->approve($policy->fresh(), $this->officer);

        $this->actingAs($this->cfh)
            ->post(route('policies.publish', $policy))
            ->assertForbidden();

        $this->assertSame('Approved', $policy->fresh()->status);
        $this->assertSame(0, PolicyException::count());
    }
}
