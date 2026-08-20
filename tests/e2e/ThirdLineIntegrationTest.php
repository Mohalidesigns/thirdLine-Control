<?php

namespace Tests\e2e;

use App\Models\Control;
use App\Models\IntegrationConfig;
use App\Models\IntegrationSyncLog;
use App\Services\ControlService;
use App\Services\IntegrationService;
use Database\Seeders\E2ETestSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * TC-18 — ThirdLine Internal Audit integration (FR-11.1 … FR-11.10), and
 * exit criterion 7.
 *
 * ThirdLine itself is stubbed with Http::fake(), so outbound behaviour is
 * asserted on the request that would have left the building — its URL,
 * headers and body — rather than on a mock's return value.
 */
class ThirdLineIntegrationTest extends E2ETestCase
{
    private function config(): IntegrationConfig
    {
        return IntegrationConfig::withoutGlobalScopes()
            ->where('tenant_id', $this->account('cfh')->tenant_id)
            ->where('target_system', 'ThirdLine')
            ->firstOrFail();
    }

    private function approvedControl(): Control
    {
        $control = Control::withoutGlobalScopes()
            ->where('tenant_id', $this->account('cfh')->tenant_id)
            ->where('is_template', false)
            ->firstOrFail();

        $control->forceFill([
            'status' => 'Active',
            'approved_at' => now()->subDay(),
            'current_version' => 3,
        ])->saveQuietly();

        return $control->refresh();
    }

