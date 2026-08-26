<?php

namespace Tests\Feature;

use App\Models\MessageLog;
use App\Models\SpeakUpCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\Messaging\WhatsAppService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The WhatsApp anonymising bridge (15.3, NCCG RP 19 / CBN whistleblowing
 * guidance): a Speak-Up report arriving over an identity-bearing channel
 * must shed that identity BEFORE persistence — and the webhook must not
 * be spoofable, because an intake anyone can forge is worse than none.
 */
class AnonymisingBridgeTest extends TestCase
{
    use RefreshDatabase;

    private const REPORTER_PHONE = '2348099887766';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        // The intake allowlist fallback needs someone to route to.
        $cfh = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $cfh->assignRole('Control Function Head');

        config()->set('messaging.whatsapp.app_secret', 'hook-secret');
    }

    private function inboundPayload(string $text): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => '111222333'],
                        'messages' => [[
                            'from' => self::REPORTER_PHONE,
                            'id' => 'wamid.inbound.1',
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function signedPost(array $payload)
    {
        $body = json_encode($payload);

        return $this->call('POST', route('webhooks.whatsapp'), [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'hook-secret'),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    // ── The bridge strips identity before persistence ────────────────

    public function test_a_speak_up_report_via_whatsapp_persists_with_no_trace_of_the_phone_number(): void
    {
        $this->signedPost($this->inboundPayload('REPORT Cash suppression at the Ikeja branch'))->assertOk();

        $case = SpeakUpCase::withoutGlobalScopes()->firstOrFail();

        $this->assertTrue($case->is_anonymous);
        $this->assertTrue($case->was_anonymised);
        $this->assertNull($case->reporter_id);
        $this->assertSame('whatsapp', $case->channel);
        $this->assertStringContainsString('Cash suppression', $case->description);
        $this->assertStringContainsString('stripped at the bridge', $case->anonymisation_note);

        // No trace of the number in ANY column of the case or message log —
        // not masked, not hashed alongside the case, nowhere.
        foreach (DB::table('cases')->get() as $row) {
            $this->assertStringNotContainsString(self::REPORTER_PHONE, json_encode($row));
            $this->assertStringNotContainsString(substr(self::REPORTER_PHONE, -6), json_encode($row));
        }

        $log = MessageLog::withoutGlobalScope('tenant')->where('was_anonymised', true)->firstOrFail();
        $this->assertNull($log->recipient_masked);
        $this->assertSame('anonymised', $log->status);
        $this->assertStringNotContainsString(self::REPORTER_PHONE, json_encode($log->getAttributes()));
    }

    public function test_the_intake_keyword_is_tenant_configurable(): void
    {
        $this->tenant->update(['settings' => ['cases' => ['whatsapp_intake_keyword' => 'SPEAKUP']]]);

        $this->signedPost($this->inboundPayload('SPEAKUP Procurement kickbacks'))->assertOk();

        $this->assertSame(1, SpeakUpCase::withoutGlobalScopes()->count());
    }

    // ── Signature verification ───────────────────────────────────────

    public function test_an_unsigned_webhook_payload_is_rejected_and_persists_nothing(): void
    {
        $body = json_encode($this->inboundPayload('REPORT Forged'));

        $this->call('POST', route('webhooks.whatsapp'), [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'wrong-secret'),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertForbidden();

        $this->assertSame(0, SpeakUpCase::withoutGlobalScopes()->count());
        $this->assertSame(0, MessageLog::withoutGlobalScope('tenant')->count());
    }

    public function test_verification_handshake_echoes_the_challenge_only_with_the_right_token(): void
    {
        config()->set('messaging.whatsapp.webhook_verify_token', 'verify-me');

        $this->get(route('webhooks.whatsapp.verify', [
            'hub_mode' => 'subscribe', 'hub_verify_token' => 'verify-me', 'hub_challenge' => '12345',
        ]))->assertOk()->assertSee('12345');

        $this->get(route('webhooks.whatsapp.verify', [
            'hub_mode' => 'subscribe', 'hub_verify_token' => 'nope', 'hub_challenge' => '12345',
        ]))->assertForbidden();
    }

    // ── Ordinary inbound: session window without a directory ─────────

    public function test_a_plain_reply_opens_the_session_window_storing_only_a_hash_and_a_mask(): void
    {
        $this->signedPost($this->inboundPayload('Thanks, received.'))->assertOk();

        $this->assertSame(0, SpeakUpCase::withoutGlobalScopes()->count());

        $session = WhatsappSession::withoutGlobalScope('tenant')->firstOrFail();
        $this->assertSame(WhatsappSession::hashPhone(self::REPORTER_PHONE), $session->phone_hash);

        $log = MessageLog::withoutGlobalScope('tenant')->firstOrFail();
        $this->assertSame('***766', $log->recipient_masked);
        $this->assertStringNotContainsString(self::REPORTER_PHONE, json_encode($log->getAttributes()));

        $this->assertTrue(app(WhatsAppService::class)->sessionOpen($this->tenant->id, self::REPORTER_PHONE));
    }

    public function test_delivery_statuses_update_the_message_log(): void
    {
        MessageLog::create([
            'tenant_id' => $this->tenant->id,
            'channel' => 'whatsapp',
            'direction' => 'out',
            'status' => 'sent',
            'provider_message_id' => 'wamid.out.9',
        ]);

        $this->signedPost([
            'entry' => [[
                'changes' => [[
                    'value' => ['statuses' => [['id' => 'wamid.out.9', 'status' => 'delivered']]],
                ]],
            ]],
        ])->assertOk();

        $log = MessageLog::withoutGlobalScope('tenant')->where('provider_message_id', 'wamid.out.9')->first();
        $this->assertSame('delivered', $log->status);
        $this->assertNotNull($log->delivered_at);
    }
}
