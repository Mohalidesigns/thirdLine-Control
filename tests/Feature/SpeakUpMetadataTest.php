<?php

namespace Tests\Feature;

use App\Models\IntegrationConfig;
use App\Models\SpeakUpCase;
use App\Models\SpeakUpMetadataAccessLog;
use App\Models\SpeakUpReportMetadata;
use App\Models\SpeakUpRevealRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CaseService;
use App\Services\SpeakUpMetadataService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The CR's guarantees, each proven:
 *
 *   - a confidential submission persists a metadata row (nulls carry
 *     source flags, never fabricated values), the anonymous route
 *     persists nothing;
 *   - Tier 2 never leaks through any screen or API payload without an
 *     approved break-glass reveal;
 *   - reveals need a reason, a justification and a second person, and
 *     every one lands in the immutable access log;
 *   - repeat submissions correlate by fingerprint;
 *   - the notice must be acknowledged before a confidential submission
 *     is accepted;
 *   - purge honours case closure and legal hold;
 *   - the ThirdLine access-log feed carries names and reasons, never a
 *     metadata value.
 */
class SpeakUpMetadataTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $officer;

    private User $cfh;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->admin = $this->makeUser('admin@test.local', 'System Administrator');
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function clientMeta(array $overrides = []): array
    {
        return [
            'platform' => 'MacIntel',
            'screen_resolution' => '2560x1440',
            'color_depth' => 30,
            'timezone' => 'Africa/Lagos',
            'timezone_offset' => 60,
            'locale' => 'en-NG,en',
            'hardware_concurrency' => 8,
            'device_memory' => 8,
            'touch_support' => false,
            ...$overrides,
        ];
    }

    /** Open the form (server stamps time-on-form), then submit. */
    private function submitConfidential(array $overrides = []): TestResponse
    {
        $this->get(route('whistleblowing.create'));

        return $this->post(route('whistleblowing.store'), [
            'title' => 'Procurement kickbacks in branch operations',
            'description' => 'Detailed description of the concern with enough substance to assess.',
            'mode' => 'confidential',
            'notice_acknowledged' => true,
            'client_meta' => $this->clientMeta(),
            ...$overrides,
        ]);
    }

    private function latestCase(): SpeakUpCase
    {
        return SpeakUpCase::withoutGlobalScopes()->orderByDesc('id')->firstOrFail();
    }

    // ── Capture ──────────────────────────────────────────────────────────

    public function test_a_confidential_submission_persists_a_complete_metadata_row(): void
    {
        $this->submitConfidential()->assertRedirect(route('whistleblowing.submitted'));

        $case = $this->latestCase();
        $this->assertFalse($case->is_anonymous);

        $row = SpeakUpReportMetadata::withoutGlobalScopes()->where('report_id', $case->id)->first();

        $this->assertNotNull($row);
        $this->assertSame('127.0.0.1', $row->ip_address);
        $this->assertNotNull($row->fingerprint_hash);
        $this->assertSame('Africa/Lagos', $row->timezone);
        $this->assertSame('2560x1440', $row->screen_resolution);
        $this->assertFalse($row->is_authenticated);
        $this->assertNotNull($row->notice_acknowledged_at);
        $this->assertSame(1, $row->notice_version);
        $this->assertNotNull($row->purge_after);
        $this->assertNotNull($row->session_duration_seconds);

        // Unobtainable values are null with a source flag — never guessed.
        $this->assertNull($row->hostname);
        $this->assertSame('unavailable', $row->hostname_source);
        $this->assertSame('unresolved', $row->capture_sources['ip_intelligence']);

        // The IP is encrypted at rest.
        $this->assertNotSame('127.0.0.1', $row->getRawOriginal('ip_address'));
    }

    public function test_the_anonymous_route_stores_no_metadata_at_all(): void
    {
        $this->get(route('whistleblowing.create'));

        $this->post(route('whistleblowing.store'), [
            'title' => 'Anonymous concern',
            'description' => 'Something happened and I would like to stay anonymous.',
            'mode' => 'anonymous',
            // A hostile or buggy client sending metadata anyway changes nothing.
            'client_meta' => $this->clientMeta(),
        ])->assertRedirect(route('whistleblowing.submitted'));

        $case = $this->latestCase();
        $this->assertTrue($case->is_anonymous);
        $this->assertSame(0, SpeakUpReportMetadata::withoutGlobalScopes()->count());

        // Belt and braces: the service itself refuses an anonymous case.
        $captured = app(SpeakUpMetadataService::class)->capture(
            $case, request(), $this->clientMeta(), 30, 1,
        );
        $this->assertNull($captured);
        $this->assertSame(0, SpeakUpReportMetadata::withoutGlobalScopes()->count());
    }

    public function test_a_confidential_submission_without_acknowledgement_is_rejected(): void
    {
        $this->get(route('whistleblowing.create'));

        $response = $this->from(route('whistleblowing.create'))->post(route('whistleblowing.store'), [
            'title' => 'Concern without acknowledgement',
            'description' => 'The notice checkbox was never ticked.',
            'mode' => 'confidential',
            'client_meta' => $this->clientMeta(),
        ]);

        $response->assertSessionHasErrors('notice_acknowledged');
        $this->assertSame(0, SpeakUpCase::withoutGlobalScopes()->count());
    }

    public function test_an_authenticated_confidential_submission_records_the_staff_link(): void
    {
        $this->actingAs($this->officer);
        $this->submitConfidential();

        $row = SpeakUpReportMetadata::withoutGlobalScopes()->firstOrFail();

        $this->assertTrue($row->is_authenticated);
        $this->assertSame((string) $this->officer->id, $row->reporter_user_id);
    }

    public function test_disabling_capture_restores_the_legacy_anonymous_channel(): void
    {
        $this->tenant->update(['settings' => ['speak_up' => ['metadata_capture' => false]]]);

        $this->get(route('whistleblowing.create'));
        $this->post(route('whistleblowing.store'), [
            'title' => 'Legacy submission',
            'description' => 'No notice, no capture, anonymous by default.',
        ])->assertRedirect(route('whistleblowing.submitted'));

        $this->assertTrue($this->latestCase()->is_anonymous);
        $this->assertSame(0, SpeakUpReportMetadata::withoutGlobalScopes()->count());
    }

    public function test_the_anonymous_route_can_be_switched_off(): void
    {
        $this->tenant->update(['settings' => ['speak_up' => ['anonymous_mode' => false]]]);

        $this->get(route('whistleblowing.create'));
        $this->from(route('whistleblowing.create'))->post(route('whistleblowing.store'), [
            'title' => 'Attempted anonymous submission',
            'description' => 'The tenant disabled the anonymous route.',
            'mode' => 'anonymous',
        ])->assertSessionHasErrors('mode');
    }

    // ── Tier separation ──────────────────────────────────────────────────

    public function test_tier2_never_leaks_without_a_reveal(): void
    {
        $this->submitConfidential();
        $case = $this->latestCase();

        // The officer is put on the case (intake allowlisted the CFH only).
        $case->update(['access_user_ids' => [$this->officer->id]]);

        // Case screen: banner + signals, no identifying field anywhere.
        $response = $this->actingAs($this->officer)->get(route('cases.show', $case));
        $response->assertOk();
        $this->assertStringNotContainsString('127.0.0.1', $response->getContent());

        // Metadata screen without a reveal: Tier 1 only.
        $response = $this->actingAs($this->officer)->get(route('cases.metadata.show', $case));
        $response->assertOk();
        $this->assertStringNotContainsString('127.0.0.1', $response->getContent());

        // Even asking for the reveal render does nothing without approval.
        $response = $this->actingAs($this->officer)->get(route('cases.metadata.show', ['case' => $case->id, 'reveal' => 1]));
        $this->assertStringNotContainsString('127.0.0.1', $response->getContent());

        // Model serialisation hides every Tier 2 attribute.
        $serialised = SpeakUpReportMetadata::withoutGlobalScopes()->firstOrFail()->toArray();
        foreach (['ip_address', 'ip_forwarded_chain', 'hostname', 'reporter_user_id', 'isp', 'asn', 'geo_city', 'user_agent_raw'] as $field) {
            $this->assertArrayNotHasKey($field, $serialised);
        }
    }

    public function test_a_system_administrator_holds_no_metadata_tier(): void
    {
        $this->submitConfidential();
        $case = $this->latestCase();

        // Oversight sees the case, never the metadata screens.
        $this->actingAs($this->admin)->get(route('cases.show', $case))->assertOk();
        $this->actingAs($this->admin)->get(route('cases.metadata.show', $case))->assertForbidden();
        $this->assertFalse($this->admin->can('speak_up.metadata.view_basic'));
        $this->assertFalse($this->admin->can('speak_up.metadata.approve_reveal'));
        $this->assertTrue($this->admin->can('speak_up.metadata.audit_log'));
    }

    // ── Break-glass reveal ───────────────────────────────────────────────

    public function test_the_reveal_needs_a_second_person_and_writes_the_access_log(): void
    {
        $this->submitConfidential();
        $case = $this->latestCase();
        $case->update(['access_user_ids' => [$this->officer->id, $this->cfh->id]]);

        // Request: reason + justification required.
        $this->actingAs($this->officer)
            ->post(route('cases.metadata.reveal.request', $case), ['reason_code' => 'suspected_false_report'])
            ->assertSessionHasErrors('justification');

        $this->actingAs($this->officer)->post(route('cases.metadata.reveal.request', $case), [
            'reason_code' => 'suspected_false_report',
            'justification' => 'Three prior unsubstantiated reports from the same device fingerprint.',
        ])->assertSessionHasNoErrors();

        $request = SpeakUpRevealRequest::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('pending', $request->status);

        // Nobody self-approves.
        try {
            app(SpeakUpMetadataService::class)->decideReveal($request, $this->officer, true);
            $this->fail('A self-approval should have been refused.');
        } catch (ValidationException) {
            // expected
        }

        // A second person approves; the officer can now open Tier 2, and
        // the render writes the log.
        $this->actingAs($this->cfh)
            ->post(route('speak-up.reveal-requests.decide', $request), ['approve' => true])
            ->assertSessionHasNoErrors();

        $response = $this->actingAs($this->officer)
            ->get(route('cases.metadata.show', ['case' => $case->id, 'reveal' => 1]));
        $response->assertOk();
        $this->assertStringContainsString('127.0.0.1', $response->getContent());

        $actions = SpeakUpMetadataAccessLog::withoutGlobalScopes()->pluck('action');
        $this->assertContains('requested', $actions);
        $this->assertContains('approved', $actions);
        $this->assertContains('revealed', $actions);

        $reveal = SpeakUpMetadataAccessLog::withoutGlobalScopes()->where('action', 'revealed')->firstOrFail();
        $this->assertSame($this->officer->id, $reveal->requested_by);
        $this->assertSame($this->cfh->id, $reveal->approved_by);
        $this->assertSame('suspected_false_report', $reveal->reason_code);
        $this->assertContains('ip_address', $reveal->fields_revealed);
    }

    public function test_a_standalone_reveal_approver_decides_without_case_permissions(): void
    {
        $approver = $this->makeUser('dpo@test.local', 'Speak Up Reveal Approver');

        $this->submitConfidential();
        $case = $this->latestCase();
        $case->update(['access_user_ids' => [$this->officer->id]]);

        $this->actingAs($this->officer)->post(route('cases.metadata.reveal.request', $case), [
            'reason_code' => 'regulatory_request',
            'justification' => 'Formal request from the EFCC citing the case reference.',
        ]);

        $request = SpeakUpRevealRequest::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($approver)->get(route('speak-up.reveal-requests'))->assertOk();
        $this->actingAs($approver)
            ->post(route('speak-up.reveal-requests.decide', $request), ['approve' => false, 'note' => 'Scope too broad.'])
            ->assertSessionHasNoErrors();

        $this->assertSame('denied', $request->fresh()->status);

        // A denied request opens nothing.
        $response = $this->actingAs($this->officer)
            ->get(route('cases.metadata.show', ['case' => $case->id, 'reveal' => 1]));
        $this->assertStringNotContainsString('127.0.0.1', $response->getContent());
    }

    // ── Fingerprint correlation ──────────────────────────────────────────

    public function test_repeat_submissions_from_the_same_device_correlate(): void
    {
        $this->submitConfidential(['title' => 'First report']);
        $first = $this->latestCase();

        // Close the first as unsubstantiated (two hands: assess, then conclude).
        $service = app(CaseService::class);
        $this->actingAs($this->cfh);
        $first->update(['status' => 'Under Investigation', 'lead_investigator_id' => $this->cfh->id]);
        $service->conclude($first->fresh(), $this->cfh, 'Unsubstantiated', []);

        $this->submitConfidential(['title' => 'Second report']);
        $second = $this->latestCase();
        $this->assertNotSame($first->id, $second->id);

        $signals = app(SpeakUpMetadataService::class)->signals($second);

        $this->assertSame(1, $signals['prior_reports']['total']);
        $this->assertSame(1, $signals['prior_reports']['last_24h']);
        $this->assertTrue($signals['prior_reports']['previously_unsubstantiated']);
    }

    public function test_a_fast_submission_raises_the_anomaly_flag(): void
    {
        $this->submitConfidential();
        $case = $this->latestCase();

        // Time-on-form is server-computed; same-second submission is < 20s.
        $signals = app(SpeakUpMetadataService::class)->signals($case);
        $this->assertTrue($signals['anomalies']['fast_submission']);
    }

    // ── Retention ────────────────────────────────────────────────────────

    public function test_purge_deletes_expired_metadata_but_honours_open_cases_and_legal_hold(): void
    {
        $service = app(SpeakUpMetadataService::class);

        // Three confidential reports.
        foreach (['A', 'B', 'C'] as $suffix) {
            $this->submitConfidential(['title' => "Report {$suffix}"]);
        }

        [$openCase, $heldCase, $closedCase] = SpeakUpCase::withoutGlobalScopes()->orderBy('id')->get()->all();

        // All three past their provisional purge date.
        SpeakUpReportMetadata::withoutGlobalScopes()->update(['purge_after' => now()->subDay()]);

        // One closed, one closed-but-held, one still open.
        $closedCase->update(['status' => 'Closed', 'closed_at' => now()->subMonths(30)]);
        $heldCase->update(['status' => 'Closed', 'closed_at' => now()->subMonths(30)]);
        $service->setLegalHold($heldCase, $this->cfh, 'Litigation pending.');
        SpeakUpReportMetadata::withoutGlobalScopes()->update(['purge_after' => now()->subDay()]);

        $purged = $service->purgeExpired();

        $this->assertSame(1, $purged);
        $remaining = SpeakUpReportMetadata::withoutGlobalScopes()->pluck('report_id');
        $this->assertContains($openCase->id, $remaining);
        $this->assertContains($heldCase->id, $remaining);
        $this->assertNotContains($closedCase->id, $remaining);

        // The report and its history survive their metadata; deletion logged.
        $this->assertNotNull(SpeakUpCase::withoutGlobalScopes()->find($closedCase->id));
        $this->assertTrue(
            SpeakUpMetadataAccessLog::withoutGlobalScopes()
                ->where('report_id', $closedCase->id)->where('action', 'purged')->exists(),
        );
    }

    public function test_closing_a_case_restamps_the_purge_date_from_closure(): void
    {
        $this->submitConfidential();
        $case = $this->latestCase();
        $case->update(['status' => 'Received']);

        $before = SpeakUpReportMetadata::withoutGlobalScopes()->firstOrFail()->purge_after;

        $this->travel(3)->months();
        app(CaseService::class)->close(SpeakUpCase::withoutGlobalScopes()->findOrFail($case->id), $this->cfh);

        $after = SpeakUpReportMetadata::withoutGlobalScopes()->firstOrFail()->purge_after;
        $this->assertTrue($after->greaterThan($before));
        $this->travelBack();
    }

    // ── ThirdLine feed ───────────────────────────────────────────────────

    public function test_the_thirdline_access_log_feed_names_the_act_but_never_a_value(): void
    {
        $apiKey = 'slk_test_key_0123456789';
        IntegrationConfig::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'target_system' => 'ThirdLine',
            'sync_direction' => 'Bidirectional',
            'master_system' => 'SecondLine',
            'inbound_key_hash' => hash('sha256', $apiKey),
            'is_active' => true,
        ]);

        $this->submitConfidential();
        $case = $this->latestCase();
        $case->update(['access_user_ids' => [$this->officer->id]]);

        $this->actingAs($this->officer)->post(route('cases.metadata.reveal.request', $case), [
            'reason_code' => 'safety_threat',
            'justification' => 'A named individual has been threatened in connection with this report.',
        ]);
        $request = SpeakUpRevealRequest::withoutGlobalScopes()->firstOrFail();
        app(SpeakUpMetadataService::class)->decideReveal($request, $this->cfh, true);
        $this->actingAs($this->officer)->get(route('cases.metadata.show', ['case' => $case->id, 'reveal' => 1]));

        $this->app['auth']->forgetGuards();

        $response = $this->getJson('/api/v1/speak-up/metadata-access-log', ['X-Api-Key' => $apiKey]);
        $response->assertOk();

        $payload = $response->getContent();
        $this->assertStringContainsString('revealed', $payload);
        $this->assertStringContainsString($case->case_ref, $payload);
        // Field NAMES are listed; the IP value itself never crosses.
        $this->assertStringContainsString('ip_address', $payload);
        $this->assertStringNotContainsString('127.0.0.1', $payload);
    }
}
