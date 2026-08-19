<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\ControlException;
use App\Models\ExceptionAction;
use App\Models\ExceptionEscalation;
use App\Models\ExceptionResponse;
use App\Models\ExceptionResponseLink;
use App\Models\ExceptionSlaPolicy;
use App\Models\NotificationPreference;
use App\Models\OrganisationUnit;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ExceptionEscalationIssuedNotification;
use App\Services\ExceptionEscalationService;
use App\Services\ExceptionResponseService;
use App\Services\ExceptionService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\NotificationEventSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * CR-01 — the Exception Manager: departmental escalation, response and
 * closure. Covers the fan-out, the SoD failing paths (R-A/R-B/R-C with no
 * admin bypass), the R-D closure block, the reject/re-issue loop, the
 * business-day SLA clock, the secure-link properties (R-H), the idempotent
 * chase engine, loud-failure routing (R-G) and tenant isolation (R-I).
 */
class ExceptionManagerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Tenant $otherTenant;

    private User $cfh;

    private User $secondCfh;

    private User $officer;

    private User $owner;

    private User $owner2;

    private User $admin;

    private OrganisationUnit $ops;

    private OrganisationUnit $treasury;

    private OrganisationUnit $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(NotificationEventSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->otherTenant = Tenant::create(['name' => 'Other Bank', 'status' => 'active']);

        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->secondCfh = $this->makeUser('cfh2@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->owner = $this->makeUser('owner@test.local', 'Control Owner');
        $this->owner2 = $this->makeUser('owner2@test.local', 'Control Owner');
        $this->admin = $this->makeUser('admin@test.local', 'System Administrator');

        $this->ops = $this->makeUnit('Operations', $this->owner);
        $this->treasury = $this->makeUnit('Treasury', $this->owner2);
        $this->branch = $this->makeUnit('Marina Branch', $this->makeUser('branch@test.local', 'Control Owner'));

        foreach (ControlException::SEVERITIES as $severity) {
            ExceptionSlaPolicy::withoutGlobalScope('tenant')->create([
                'tenant_id' => $this->tenant->id,
                'name' => "Standard {$severity}",
                'severity' => $severity,
                'acknowledge_within_hours' => 24,
                'respond_within_days' => 5,
                'reminder_offsets' => [-1, 0, 1],
                'max_reminders' => 3,
                'escalate_after_days' => 2,
                'auto_escalate_to_matrix' => true,
                'is_active' => true,
            ]);
        }
    }

    private function makeUser(string $email, string $role, ?Tenant $tenant = null): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => ($tenant ?? $this->tenant)->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makeUnit(string $name, User $head): OrganisationUnit
    {
        return OrganisationUnit::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'code' => strtoupper(substr($name, 0, 3)).fake()->unique()->numberBetween(10, 99),
            'name' => $name,
            'type' => 'Department',
            'head_user_id' => $head->id,
        ]);
    }

    private function makeException(array $overrides = []): ControlException
    {
        return ControlException::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'reference' => 'EXC-'.fake()->unique()->numberBetween(1000, 9999),
            'source_type' => 'Manual',
            'title' => 'Unreconciled suspense balances',
            'severity' => 'High',
            'owner_id' => $this->owner->id,
            'unit_id' => $this->ops->id,
            'raised_by' => $this->officer->id,
            'date_raised' => now()->toDateString(),
            'target_closure_date' => now()->addDays(14)->toDateString(),
            'status' => 'In Progress',
            ...$overrides,
        ]);
    }

    private function issueTo(ControlException $exception, array $targets, ?User $actor = null): array
    {
        $this->actingAs($actor ?? $this->officer);

        return app(ExceptionEscalationService::class)->issue($exception, $targets, $actor ?? $this->officer);
    }

    private function unitTarget(OrganisationUnit $unit, array $overrides = []): array
    {
        return [
            'target_type' => 'unit',
            'unit_id' => $unit->id,
            'issue_note' => "Explain the lapse in {$unit->name} and commit to a dated remediation plan.",
            'required_response' => 'both',
            ...$overrides,
        ];
    }

    private function submitResponse(ExceptionEscalation $escalation, User $responder, array $overrides = []): ExceptionResponse
    {
        $this->actingAs($responder);

        return app(ExceptionResponseService::class)->submit($escalation, [
            'position' => 'Accepted',
            'management_comment' => 'The lapse arose from the migration backlog; clearance is scheduled and owned.',
            'root_cause' => 'Reconciliation staff reassigned without backfill.',
            'root_cause_category' => 'process',
            'agreed_action_plan' => 'Clear all aged items and reinstate the daily clearance report.',
            'proposed_target_date' => now()->addDays(20)->toDateString(),
            ...$overrides,
        ], $responder);
    }

    // ── CR1.1: fan-out ────────────────────────────────────────────────

    public function test_fanout_issuing_to_three_units_creates_three_independent_escalations(): void
    {
        Notification::fake();

        $exception = $this->makeException();

        $escalations = $this->issueTo($exception, [
            $this->unitTarget($this->ops),
            $this->unitTarget($this->treasury),
            $this->unitTarget($this->branch),
        ]);

        $this->assertCount(3, $escalations);

        $respondents = collect($escalations)->pluck('respondent_id');
        $this->assertCount(3, $respondents->unique());

        // Independent clocks and references.
        $this->assertCount(3, collect($escalations)->pluck('reference')->unique());

        foreach ($escalations as $escalation) {
            $this->assertSame('Issued', $escalation->status);
            $this->assertSame(1, $escalation->round_no);
            $this->assertNotNull($escalation->acknowledge_due_at);
            $this->assertNotNull($escalation->response_due_at);
            $this->assertNotEmpty($escalation->notified_to);
        }

        // Independent threads: answering one leaves the others untouched.
        $this->submitResponse($escalations[0], $this->owner);

        $this->assertSame('Responded', $escalations[0]->fresh()->status);
        $this->assertSame('Issued', $escalations[1]->fresh()->status);
        $this->assertSame('Issued', $escalations[2]->fresh()->status);

        $this->assertSame(3, $exception->fresh()->open_escalation_count);
        $this->assertNotNull($exception->fresh()->first_escalated_at);
    }

    // ── SoD failing paths (R-A / R-B / R-C) ──────────────────────────

    public function test_the_raiser_gets_403_on_respond(): void
    {
        Notification::fake();

        // The exception was RAISED by the same owner it is now addressed to.
        $exception = $this->makeException(['raised_by' => $this->owner->id]);
        [$escalation] = $this->issueTo($exception, [$this->unitTarget($this->ops)], $this->officer);

        $this->assertSame($this->owner->id, $escalation->respondent_id);

        $this->actingAs($this->owner)
            ->post(route('exception-manager.respond.submit', $escalation), [
                'position' => 'Accepted',
                'management_comment' => 'Trying to answer my own exception.',
                'root_cause' => 'n/a',
                'agreed_action_plan' => 'n/a',
            ])
            ->assertForbidden();

        $this->assertSame('Issued', $escalation->fresh()->status);
    }

    public function test_the_responder_gets_403_on_review(): void
    {
        Notification::fake();

        $exception = $this->makeException();
        [$escalation] = $this->issueTo($exception, [
            $this->unitTarget($this->ops, ['respondent_id' => $this->secondCfh->id, 'target_type' => 'user', 'unit_id' => null]),
        ]);

        $response = $this->submitResponse($escalation, $this->secondCfh);

        // The second CFH holds every review permission — and is still
        // refused, because they submitted the response (R-B).
        $this->actingAs($this->secondCfh)
            ->post(route('exception-manager.review', [$escalation, $response]), [
                'decision' => 'accept',
            ])
            ->assertForbidden();

        $this->assertSame('Pending Review', $response->fresh()->review_status);
    }

    public function test_a_system_administrator_gets_403_on_accept_and_on_close(): void
    {
        Notification::fake();

        $exception = $this->makeException();
        [$escalation] = $this->issueTo($exception, [$this->unitTarget($this->ops)]);
        $response = $this->submitResponse($escalation, $this->owner);

        // The explicit no-admin-bypass test (R2): platform keys confer no
        // part in the loop.
        $this->actingAs($this->admin)
            ->post(route('exception-manager.review', [$escalation, $response]), ['decision' => 'accept'])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->post(route('exception-manager.close', $escalation), ['validation_status' => 'Effective'])
            ->assertForbidden();

        // And Verified-Closed on the exception itself stays shut too.
        $exception->update(['status' => 'Remediated']);

        $this->actingAs($this->admin)
            ->post(route('exceptions.close', $exception), ['verification_method' => 'Admin bypass attempt'])
            ->assertForbidden();
    }

    public function test_a_control_owner_can_respond_and_complete_but_not_verified_close(): void
    {
        Notification::fake();

        $exception = $this->makeException();
        [$escalation] = $this->issueTo($exception, [$this->unitTarget($this->ops)]);

        // Respond through the real screen: opening it acknowledges.
        $this->actingAs($this->owner)
            ->get(route('exception-manager.respond', $escalation))
            ->assertOk();

        $fresh = $escalation->fresh();
        $this->assertNotNull($fresh->acknowledged_at);
        $this->assertSame('Acknowledged', $fresh->status);

        $this->actingAs($this->owner)
            ->post(route('exception-manager.respond.submit', $escalation), [
                'position' => 'Accepted',
                'management_comment' => 'Backlog acknowledged; clearance plan attached below.',
                'root_cause' => 'Staff reassignment without backfill.',
                'root_cause_category' => 'process',
                'agreed_action_plan' => 'Clear all aged items by month end.',
                'proposed_target_date' => now()->addDays(15)->toDateString(),
            ])
            ->assertRedirect();

        $response = $escalation->responses()->first();
        $this->assertNotNull($response->submitted_at);

        // The control function accepts; the commitment becomes an action.
        $this->actingAs($this->cfh);
        app(ExceptionEscalationService::class)->acceptResponse($escalation->fresh(), $response, $this->cfh);

        $action = $escalation->actions()->first();
        $this->assertNotNull($action);
        $this->assertSame($this->owner->id, $action->owner_id);

        // The owner completes their action…
        $this->actingAs($this->owner)
            ->post(route('exception-actions.complete', $action), ['note' => 'All items cleared.'])
            ->assertRedirect();

        $this->assertSame('Completed', $action->fresh()->status);

        // …but can never reach Verified-Closed (FR-5.4 survives CR-01).
        $exception->update(['status' => 'Remediated']);

        $this->actingAs($this->owner)
            ->post(route('exceptions.close', $exception), ['verification_method' => 'Owner attempt'])
            ->assertForbidden();
    }

    // ── R-D: closure blocked while the loop is open ──────────────────

    public function test_close_throws_while_an_escalation_is_open_and_succeeds_once_all_are_closed_or_withdrawn(): void
    {
        Notification::fake();

        $exception = $this->makeException(['status' => 'Remediated']);
        [$escalationA, $escalationB] = $this->issueTo($exception, [
            $this->unitTarget($this->ops),
            $this->unitTarget($this->treasury),
        ]);

        $this->actingAs($this->secondCfh);

        try {
            app(ExceptionService::class)->verifyAndClose($exception->fresh(), $this->secondCfh, [
                'verification_method' => 'Attempted while the loop is open',
            ]);
            $this->fail('Verified-Closed was reached with open escalations.');
        } catch (ValidationException $e) {
            // The error names the open escalations (R-D).
            $this->assertStringContainsString($escalationA->reference, $e->getMessage());
            $this->assertStringContainsString($escalationB->reference, $e->getMessage());
        }

        // Close the loop: A runs the full accept → complete → validate path,
        // B is withdrawn.
        $response = $this->submitResponse($escalationA, $this->owner);
        $this->actingAs($this->cfh);
        app(ExceptionEscalationService::class)->acceptResponse($escalationA->fresh(), $response, $this->cfh);
        $action = $escalationA->actions()->first();
        app(ExceptionResponseService::class)->completeAction($action, $this->owner);
        app(ExceptionEscalationService::class)->close($escalationA->fresh(), $this->cfh, 'Effective', 'Re-performed the control.');

        app(ExceptionEscalationService::class)->withdraw($escalationB->fresh(), $this->cfh, 'Issued in error — Treasury does not own this process.');

        $this->assertSame('Closed', $escalationA->fresh()->status);
        $this->assertSame('Withdrawn', $escalationB->fresh()->status);

        app(ExceptionService::class)->verifyAndClose($exception->fresh(), $this->secondCfh, [
            'verification_method' => 'Re-performance on the current sample',
        ]);

        $this->assertSame('Verified-Closed', $exception->fresh()->status);
        $this->assertSame('All Closed', $exception->fresh()->escalation_status);
    }

    // ── CR1.4: reject / re-issue loop ────────────────────────────────

    public function test_reject_increments_round_sets_new_due_date_resumes_the_ladder_and_preserves_round_one(): void
    {
        Notification::fake();

        $exception = $this->makeException();
        [$escalation] = $this->issueTo($exception, [$this->unitTarget($this->ops)]);
        $firstDue = $escalation->response_due_at;

        $response = $this->submitResponse($escalation, $this->owner, [
            'management_comment' => 'Will clear soon.',
        ]);

        Carbon::setTestNow(now()->addDays(2));

        $this->actingAs($this->cfh);
        app(ExceptionEscalationService::class)->rejectResponse(
            $escalation->fresh(), $response, $this->cfh, 'No dated plan and no named owner.',
        );

        $fresh = $escalation->fresh();
        $this->assertSame('Reissued', $fresh->status);
        $this->assertSame(2, $fresh->round_no);
        $this->assertTrue($fresh->response_due_at->greaterThan($firstDue));
        $this->assertSame(0, $fresh->reminder_count);
        $this->assertNull($fresh->matrix_escalated_at); // the ladder resumes for the new round

        // Round 1 stays readable and unchanged (R-F).
        $roundOne = $fresh->responses()->where('round_no', 1)->first();
        $this->assertSame('Rejected', $roundOne->review_status);
        $this->assertSame('Will clear soon.', $roundOne->management_comment);
        $this->assertSame('No dated plan and no named owner.', $roundOne->rejection_reason);

        // Immutability is enforced, not hoped for.
        $this->expectException(\LogicException::class);
        $roundOne->update(['management_comment' => 'Rewriting history']);

        Carbon::setTestNow();
    }

    // ── The business-day SLA clock ───────────────────────────────────

    public function test_is_late_is_true_one_business_day_past_due_and_false_at_2359_tenant_time(): void
    {
        Notification::fake();

        // Monday 09:00 Lagos.
        Carbon::setTestNow(Carbon::parse('2026-08-17 09:00:00', 'Africa/Lagos'));

        $exception = $this->makeException();
        [$escalation] = $this->issueTo($exception, [$this->unitTarget($this->ops)]);

        // 5 business days from Monday = next Monday, end of day Lagos time.
        $dueLagos = $escalation->response_due_at->copy()->setTimezone('Africa/Lagos');
        $this->assertSame('2026-08-24', $dueLagos->toDateString());

        // Submitted on the due date at 23:59 tenant time → ON time.
        Carbon::setTestNow(Carbon::parse('2026-08-24 23:59:00', 'Africa/Lagos'));
        $onTime = $this->submitResponse($escalation, $this->owner);
        $this->assertFalse($onTime->is_late);

        // A second escalation submitted one business day past due → late.
        [$second] = $this->issueTo(
            $this->makeException(),
            [$this->unitTarget($this->treasury)],
        );
        Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00', 'Africa/Lagos'));
        $late = $this->submitResponse($second, $this->owner2);
        $this->assertTrue($late->is_late);

        Carbon::setTestNow();
    }

    public function test_the_sla_clock_skips_weekends_and_a_seeded_public_holiday_in_the_tenant_timezone(): void
    {
        Notification::fake();

        // Nigerian public holiday inside the response window.
        PublicHoliday::withoutGlobalScopes()->create([
            'jurisdiction' => 'NG',
            'holiday_date' => '2026-08-19',
            'name' => 'Test Holiday',
            'is_recurring' => false,
        ]);

        // Monday 17 Aug, 09:00 Lagos. 5 business days skipping Wed 19 Aug
        // and the weekend = Tuesday 25 Aug.
        Carbon::setTestNow(Carbon::parse('2026-08-17 09:00:00', 'Africa/Lagos'));

        $exception = $this->makeException();
        [$escalation] = $this->issueTo($exception, [$this->unitTarget($this->ops)]);

        $dueLagos = $escalation->response_due_at->copy()->setTimezone('Africa/Lagos');
        $this->assertSame('2026-08-25', $dueLagos->toDateString());
        $this->assertSame('23:59', $dueLagos->format('H:i'));

        Carbon::setTestNow();
    }

    public function test_changing_the_sla_policy_changes_the_computed_due_date(): void
    {
        Notification::fake();

        ExceptionSlaPolicy::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->tenant->id)
            ->where('severity', 'High')
            ->update(['respond_within_days' => 15]);

        Carbon::setTestNow(Carbon::parse('2026-08-17 09:00:00', 'Africa/Lagos'));

        $exception = $this->makeException();
        [$escalation] = $this->issueTo($exception, [$this->unitTarget($this->ops)]);

        // 15 business days from Monday 17 Aug = Monday 7 Sep. Nothing in PHP
        // knows the number — the table does (R1/R-E).
        $this->assertSame(
            '2026-09-07',
            $escalation->response_due_at->copy()->setTimezone('Africa/Lagos')->toDateString(),
        );

        Carbon::setTestNow();
    }

    // ── R-H: the secure link ─────────────────────────────────────────

    private function makeExternalEscalation(): array
    {
        $exception = $this->makeException();
        [$escalation] = $this->issueTo($exception, [[
            'target_type' => 'external_party',
            'external_name' => 'Kunle Processor',
            'external_email' => 'ops@processor.example',
            'external_organisation' => 'Card Processor Ltd',
            'issue_note' => 'Confirm the settlement file gaps and commit to a remediation plan.',
            'required_response' => 'both',
        ]]);

        $this->actingAs($this->officer);

        return [$escalation, app(ExceptionResponseService::class)->createSecureLink($escalation, $this->officer, now()->addDays(7), notify: false)];
    }

    public function test_secure_link_grants_one_escalation_expires_revokes_logs_access_and_is_rate_limited(): void
    {
        Notification::fake();

        [$escalationA, $linkA] = $this->makeExternalEscalation();

        $this->post(route('logout'));

        // The plaintext token was never persisted.
        $this->assertDatabaseMissing('exception_response_links', ['token_hash' => $linkA['token']]);
        $this->assertSame(ExceptionResponseLink::hashToken($linkA['token']), $linkA['link']->token_hash);

        // Valid token: sees exactly this escalation, and access is logged.
        $this->get(route('public.exception-response.show', ['token' => $linkA['token']]))->assertOk();
        $fresh = $linkA['link']->fresh();
        $this->assertSame(1, $fresh->access_count);
        $this->assertNotNull($fresh->ip_address);
        $this->assertNotNull($fresh->last_accessed_at);

        // A valid token for escalation A cannot read or answer escalation B —
        // the token resolves to its own escalation, and a forged token 404s.
        [$escalationB] = $this->makeExternalEscalation();
        $this->post(route('logout'));
        $this->get(route('public.exception-response.show', ['token' => str_repeat('x', 48)]))->assertNotFound();

        // Answering through A's link writes A's thread, never B's.
        $this->post(route('public.exception-response.submit', ['token' => $linkA['token']]), [
            'responder_name' => 'Kunle Processor',
            'responder_email' => 'ops@processor.example',
            'position' => 'Accepted',
            'management_comment' => 'Gap confirmed; new settlement job deployed.',
            'root_cause' => 'A cron misconfiguration on our side.',
            'agreed_action_plan' => 'Deploy the fixed job and reconcile the missed files.',
        ])->assertRedirect();

        $this->assertSame(1, $escalationA->fresh()->responses()->count());
        $this->assertSame(0, $escalationB->fresh()->responses()->count());
        $this->assertSame('secure_link', $escalationA->fresh()->responses()->first()->channel);
        $this->assertNotNull($escalationA->fresh()->responses()->first()->ip_address);
        $this->assertSame('used', $linkA['link']->fresh()->status);

        // Expired → denied.
        [, $expired] = $this->makeExternalEscalation();
        $expired['link']->update(['expires_at' => now()->subMinute()]);
        $this->post(route('logout'));
        $this->get(route('public.exception-response.show', ['token' => $expired['token']]))->assertStatus(410);
        $this->assertSame('expired', $expired['link']->fresh()->status);

        // Revoked → denied.
        [, $revoked] = $this->makeExternalEscalation();
        app(ExceptionResponseService::class)->revokeLink($revoked['link'], $this->officer);
        $this->post(route('logout'));
        $this->get(route('public.exception-response.show', ['token' => $revoked['token']]))->assertStatus(410);
    }

    public function test_brute_forcing_the_token_endpoint_is_rate_limited(): void
    {
        $this->post(route('logout'));

        foreach (range(1, 20) as $i) {
            $this->get(route('public.exception-response.show', ['token' => 'guess-'.$i]))->assertNotFound();
        }

        $this->get(route('public.exception-response.show', ['token' => 'guess-21']))->assertStatus(429);
    }

    // ── CR1.6: the chase engine ──────────────────────────────────────

    public function test_chase_run_twice_in_a_day_sends_exactly_one_reminder_set(): void
    {
        Notification::fake();

        Carbon::setTestNow(Carbon::parse('2026-08-17 09:00:00', 'Africa/Lagos'));

        $exception = $this->makeException();
        [$escalation] = $this->issueTo($exception, [$this->unitTarget($this->ops)]);

        // Move to the due date itself (offset 0 is in the policy).
        Carbon::setTestNow(Carbon::parse('2026-08-24 08:00:00', 'Africa/Lagos'));

        $this->artisan('exceptions:chase')->assertSuccessful();
        $this->artisan('exceptions:chase')->assertSuccessful();

        $fresh = $escalation->fresh();
        $this->assertSame(1, $fresh->reminder_count);
        $this->assertSame(1, $exception->activities()->where('activity_type', 'Reminder Sent')->count());

        // Past due + escalate_after_days: the escalation is handed to the
        // FR-8 tier ladder and the parent exception flagged.
        Carbon::setTestNow(Carbon::parse('2026-08-27 08:00:00', 'Africa/Lagos'));
        $this->artisan('exceptions:chase')->assertSuccessful();

        $fresh = $escalation->fresh();
        $this->assertTrue($fresh->is_response_late);
        $this->assertNotNull($fresh->matrix_escalated_at);
        $this->assertTrue($exception->fresh()->is_response_overdue);

        Carbon::setTestNow();
    }

    // ── R-G: loud failure ────────────────────────────────────────────

    public function test_an_issue_with_no_resolvable_respondent_fails_with_a_validation_error_and_creates_nothing(): void
    {
        Notification::fake();

        $orphanUnit = OrganisationUnit::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'ORPH1',
            'name' => 'Orphan Unit',
            'type' => 'Department',
        ]);

        $exception = $this->makeException();

        try {
            $this->issueTo($exception, [
                $this->unitTarget($this->ops),
                ['target_type' => 'unit', 'unit_id' => $orphanUnit->id, 'issue_note' => 'Who answers this?'],
            ]);
            $this->fail('An escalation was created with nobody to answer it.');
        } catch (ValidationException) {
            // expected
        }

        // The WHOLE issue failed — not even the resolvable target was written.
        $this->assertSame(0, ExceptionEscalation::withoutGlobalScopes()->where('exception_id', $exception->id)->count());
    }

    // ── CR1.2: the unmuteable issue notice ───────────────────────────

    public function test_the_issued_notification_cannot_be_disabled_in_preferences(): void
    {
        Notification::fake();

        foreach (['in_app', 'email'] as $channel) {
            NotificationPreference::withoutGlobalScope('tenant')->create([
                'tenant_id' => $this->tenant->id,
                'user_id' => $this->owner->id,
                'event_key' => 'exception.escalation.issued',
                'channel' => $channel,
                'is_enabled' => false,
            ]);
        }

        $exception = $this->makeException();
        $this->issueTo($exception, [$this->unitTarget($this->ops)]);

        Notification::assertSentTo($this->owner, ExceptionEscalationIssuedNotification::class);
    }

    // ── R-I: tenant isolation ────────────────────────────────────────

    public function test_tenant_isolation_on_the_new_tables(): void
    {
        Notification::fake();

        $exception = $this->makeException();
        [$escalation] = $this->issueTo($exception, [$this->unitTarget($this->ops)]);
        $this->submitResponse($escalation, $this->owner);

        $outsider = $this->makeUser('outsider@other.local', 'Control Function Head', $this->otherTenant);

        $this->actingAs($outsider);

        $this->assertSame(0, ExceptionEscalation::count());
        $this->assertSame(0, ExceptionResponse::count());
        $this->assertSame(0, ExceptionAction::count());
        $this->assertSame(0, ExceptionSlaPolicy::where('tenant_id', $this->tenant->id)->count());

        // Route-level: the other tenant's head of control cannot open it.
        $this->get(route('exception-manager.show', $escalation))->assertNotFound();
    }

    // ── The audit trail ──────────────────────────────────────────────

    public function test_an_audit_trail_row_exists_for_every_act_in_the_loop(): void
    {
        Notification::fake();

        $exception = $this->makeException();
        [$escalation] = $this->issueTo($exception, [$this->unitTarget($this->ops)]);

        // acknowledge → respond → reject/reissue → respond → accept →
        // complete → close; a second escalation is withdrawn.
        $this->actingAs($this->owner)->get(route('exception-manager.respond', $escalation))->assertOk();
        $rejected = $this->submitResponse($escalation, $this->owner, ['management_comment' => 'Round one — thin answer under review.']);

        $this->actingAs($this->cfh);
        app(ExceptionEscalationService::class)->rejectResponse($escalation->fresh(), $rejected, $this->cfh, 'Not enough.');

        $accepted = $this->submitResponse($escalation->fresh(), $this->owner);
        $this->actingAs($this->cfh);
        app(ExceptionEscalationService::class)->acceptResponse($escalation->fresh(), $accepted, $this->cfh);

        $action = $escalation->actions()->first();
        app(ExceptionResponseService::class)->completeAction($action, $this->owner);
        app(ExceptionEscalationService::class)->close($escalation->fresh(), $this->cfh, 'Effective');

        [$second] = $this->issueTo($exception, [$this->unitTarget($this->treasury)]);
        $this->actingAs($this->cfh);
        app(ExceptionEscalationService::class)->withdraw($second, $this->cfh, 'Issued in error.');

        $escalationActions = AuditTrail::withoutGlobalScopes()
            ->where('entity_type', ExceptionEscalation::class)
            ->pluck('action');

        foreach (['issued', 'acknowledged', 'responded', 'response_rejected', 'reissued', 'response_accepted', 'closed', 'withdrawn'] as $act) {
            $this->assertContains($act, $escalationActions->all(), "Missing audit trail row for '{$act}'.");
        }
    }
}
