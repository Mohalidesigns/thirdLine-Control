<?php

namespace Tests\Feature;

use App\Models\Regulator;
use App\Models\RegulatoryChange;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RegulatoryChangeService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * New → Under Review → Impact Assessed → Actioned, with the sign-off gate
 * closed to whoever wrote the assessment (R2).
 */
class RegulatoryChangeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private RegulatoryChangeService $service;

    private RegulatoryChange $change;

    private User $officer;

    private User $head;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->service = app(RegulatoryChangeService::class);

        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->head = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->admin = $this->makeUser('admin@test.local', 'System Administrator');

        $regulator = Regulator::create(['code' => 'CBN', 'name' => 'Central Bank of Nigeria', 'jurisdiction' => 'NG']);

        $this->change = RegulatoryChange::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'regulator_id' => $regulator->id,
            'title' => 'Circular on payment data localisation',
            'reference' => 'PSS/DIR/PUB/CIR/001/004',
            'published_at' => now()->subDays(3),
            'status' => 'New',
            'source' => 'manual',
        ]);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function assess(User $assessor): void
    {
        $this->service->startReview($this->change, $assessor);
        $this->service->recordAssessment($this->change->fresh(), $assessor, [
            'impact_assessment' => 'Three payment obligations move and two controls need amending before 1 January 2027.',
        ]);
    }

    public function test_the_status_machine_refuses_a_skipped_step(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->markActioned($this->change, $this->head);
    }

    public function test_an_assessment_needs_a_narrative(): void
    {
        $this->service->startReview($this->change, $this->officer);

        $this->expectException(ValidationException::class);

        $this->service->recordAssessment($this->change->fresh(), $this->officer, ['impact_assessment' => '']);
    }

    public function test_the_assessor_cannot_sign_off_their_own_assessment(): void
    {
        $this->assess($this->officer);

        $this->expectException(ValidationException::class);

        $this->service->markActioned($this->change->fresh(), $this->officer);
    }

    public function test_a_system_administrator_gets_no_bypass_on_their_own_assessment(): void
    {
        $this->assess($this->admin);

        $this->assertFalse(
            $this->admin->can('action', $this->change->fresh()),
            'There is no administrator bypass on this gate.',
        );

        $this->expectException(ValidationException::class);

        $this->service->markActioned($this->change->fresh(), $this->admin);
    }

    public function test_a_second_person_can_sign_the_change_off(): void
    {
        $this->assess($this->officer);

        $this->service->markActioned($this->change->fresh(), $this->head, 'Obligations updated and controls amended.');

        $this->assertSame('Actioned', $this->change->fresh()->status);
        $this->assertSame($this->head->id, $this->change->fresh()->actioned_by);
        $this->assertSame($this->officer->id, $this->change->fresh()->assessed_by);
    }

    public function test_the_assessment_records_the_obligations_and_controls_it_touches(): void
    {
        $this->service->startReview($this->change, $this->officer);
        $this->service->recordAssessment($this->change->fresh(), $this->officer, [
            'impact_assessment' => 'Payment data must be stored in country from 1 January 2027.',
            'affected_obligation_ids' => [11, 12],
            'affected_control_ids' => [7],
        ]);

        $this->assertSame([11, 12], $this->change->fresh()->affected_obligation_ids);
        $this->assertSame([7], $this->change->fresh()->affected_control_ids);
    }

    public function test_the_http_endpoint_enforces_the_same_gate(): void
    {
        $this->assess($this->officer);

        $this->actingAs($this->officer)
            ->post(route('regulatory-changes.action', $this->change->id))
            ->assertForbidden();

        $this->actingAs($this->head)
            ->post(route('regulatory-changes.action', $this->change->id))
            ->assertRedirect();

        $this->assertSame('Actioned', $this->change->fresh()->status);
    }

    public function test_an_actioned_change_is_closed(): void
    {
        $this->assess($this->officer);
        $this->service->markActioned($this->change->fresh(), $this->head);

        $this->expectException(ValidationException::class);

        $this->service->transition($this->change->fresh(), 'Under Review');
    }

    public function test_feed_ingestion_is_deduplicated_on_the_publication_guid(): void
    {
        $regulator = Regulator::create([
            'code' => 'NDPC', 'name' => 'Nigeria Data Protection Commission',
            'jurisdiction' => 'NG', 'feed_url' => 'https://example.test/feed.xml',
        ]);

        Http::fake([
            'example.test/*' => Http::response(<<<'XML'
                <?xml version="1.0"?>
                <rss version="2.0"><channel>
                  <item>
                    <title>Guidance note on compliance audit returns</title>
                    <link>https://example.test/guidance-1</link>
                    <guid>guidance-1</guid>
                    <pubDate>Mon, 03 Aug 2026 09:00:00 +0100</pubDate>
                    <description>Clarifies the audit return process.</description>
                  </item>
                </channel></rss>
                XML, 200),
        ]);

        $first = $this->service->ingestFromFeeds($this->tenant->id);
        $second = $this->service->ingestFromFeeds($this->tenant->id);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second, 'The same publication must not be logged twice.');
        $this->assertDatabaseHas('regulatory_changes', [
            'regulator_id' => $regulator->id,
            'source_guid' => 'guidance-1',
            'status' => 'New',
            'source' => 'rss',
        ]);
    }

    public function test_a_regulator_whose_feed_is_down_does_not_break_the_sweep(): void
    {
        Regulator::create([
            'code' => 'BROKEN', 'name' => 'Unreachable Regulator',
            'jurisdiction' => 'NG', 'feed_url' => 'https://broken.test/feed.xml',
        ]);

        Http::fake([
            'broken.test/*' => Http::response('', 500),
        ]);

        $this->artisan('atheris:poll-regulatory-feeds')->assertSuccessful();
    }
}
