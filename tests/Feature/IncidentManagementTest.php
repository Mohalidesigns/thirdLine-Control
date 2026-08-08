<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\ControlException;
use App\Models\Incident;
use App\Models\OrganisationUnit;
use App\Models\Regulator;
use App\Models\RegulatoryObligation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\IncidentService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IncidentManagementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    private User $owner;

    private OrganisationUnit $entity;

    private RegulatoryObligation $breachObligation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active', 'timezone' => 'Africa/Lagos']);

        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->owner = $this->makeUser('owner@test.local', 'Control Owner');

        $this->entity = OrganisationUnit::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'ENT-001',
            'name' => 'Test Bank Plc',
            'type' => 'Head Office',
            'is_legal_entity' => true,
            'entity_type' => 'commercial_bank',
            'jurisdiction' => 'NG',
        ]);

        // The obligation is seeded data, exactly as a content pack would ship
        // it. Nothing in the resolver knows what 72 means.
        $regulator = Regulator::create([
            'code' => 'NDPC-TEST',
            'name' => 'Nigeria Data Protection Commission',
            'jurisdiction' => 'NG',
            'is_active' => true,
        ]);

        $this->breachObligation = RegulatoryObligation::create([
            'tenant_id' => null,
            'regulator_id' => $regulator->id,
            'obligation_ref' => 'TEST-BREACH-72H',
            'title' => 'Personal data breach notified within 72 hours',
            'obligation_type' => 'notification',
            'trigger_type' => 'event',
            'frequency' => 'event_driven',
            'due_rule' => ['type' => 'event_relative', 'event' => 'breach_detected', 'offset_hours' => 72],
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

    private function report(array $overrides = []): Incident
    {
        return app(IncidentService::class)->report([
            'tenant_id' => $this->tenant->id,
            'title' => 'Customer statements exposed on a public bucket',
            'description' => 'A migration left a storage bucket world-readable.',
            'incident_type' => 'data_breach',
            'severity' => 'Critical',
            'occurred_at' => now()->subHours(5),
            'detected_at' => now()->subHours(4),
            'entity_id' => $this->entity->id,
            'owner_id' => $this->owner->id,
            ...$overrides,
        ], $this->officer);
    }

    // ── The notification window comes from the obligation record (R1) ─

    public function test_the_notification_due_time_is_read_from_the_obligation_record(): void
    {
        $incident = $this->report();

        $notification = $incident->notifications()->first();

        $this->assertNotNull($notification, 'A data breach must resolve to the breach-notification obligation.');
        $this->assertSame($this->breachObligation->id, $notification->obligation_id);
        $this->assertSame(
            $incident->detected_at->copy()->addHours(72)->timestamp,
            $notification->due_at->timestamp,
            'The window must be the obligation’s 72 hours measured from detection.',
        );
    }

    public function test_changing_the_obligation_changes_the_countdown(): void
    {
        $incident = $this->report();

        $before = $incident->notifications()->first()->due_at->timestamp;

        // The regulator shortens the window. Nothing in PHP changes.
        $this->breachObligation->update([
            'due_rule' => ['type' => 'event_relative', 'event' => 'breach_detected', 'offset_hours' => 24],
        ]);

        app(IncidentService::class)->refreshNotifications($incident->fresh());

        $after = $incident->fresh()->notifications()->first()->due_at->timestamp;

        $this->assertNotSame($before, $after, 'Editing the obligation must move the countdown.');
        $this->assertSame($incident->detected_at->copy()->addHours(24)->timestamp, $after);
    }

    public function test_an_incident_type_with_no_matching_obligation_owes_nothing(): void
    {
        $incident = $this->report(['incident_type' => 'health_safety']);

        $this->assertSame(0, $incident->notifications()->count());
        $this->assertFalse($incident->fresh()->is_reportable);
    }

    public function test_an_unverified_obligation_travels_with_the_notification(): void
    {
        $incident = $this->report();

        $this->assertSame('unverified', $incident->notifications()->first()->verification_status,
            'R10: an unconfirmed window must be visibly unverified on the incident.');
    }

    // ── The closure gate ─────────────────────────────────────────────

    public function test_an_incident_cannot_close_with_an_open_mandatory_action(): void
    {
        $incident = $this->report();
        $service = app(IncidentService::class);

        // Clear the notification so the action is the only thing blocking.
        $service->waiveNotification($incident->notifications()->first(), $this->cfh, 'Assessed as not notifiable.');

        $service->addAction($incident->fresh(), [
            'action_type' => 'corrective',
            'title' => 'Rotate the exposed access keys',
            'owner_id' => $this->owner->id,
            'is_mandatory' => true,
        ]);

        $service->triage($incident->fresh(), ['severity' => 'Critical', 'incident_type' => 'data_breach'], $this->cfh);

        try {
            $service->close($incident->fresh(), $this->cfh);
            $this->fail('An incident with an open mandatory action must not close.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('actions', $e->errors());
        }

        $this->assertNotSame('Closed', $incident->fresh()->status);
    }

    public function test_an_incident_cannot_close_with_an_outstanding_regulatory_notification(): void
    {
        $incident = $this->report();
        $service = app(IncidentService::class);

        $service->triage($incident->fresh(), ['severity' => 'Critical', 'incident_type' => 'data_breach'], $this->cfh);

        try {
            $service->close($incident->fresh(), $this->cfh);
            $this->fail('An incident owing a notification must not close.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('notifications', $e->errors());
        }
    }

    public function test_an_incident_closes_once_the_actions_and_notifications_are_settled(): void
    {
        $incident = $this->report();
        $service = app(IncidentService::class);

        $action = $service->addAction($incident->fresh(), [
            'action_type' => 'containment',
            'title' => 'Make the bucket private',
            'owner_id' => $this->owner->id,
            'is_mandatory' => true,
        ]);

        $service->recordNotification(
            $incident->notifications()->first(),
            $this->cfh,
            'NDPC/2026/00417',
        );

        $service->completeAction($action, $this->owner);
        $service->triage($incident->fresh(), ['severity' => 'Critical', 'incident_type' => 'data_breach'], $this->cfh);
        $service->close($incident->fresh(), $this->cfh);

        $this->assertSame('Closed', $incident->fresh()->status);
        $this->assertSame($this->cfh->id, $incident->fresh()->closed_by);
    }

    public function test_the_reporter_of_an_incident_cannot_close_it(): void
    {
        $incident = $this->report();

        $this->assertFalse(
            $this->officer->can('close', $incident->fresh()),
            'The person who reported an incident cannot be the one who declares it finished.',
        );
    }

    public function test_an_action_cannot_be_verified_by_its_own_owner(): void
    {
        $incident = $this->report();
        $service = app(IncidentService::class);

        $action = $service->addAction($incident->fresh(), [
            'action_type' => 'preventive',
            'title' => 'Add a deploy-time bucket policy check',
            'owner_id' => $this->cfh->id,
            'is_mandatory' => true,
        ]);

        $service->completeAction($action, $this->cfh);

        $this->expectException(ValidationException::class);
        $service->verifyAction($action->fresh(), $this->cfh);
    }

    // ── Control failure linkage ──────────────────────────────────────

    public function test_naming_a_failed_control_raises_an_exception_against_it(): void
    {
        $control = Control::create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-900',
            'title' => 'Storage bucket access review',
            'type' => 'Preventive',
            'nature' => 'Manual',
            'frequency' => 'Monthly',
            'owner_id' => $this->owner->id,
            'status' => 'Active',
        ]);

        $incident = $this->report(['control_failure_ids' => [$control->id]]);

        $exception = ControlException::where('source_type', 'Incident')
            ->where('source_id', $incident->id)
            ->first();

        $this->assertNotNull($exception, 'A named control failure must raise an exception.');
        $this->assertSame($control->id, $exception->control_id);
        $this->assertSame('Critical', $exception->severity);
        $this->assertContains($control->id, $incident->fresh()->control_failure_ids);
    }

    // ── Loss capture (R7) ────────────────────────────────────────────

    public function test_net_loss_is_derived_from_gross_less_recovery_in_minor_units(): void
    {
        $incident = $this->report();

        app(IncidentService::class)->recordLoss($incident, [
            'currency' => 'NGN',
            'gross_loss_minor' => 4_850_000_00,
            'recovery_minor' => 1_200_000_00,
        ]);

        $this->assertSame(3_650_000_00, $incident->fresh()->net_loss_minor);
        $this->assertSame('₦3,650,000.00', $incident->fresh()->netLossMoney()->format());
    }

    public function test_recovery_above_gross_loss_is_rejected(): void
    {
        $incident = $this->report();

        $this->expectException(ValidationException::class);
        app(IncidentService::class)->recordLoss($incident, [
            'currency' => 'NGN',
            'gross_loss_minor' => 100_00,
            'recovery_minor' => 500_00,
        ]);
    }

    public function test_the_basel_loss_view_totals_net_loss_per_event_type_and_currency(): void
    {
        foreach ([[1_000_000_00, 250_000_00], [500_000_00, 0]] as $index => [$gross, $recovery]) {
            $incident = $this->report([
                'title' => "Switch outage {$index}",
                'incident_type' => 'continuity',
                'basel_event_type' => 'Business Disruption & System Failures',
            ]);

            app(IncidentService::class)->recordLoss($incident, [
                'currency' => 'NGN',
                'gross_loss_minor' => $gross,
                'recovery_minor' => $recovery,
            ]);
        }

        $rows = app(IncidentService::class)->lossByBaselEventType($this->tenant->id);

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['incidents']);
        $this->assertSame(1_250_000_00, $rows[0]['net_loss']['amount']);
        $this->assertSame('₦1,250,000.00', $rows[0]['net_loss']['formatted']);
    }

    public function test_near_misses_are_excluded_from_the_basel_loss_view(): void
    {
        $incident = $this->report(['near_miss' => true, 'basel_event_type' => 'External Fraud']);
        app(IncidentService::class)->recordLoss($incident, [
            'currency' => 'NGN', 'gross_loss_minor' => 0, 'recovery_minor' => 0,
        ]);

        $rows = app(IncidentService::class)->lossByBaselEventType($this->tenant->id);

        $this->assertSame([], $rows);
    }

    // ── Pages ────────────────────────────────────────────────────────

    public function test_the_incident_pages_render(): void
    {
        $incident = $this->report();

        $this->actingAs($this->cfh)
            ->get(route('incidents.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Incidents/Index')->has('incidents.data', 1));

        $this->actingAs($this->cfh)
            ->get(route('incidents.show', $incident))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Incidents/Show')->has('incident.notifications', 1));
    }
}