    private function inboundPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'external_ref' => 'TL-E2E-0001',
            'last_approved_at' => now()->toIso8601String(),
            'control' => [
                'title' => 'Inbound control from ThirdLine (E2E)',
                'description' => 'Defined in ThirdLine and synchronised inbound.',
                'objective' => 'Prove FR-11.3.',
                'type' => 'Detective',
                'nature' => 'Automated',
                'frequency' => 'Quarterly',
                'is_key_control' => true,
            ],
        ], $overrides);
    }

    private function postInbound(array $payload, array $headers = []): TestResponse
    {
        return $this->withHeaders(array_merge(
            ['X-Api-Key' => E2ETestSeeder::THIRDLINE_API_KEY],
            $headers
        ))->postJson('/api/v1/controls', $payload);
    }

    // -----------------------------------------------------------------
    // TC-18-01 / TC-18-02 — outbound push and field-mapping fidelity
    // -----------------------------------------------------------------

    public function test_tc_18_01_and_02_a_control_is_published_with_the_full_contract_payload(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $control = $this->approvedControl();
        $log = app(IntegrationService::class)->publishControl($control);

        $this->assertNotNull($log, 'FR-11.1: an approved control must be published.');
        $this->assertSame('Success', $log->status);
        $this->assertSame('outbound', $log->direction);

        Http::assertSent(function ($request) use ($control) {
            $body = $request->data();

            // FR-11.5: a stable external identifier must cross both systems.
            $this->assertNotEmpty($body['external_ref'] ?? null, 'FR-11.5: external_ref must be present.');
            $this->assertSame($control->external_ref, $body['external_ref']);

            // Contract envelope.
            foreach (['tenant_id', 'external_ref', 'source_system', 'version_no', 'last_approved_at'] as $field) {
                $this->assertArrayHasKey($field, $body, "Contract field {$field} missing from the payload.");
            }
            $this->assertSame('SecondLine', $body['source_system']);

            // FR-11.2 field mapping.
            foreach (['control_ref', 'title', 'description', 'objective', 'type', 'nature', 'frequency', 'is_key_control', 'status'] as $field) {
                $this->assertArrayHasKey($field, $body['control'], "Mapped attribute {$field} missing.");
            }
            $this->assertSame($control->title, $body['control']['title']);
            $this->assertSame($control->type, $body['control']['type']);

            return str_ends_with($request->url(), '/api/v1/controls');
        });
    }

    public function test_fr_11_5_a_control_without_an_external_ref_is_given_a_stable_one(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $control = $this->approvedControl();
        $control->forceFill(['external_ref' => null])->saveQuietly();

        app(IntegrationService::class)->publishControl($control->refresh());

        $control->refresh();

        $this->assertNotNull($control->external_ref, 'FR-11.5: an external identifier must be minted on first publish.');
        $this->assertStringStartsWith('SL-', $control->external_ref);

        $first = $control->external_ref;
        app(IntegrationService::class)->publishControl($control->refresh());

        $this->assertSame($first, $control->refresh()->external_ref, 'FR-11.5: the identifier must be stable across publishes.');
    }

    public function test_fr_11_6_the_outbound_request_carries_the_api_key_and_an_idempotency_key(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->config()->forceFill(['credentials_encrypted' => Crypt::encryptString('outbound-secret-key')])->saveQuietly();

        $log = app(IntegrationService::class)->publishControl($this->approvedControl());

        Http::assertSent(function ($request) use ($log) {
            $this->assertSame('outbound-secret-key', $request->header('X-Api-Key')[0] ?? null, 'FR-11.6: the API key must be sent.');
            $this->assertSame($log->idempotency_key, $request->header('Idempotency-Key')[0] ?? null, 'FR-11.7: an idempotency key must accompany every delivery.');

            return true;
        });
    }

    /** FR-11.6 — the outbound secret must never be written into the sync log. */
    public function test_fr_11_6_secrets_are_not_written_to_the_sync_log(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->config()->forceFill(['credentials_encrypted' => Crypt::encryptString('outbound-secret-key')])->saveQuietly();

        $log = app(IntegrationService::class)->publishControl($this->approvedControl());

        $this->assertStringNotContainsString('outbound-secret-key', json_encode($log->payload), 'The outbound secret leaked into the logged payload.');
        $this->assertStringNotContainsString('outbound-secret-key', (string) $log->error_message);
    }

    // -----------------------------------------------------------------
    // TC-18-03 / TC-18-04 — sync direction is honoured in both directions
    // -----------------------------------------------------------------

    public function test_tc_18_04_a_pull_only_tenant_does_not_publish_outbound(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->config()->forceFill(['sync_direction' => 'Pull'])->saveQuietly();

        $this->assertNull(
            app(IntegrationService::class)->publishControl($this->approvedControl()),
            'TC-18-04: a Pull-configured tenant must not push.'
        );

        Http::assertNothingSent();
    }

    public function test_tc_18_04_a_push_only_tenant_refuses_inbound_writes(): void
    {
        $this->config()->forceFill(['sync_direction' => 'Push'])->saveQuietly();

        $before = Control::withoutGlobalScopes()->count();

        $this->postInbound($this->inboundPayload())->assertStatus(409);

        $this->assertSame(
            $before,
            Control::withoutGlobalScopes()->count(),
            'TC-18-04: a SecondLine-master tenant must not accept inbound control writes.'
        );
    }

    public function test_tc_18_03_bidirectional_accepts_an_inbound_control(): void
    {
        $this->config()->forceFill(['sync_direction' => 'Bidirectional'])->saveQuietly();

        $response = $this->postInbound($this->inboundPayload());
        $response->assertStatus(201);
        $this->assertSame('created', $response->json('action'));

        $control = Control::withoutGlobalScopes()->where('external_ref', 'TL-E2E-0001')->firstOrFail();

        $this->assertSame('Inbound control from ThirdLine (E2E)', $control->title);

        // TC-18-02 field-mapping fidelity. IntegrationService::ingestControl()
        // maps all of these correctly when handed the full payload — verified
        // separately — so any loss here happens in the controller.
        $this->assertSame('Detective', $control->type, 'DEF-016: inbound control type was replaced by the default.');
        $this->assertSame('Automated', $control->nature, 'DEF-016: inbound nature was replaced by the default.');
        $this->assertSame('Quarterly', $control->frequency, 'DEF-016: inbound frequency was replaced by the default.');
        $this->assertTrue((bool) $control->is_key_control, 'DEF-016: inbound key-control flag was lost.');
        $this->assertSame('Defined in ThirdLine and synchronised inbound.', $control->description, 'DEF-016: inbound description was lost.');
        $this->assertSame('Prove FR-11.3.', $control->objective, 'DEF-016: inbound objective was lost.');
        $this->assertSame('synced_from_thirdline', $control->sync_status, 'FR-11.3: inbound records must be marked as such.');
        $this->assertSame($this->account('cfh')->tenant_id, $control->tenant_id, 'Inbound must land in the key\'s tenant.');
    }

    // -----------------------------------------------------------------
    // TC-18-06 — duplicate prevention
    // -----------------------------------------------------------------

    public function test_tc_18_06_a_repeated_delivery_with_the_same_idempotency_key_is_not_reprocessed(): void
    {
        $this->config()->forceFill(['sync_direction' => 'Bidirectional'])->saveQuietly();

        $key = 'e2e-idempotency-key-0001';

        $this->postInbound($this->inboundPayload(), ['Idempotency-Key' => $key])->assertStatus(201);

        $after = Control::withoutGlobalScopes()->count();

        $repeat = $this->postInbound($this->inboundPayload(), ['Idempotency-Key' => $key]);
        $repeat->assertStatus(200);
        $this->assertSame('duplicate', $repeat->json('action'), 'FR-11.7: a repeated delivery must be recognised.');

        $this->assertSame($after, Control::withoutGlobalScopes()->count(), 'TC-18-06: a repeat delivery created a second record.');
    }

    public function test_tc_18_06_re_syncing_the_same_external_ref_updates_rather_than_duplicating(): void
    {
        $this->config()->forceFill(['sync_direction' => 'Bidirectional'])->saveQuietly();

        $this->postInbound($this->inboundPayload())->assertStatus(201);
        $after = Control::withoutGlobalScopes()->count();

        // Same external_ref, no idempotency key, newer approval — an update.
        $this->postInbound($this->inboundPayload([
            'last_approved_at' => now()->addDay()->toIso8601String(),
            'control' => ['title' => 'Renamed in ThirdLine'],
        ]))->assertStatus(201);

        $this->assertSame($after, Control::withoutGlobalScopes()->count(), 'TC-18-06: re-sync duplicated the control.');
        $this->assertSame(
            'Renamed in ThirdLine',
            Control::withoutGlobalScopes()->where('external_ref', 'TL-E2E-0001')->value('title')
        );
    }

    // -----------------------------------------------------------------
    // TC-18-05 — conflict resolution
    // -----------------------------------------------------------------

    public function test_tc_18_05_the_newer_local_version_wins_under_last_approved_at_wins(): void
    {
        $this->config()->forceFill(['sync_direction' => 'Bidirectional', 'conflict_rule' => 'last_approved_at_wins'])->saveQuietly();

        $this->postInbound($this->inboundPayload())->assertStatus(201);

        $control = Control::withoutGlobalScopes()->where('external_ref', 'TL-E2E-0001')->firstOrFail();
        $control->forceFill(['approved_at' => now()->addDays(5), 'title' => 'Locally approved, newer'])->saveQuietly();

        // ThirdLine sends an older approval.
        $response = $this->postInbound($this->inboundPayload([
            'last_approved_at' => now()->toIso8601String(),
            'control' => ['title' => 'Stale ThirdLine version'],
        ]));

        $response->assertStatus(201);
        $this->assertSame('skipped', $response->json('action'), 'TC-18-05: the older incoming version must not win.');

        $this->assertSame(
            'Locally approved, newer',
            $control->refresh()->title,
            'TC-18-05 CRITICAL: a stale ThirdLine version overwrote a newer local one.'
        );
    }

    // -----------------------------------------------------------------
    // TC-18-09 — malformed inbound payloads
    // -----------------------------------------------------------------

    public function test_tc_18_09_a_malformed_payload_is_rejected_with_no_partial_write(): void
    {
        $this->config()->forceFill(['sync_direction' => 'Bidirectional'])->saveQuietly();

        $before = Control::withoutGlobalScopes()->count();

        // Missing external_ref.
        $this->postInbound(['control' => ['title' => 'No external ref']])->assertStatus(422);

        // Missing the mandatory title.
        $this->postInbound(['external_ref' => 'TL-E2E-BAD', 'control' => ['description' => 'No title']])->assertStatus(422);

        // control not an array at all.
        $this->postInbound(['external_ref' => 'TL-E2E-BAD', 'control' => 'not-an-array'])->assertStatus(422);

        $this->assertSame(
            $before,
            Control::withoutGlobalScopes()->count(),
            'TC-18-09: a malformed payload produced a partial write.'
        );
    }

    // -----------------------------------------------------------------
    // TC-18-07 — authentication
    // -----------------------------------------------------------------

    public function test_tc_18_07_inbound_writes_require_a_valid_key(): void
    {
        $before = Control::withoutGlobalScopes()->count();

        $this->postJson('/api/v1/controls', $this->inboundPayload())->assertStatus(401);
        $this->withHeaders(['X-Api-Key' => 'wrong-key'])->postJson('/api/v1/controls', $this->inboundPayload())->assertStatus(401);

        $this->config()->forceFill(['is_active' => false])->saveQuietly();
        $this->postInbound($this->inboundPayload())->assertStatus(401);

        $this->assertSame($before, Control::withoutGlobalScopes()->count(), 'An unauthenticated caller wrote a control.');
    }

    // -----------------------------------------------------------------
    // TC-18-08 — ThirdLine unavailable
    // -----------------------------------------------------------------

    public function test_tc_18_08_an_unavailable_thirdline_fails_gracefully_and_loses_nothing(): void
    {
        Http::fake(['*' => Http::response('Service Unavailable', 503)]);

        $control = $this->approvedControl();
        $log = app(IntegrationService::class)->publishControl($control);

        $this->assertSame('Failed', $log->status, 'A 503 must be recorded as a failure.');
        $this->assertStringContainsString('503', (string) $log->error_message);
        $this->assertNotNull($log->payload, 'FR-11.7: the payload must be retained so the event is replayable.');
        $this->assertSame(1, $log->attempts);

        // The local record is untouched — publication never blocks the business operation.
        $this->assertSame('Active', $control->refresh()->status);
    }

    public function test_tc_18_08_a_connection_failure_is_caught_rather_than_thrown(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $log = app(IntegrationService::class)->publishControl($this->approvedControl());

        $this->assertSame('Failed', $log->status);
        $this->assertStringContainsString('Connection refused', (string) $log->error_message);
    }

    public function test_a_publication_failure_never_blocks_the_business_operation(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $control = $this->approvedControl();
        $control->forceFill(['status' => 'Pending Approval'])->saveQuietly();

        // approve() publishes to ThirdLine; the approval itself must still stand.
        app(ControlService::class)->approve($control->refresh(), $this->account('cfh'));

        $this->assertSame(
            'Active',
            $control->refresh()->status,
            'FR-11.1: a failed publication must not roll back the control approval.'
        );
    }

    // -----------------------------------------------------------------
    // TC-18-08 / FR-11.7 — replay
    // -----------------------------------------------------------------

    public function test_a_failed_event_can_be_replayed_and_is_linked_to_its_original(): void
    {
        // One fake with a sequence: Http::fake() appends stubs rather than
        // replacing them, so a second fake('*') would never be reached.
        Http::fake(['*' => Http::sequence()->push('down', 503)->push([], 200)]);

        $failed = app(IntegrationService::class)->publishControl($this->approvedControl());
        $this->assertSame('Failed', $failed->status);

        $replay = app(IntegrationService::class)->replay($failed->refresh());

        $this->assertSame('Success', $replay->status, 'FR-11.7: a failed event must be replayable.');
        $this->assertSame($failed->id, $replay->replayed_from_id, 'The replay must link to the original.');
    }

    /**
     * FR-11.7 / TC-18-06. A replay is a redelivery of the *same* event, so
     * it must carry the same idempotency key — that key is the only thing
     * stopping ThirdLine treating a retry as a second control.
     */
    public function test_a_replay_reuses_the_original_idempotency_key(): void
    {
        Http::fake(['*' => Http::sequence()->push('down', 503)->push([], 200)]);

        $failed = app(IntegrationService::class)->publishControl($this->approvedControl());
        $replay = app(IntegrationService::class)->replay($failed->refresh());

        $this->assertSame(
            $failed->idempotency_key,
            $replay->idempotency_key,
            'A replayed delivery was given a brand-new idempotency key. SecondLine\'s own inbound '
            .'endpoint de-duplicates on that key, so by its own contract a retry after a timeout — '
            .'where the first delivery may in fact have landed — is indistinguishable from a new event '
            .'and can be processed twice.'
        );
    }

    // -----------------------------------------------------------------
    // TC-18-10 — the sync audit trail
    // -----------------------------------------------------------------

    public function test_tc_18_10_every_sync_event_is_logged_with_direction_payload_and_outcome(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $before = IntegrationSyncLog::withoutGlobalScopes()->count();

        app(IntegrationService::class)->publishControl($this->approvedControl());

        $this->config()->forceFill(['sync_direction' => 'Bidirectional'])->saveQuietly();
        $this->postInbound($this->inboundPayload())->assertStatus(201);

        $this->assertSame($before + 2, IntegrationSyncLog::withoutGlobalScopes()->count(), 'Both directions must be logged.');

        $outbound = IntegrationSyncLog::withoutGlobalScopes()->where('direction', 'outbound')->latest('id')->first();
        $inbound = IntegrationSyncLog::withoutGlobalScopes()->where('direction', 'inbound')->latest('id')->first();

        foreach ([$outbound, $inbound] as $log) {
            $this->assertNotNull($log->payload, 'FR-11.7: a payload reference must be retained.');
            $this->assertNotNull($log->status, 'FR-11.7: an outcome must be recorded.');
            $this->assertNotNull($log->entity_type);
            $this->assertNotNull($log->attempted_at);
        }

        $this->assertSame('control', $inbound->entity_type);
        $this->assertSame('Success', $inbound->status);
    }

    public function test_sync_logs_are_tenant_scoped(): void
    {
        $foreign = IntegrationSyncLog::withoutGlobalScopes()
            ->where('tenant_id', '!=', $this->account('cfh')->tenant_id)->count();

        $this->assertSame(0, $foreign, 'Sync logs exist for another tenant in this fixture set — isolation check needs review.');
    }
}
