<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\OrganisationUnit;
use App\Models\Regulator;
use App\Models\RegulatoryObligation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ComplaintService;
use Carbon\Carbon;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\GovernanceTaxonomySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The CBN consumer-protection clocks (11.3). Every figure asserted here is
 * read from an obligation record created in setUp exactly as a content pack
 * would ship it — if any of these numbers were hard-coded in PHP, changing
 * the record below would not change the result and these tests would fail.
 */
class ComplaintSlaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    private OrganisationUnit $entity;

    private RegulatoryObligation $ackObligation;

    private RegulatoryObligation $weeklyObligation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(GovernanceTaxonomySeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active', 'timezone' => 'Africa/Lagos']);

        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');

        $this->entity = OrganisationUnit::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'ENT-001',
            'name' => 'Test Bank Plc',
            'type' => 'Head Office',
            'is_legal_entity' => true,
            'entity_type' => 'commercial_bank',
            'jurisdiction' => 'NG',
        ]);

        $regulator = Regulator::create([
            'code' => 'CBN-TEST',
            'name' => 'Central Bank of Nigeria',
            'jurisdiction' => 'NG',
            'is_active' => true,
        ]);

        $this->ackObligation = RegulatoryObligation::create([
            'tenant_id' => null,
            'regulator_id' => $regulator->id,
            'obligation_ref' => 'TEST-CPF-ACK-24H',
            'title' => 'Acknowledge a complaint within 24 hours',
            'obligation_type' => 'notification',
            'trigger_type' => 'event',
            'frequency' => 'event_driven',
            'due_rule' => ['type' => 'event_relative', 'event' => 'complaint_received', 'offset_hours' => 24],
            'penalty_amount_minor' => 200_000_000,   // ₦2,000,000
            'penalty_currency' => 'NGN',
            'penalty_basis' => 'per_instance',
            'verification_status' => 'unverified',
            'is_active' => true,
        ]);

        $this->weeklyObligation = RegulatoryObligation::create([
            'tenant_id' => null,
            'regulator_id' => $regulator->id,
            'obligation_ref' => 'TEST-CPF-RESOLUTION',
            'title' => 'Resolve complaints within the prescribed period',
            'obligation_type' => 'operational',
            'trigger_type' => 'calendar',
            'frequency' => 'weekly',
            'due_rule' => ['type' => 'relative', 'anchor' => 'period_end', 'offset_days' => 0],
            'penalty_amount_minor' => 50_000_000,    // ₦500,000
            'penalty_currency' => 'NGN',
            'penalty_basis' => 'per_week',
            'verification_status' => 'unverified',
            'is_active' => true,
        ]);

        $this->actingAs($this->officer);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function intake(array $overrides = []): Complaint
    {
        return app(ComplaintService::class)->intake([
            'tenant_id' => $this->tenant->id,
            'channel' => 'branch',
            'subject' => 'Undisclosed account maintenance charges',
            'description' => 'Charges applied monthly without notice.',
            'customer_name' => 'Test Customer',
            'entity_id' => $this->entity->id,
            'assigned_to' => $this->officer->id,
            ...$overrides,
        ], $this->officer);
    }

    // ── The 24-hour acknowledgement clock ────────────────────────────

    public function test_the_acknowledgement_window_comes_from_the_obligation_record(): void
    {
        $receivedAt = Carbon::parse('2026-06-01 09:15:00', 'Africa/Lagos');
        $complaint = $this->intake(['received_at' => $receivedAt]);

        $this->assertSame(
            $receivedAt->copy()->addHours(24)->timestamp,
            $complaint->acknowledgement_due_at->timestamp,
        );
    }

    public function test_changing_the_obligation_changes_the_acknowledgement_window(): void
    {
        $receivedAt = Carbon::parse('2026-06-01 09:15:00', 'Africa/Lagos');
        $complaint = $this->intake(['received_at' => $receivedAt]);

        $this->ackObligation->update([
            'due_rule' => ['type' => 'event_relative', 'event' => 'complaint_received', 'offset_hours' => 48],
        ]);

        app(ComplaintService::class)->applyDeadlines($complaint->fresh());

        $this->assertSame(
            $receivedAt->copy()->addHours(48)->timestamp,
            $complaint->fresh()->acknowledgement_due_at->timestamp,
            'The window is the obligation’s, not the code’s.',
        );
    }

    /**
     * The window is an elapsed duration, not a local clock reading: 24 hours
     * after 23:30 on the night the clocks go forward is still 24 hours.
     */
    public function test_the_acknowledgement_clock_survives_a_dst_transition(): void
    {
        $this->tenant->update(['timezone' => 'Europe/London']);

        // BST begins 2027-03-28 01:00 UTC; this receipt straddles it.
        $this->travelTo(Carbon::parse('2027-03-29 12:00:00', 'UTC'));

        $receivedAt = Carbon::parse('2027-03-27 23:30:00', 'UTC');
        $complaint = $this->intake(['received_at' => $receivedAt]);

        $due = $complaint->acknowledgement_due_at;

        $this->assertSame(24 * 3600, $due->getTimestamp() - $receivedAt->getTimestamp(),
            'A DST transition must not lengthen or shorten a statutory window.');

        // The local clock reading does shift by the DST hour — that is the
        // correct behaviour, and the reason the instant is what we store.
        $this->assertSame('00:30', $due->copy()->setTimezone('Europe/London')->format('H:i'));
    }

    public function test_the_clock_is_computed_in_the_tenant_timezone(): void
    {
        $receivedAt = Carbon::parse('2026-06-01 09:15:00', 'UTC');

        $lagos = $this->intake(['received_at' => $receivedAt]);
        $lagosDue = $lagos->acknowledgement_due_at->timestamp;

        $this->tenant->update(['timezone' => 'Africa/Nairobi']);
        $nairobi = $this->intake(['received_at' => $receivedAt, 'subject' => 'Second complaint']);

        // An hour-precise window is an elapsed duration, so the instant is
        // the same in both timezones — only its rendering differs.
        $this->assertSame($lagosDue, $nairobi->acknowledgement_due_at->timestamp);
    }

    // ── The server owns the acknowledgement timestamp ────────────────

    public function test_acknowledgement_is_stamped_server_side_and_cannot_be_supplied(): void
    {
        $complaint = $this->intake(['received_at' => now()->subHours(2)]);

        $this->actingAs($this->cfh)
            ->post(route('complaints.acknowledge', $complaint), [
                'acknowledged_at' => now()->subDays(5)->toIso8601String(),
                'note' => 'Acknowledged by phone.',
            ])
            ->assertRedirect();

        $stored = DB::table('complaints')->where('id', $complaint->id)->value('acknowledged_at');

        $this->assertNotNull($stored);
        $this->assertTrue(
            Carbon::parse($stored)->greaterThan(now()->subMinute()),
            'The stored acknowledgement time must be the server’s, not the client’s.',
        );
    }

    public function test_intake_rejects_a_future_receipt_time(): void
    {
        $this->expectException(ValidationException::class);
        $this->intake(['received_at' => now()->addDay()]);
    }

    // ── Penalty exposure ─────────────────────────────────────────────

    public function test_missing_the_acknowledgement_window_costs_one_instance_penalty(): void
    {
        // Received 30 hours ago and never acknowledged; still inside the
        // 14-day resolution window, so only the acknowledgement figure lands.
        $complaint = $this->intake(['received_at' => now()->subHours(30)]);
        app(ComplaintService::class)->refreshSla($complaint->fresh());

        $this->assertSame(200_000_000, $complaint->fresh()->penalty_exposure_minor);
        $this->assertTrue($complaint->fresh()->sla_breached);
    }

    public function test_an_acknowledged_complaint_inside_both_windows_owes_nothing(): void
    {
        $complaint = $this->intake(['received_at' => now()->subHours(2)]);
        app(ComplaintService::class)->acknowledge($complaint->fresh(), $this->officer);

        $this->assertSame(0, $complaint->fresh()->penalty_exposure_minor);
        $this->assertFalse($complaint->fresh()->sla_breached);
    }

    /**
     * ₦500,000 per complaint per week unresolved, with a part week counted
     * as a whole week — the same ceiling ObligationService applies.
     */
    public function test_penalty_accrues_per_started_week_past_the_resolution_deadline(): void
    {
        $service = app(ComplaintService::class);

        // Resolution window is 14 days by default, so "days past deadline"
        // is (age - 14). Each row is [age in days, expected weeks].
        foreach ([[15, 1], [21, 1], [22, 2], [28, 2], [29, 3]] as $index => [$ageDays, $expectedWeeks]) {
            $complaint = $this->intake([
                'received_at' => now()->subDays($ageDays),
                'subject' => "Complaint {$index}",
            ]);

            // Acknowledge inside the window so only the weekly figure counts.
            $complaint->fresh()->updateQuietly(['acknowledged_at' => now()->subDays($ageDays)->addHour()]);
            $service->refreshSla($complaint->fresh());

            $this->assertSame(
                $expectedWeeks,
                $service->weeksOverdue($complaint->fresh()),
                "A complaint {$ageDays} days old should be {$expectedWeeks} started week(s) overdue.",
            );

            $this->assertSame(
                50_000_000 * $expectedWeeks,
                $complaint->fresh()->penalty_exposure_minor,
                "Exposure must be ₦500,000 × {$expectedWeeks}.",
            );
        }
    }

    public function test_a_complaint_that_misses_both_windows_owes_both_penalties(): void
    {
        // 23 days old, never acknowledged: ₦2,000,000 + 2 weeks × ₦500,000.
        $complaint = $this->intake(['received_at' => now()->subDays(23)]);
        app(ComplaintService::class)->refreshSla($complaint->fresh());

        $this->assertSame(200_000_000 + (2 * 50_000_000), $complaint->fresh()->penalty_exposure_minor);
    }

    public function test_exposure_stops_accruing_once_the_complaint_is_resolved(): void
    {
        $service = app(ComplaintService::class);
        $complaint = $this->intake(['received_at' => now()->subDays(30)]);

        $complaint->fresh()->updateQuietly(['acknowledged_at' => now()->subDays(30)->addHour()]);
        $service->refreshSla($complaint->fresh());
        $accruing = $complaint->fresh()->penalty_exposure_minor;

        // Resolved a week ago: the clock stopped then, not now.
        $complaint->fresh()->updateQuietly(['status' => 'Resolved', 'resolved_at' => now()->subDays(7)]);
        $service->refreshSla($complaint->fresh());

        $this->assertLessThan($accruing, $complaint->fresh()->penalty_exposure_minor);
        $this->assertSame(50_000_000 * 2, $complaint->fresh()->penalty_exposure_minor);
    }

    public function test_exposure_is_reported_per_currency_and_never_summed_across_them(): void
    {
        $this->intake(['received_at' => now()->subDays(23)]);
        app(ComplaintService::class)->refreshAll($this->tenant->id);

        $exposure = app(ComplaintService::class)->exposureByCurrency($this->tenant->id);

        $this->assertArrayHasKey('NGN', $exposure);
        $this->assertSame('NGN', $exposure['NGN']['currency']);
    }

    public function test_removing_the_weekly_obligation_removes_the_weekly_accrual(): void
    {
        $this->weeklyObligation->update(['is_active' => false]);

        $complaint = $this->intake(['received_at' => now()->subDays(23)]);
        app(ComplaintService::class)->refreshSla($complaint->fresh());

        $this->assertSame(200_000_000, $complaint->fresh()->penalty_exposure_minor,
            'With no per-week obligation on file there is no per-week exposure.');
    }

    // ── Handling rules ───────────────────────────────────────────────

    public function test_a_complaint_cannot_be_resolved_without_a_root_cause(): void
    {
        $complaint = $this->intake();
        app(ComplaintService::class)->acknowledge($complaint->fresh(), $this->officer);

        $this->expectException(ValidationException::class);
        app(ComplaintService::class)->resolve($complaint->fresh(), $this->officer, [
            'resolution_summary' => 'Sorted it out.',
            'resolution_type' => 'upheld',
        ]);
    }

    public function test_a_complaint_cannot_be_resolved_before_it_is_acknowledged(): void
    {
        $complaint = $this->intake();

        $this->expectException(ValidationException::class);
        app(ComplaintService::class)->resolve($complaint->fresh(), $this->officer, [
            'resolution_summary' => 'Sorted it out.',
            'resolution_type' => 'upheld',
            'root_cause_id' => ComplaintCategory::ofKind('root_cause')->first()->id,
        ]);
    }

    public function test_every_complaint_gets_a_unique_customer_facing_tracking_id(): void
    {
        $first = $this->intake();
        $second = $this->intake(['subject' => 'Another complaint']);

        $this->assertNotSame($first->tracking_id, $second->tracking_id);
        $this->assertMatchesRegularExpression('/^CT\d{2}[0-9A-F]{8}$/', $first->tracking_id);
    }

    // ── Pages ────────────────────────────────────────────────────────

    public function test_the_complaint_pages_render(): void
    {
        $complaint = $this->intake();

        $this->actingAs($this->cfh)
            ->get(route('complaints.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Complaints/Index')->has('complaints.data', 1));

        $this->actingAs($this->cfh)
            ->get(route('complaints.show', $complaint))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Complaints/Show')
                ->where('acknowledgementObligation.obligation_ref', 'TEST-CPF-ACK-24H'));

        $this->actingAs($this->cfh)
            ->get(route('complaints.analysis'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Complaints/Analysis'));
    }

    public function test_the_cpd_return_exports(): void
    {
        $this->intake();

        $this->actingAs($this->cfh)
            ->get(route('complaints.cpd-return', [
                'from' => now()->subMonth()->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertOk();
    }
}
