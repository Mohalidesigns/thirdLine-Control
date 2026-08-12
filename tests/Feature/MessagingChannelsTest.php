<?php

namespace Tests\Feature;

use App\Models\MessageLog;
use App\Models\SmsTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappTemplate;
use App\Services\Messaging\SmsService;
use App\Services\Messaging\WebPushService;
use App\Services\Messaging\WhatsAppService;
use Database\Seeders\Phase15MessagingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WhatsApp/SMS/Push channel drivers (15.3–15.5): the no-personal-data
 * guard on every outbound body, the 24-hour session window, Meta template
 * approval gating, cost tracking in minor units, and quiet skipping when
 * a channel is unconfigured.
 */
class MessagingChannelsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase15MessagingSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'phone' => '+2348012345678',
        ]);
        $this->user->assignRole('Control Owner');

        $this->actingAs($this->user);
    }

    private function configureWhatsapp(): void
    {
        config()->set('messaging.whatsapp.access_token', 'test-token');
        config()->set('messaging.whatsapp.phone_number_id', '111222333');
        config()->set('messaging.whatsapp.app_secret', 'test-secret');
    }

    private function approveTemplate(string $key): void
    {
        WhatsappTemplate::where('key', $key)->update(['approval_status' => 'approved']);
    }

    // ── The NDPA guard: notifications and links only ─────────────────

    public function test_whatsapp_payload_with_an_account_number_is_blocked_before_any_http_call(): void
    {
        $this->configureWhatsapp();
        $this->approveTemplate('task_reminder');
        Http::fake();

        $log = app(WhatsAppService::class)->send(
            $this->tenant->id, $this->user, '+2348012345678', 'task_reminder',
            ['title' => 'Balance for account 0123456789 needs review'],
            config('app.url').'/my-tasks',
        );

        $this->assertSame('skipped', $log->status);
        $this->assertStringContainsString('digit sequence', $log->error);
        Http::assertNothingSent();
    }

    public function test_whatsapp_payload_with_an_email_address_is_blocked(): void
    {
        $this->configureWhatsapp();
        $this->approveTemplate('task_reminder');
        Http::fake();

        $log = app(WhatsAppService::class)->send(
            $this->tenant->id, $this->user, '+2348012345678', 'task_reminder',
            ['title' => 'Contact customer jane.doe@bank.com about her complaint'],
        );

        $this->assertSame('skipped', $log->status);
        Http::assertNothingSent();
    }

    public function test_outbound_links_must_point_back_into_the_platform(): void
    {
        $this->configureWhatsapp();
        $this->approveTemplate('task_reminder');
        Http::fake();

        $log = app(WhatsAppService::class)->send(
            $this->tenant->id, $this->user, '+2348012345678', 'task_reminder',
            ['title' => 'Task due'], 'https://evil.example.com/phish',
        );

        $this->assertSame('skipped', $log->status);
        Http::assertNothingSent();
    }

    public function test_clean_whatsapp_payload_contains_no_personal_data(): void
    {
        $this->configureWhatsapp();
        $this->approveTemplate('task_reminder');
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.123']]], 200)]);

        $log = app(WhatsAppService::class)->send(
            $this->tenant->id, $this->user, '+2348012345678', 'task_reminder',
            ['title' => 'Quarterly access review due'],
            config('app.url').'/my-tasks',
        );

        $this->assertSame('sent', $log->status);
        $this->assertSame('wamid.123', $log->provider_message_id);

        Http::assertSent(function ($request) {
            $body = json_encode($request->data());

            // The body carries the notification and the link — never an
            // email, an account number or a fragment of case substance.
            return ! preg_match('/\d{9,}(?!\d)/', str_replace(['2348012345678', '111222333'], '', $body))
                && ! str_contains($body, '@bank');
        });
    }

    // ── The 24-hour session window ───────────────────────────────────

    public function test_template_message_is_used_outside_the_session_window(): void
    {
        $this->configureWhatsapp();
        $this->approveTemplate('task_reminder');
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.t']]], 200)]);

        $log = app(WhatsAppService::class)->send(
            $this->tenant->id, $this->user, '+2348012345678', 'task_reminder', ['title' => 'Task due'],
        );

        $this->assertSame('template', $log->window);
        Http::assertSent(fn ($request) => ($request->data()['type'] ?? null) === 'template');
    }

    public function test_free_form_is_allowed_inside_the_session_window(): void
    {
        $this->configureWhatsapp();
        $this->approveTemplate('task_reminder');
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.s']]], 200)]);

        $service = app(WhatsAppService::class);
        $service->recordInbound($this->tenant->id, '+2348012345678');

        $log = $service->send(
            $this->tenant->id, $this->user, '+2348012345678', 'task_reminder', ['title' => 'Task due'],
        );

        $this->assertSame('session', $log->window);
        Http::assertSent(fn ($request) => ($request->data()['type'] ?? null) === 'text');
    }

    public function test_an_unapproved_template_is_never_sent_outside_the_window(): void
    {
        $this->configureWhatsapp();
        // Seeded templates are drafts — Meta has not approved them.
        Http::fake();

        $log = app(WhatsAppService::class)->send(
            $this->tenant->id, $this->user, '+2348012345678', 'task_reminder', ['title' => 'Task due'],
        );

        $this->assertSame('skipped', $log->status);
        $this->assertStringContainsString('not approved by Meta', $log->error);
        Http::assertNothingSent();
    }

    public function test_unconfigured_whatsapp_skips_quietly(): void
    {
        $this->approveTemplate('task_reminder');
        Http::fake();

        $log = app(WhatsAppService::class)->send(
            $this->tenant->id, $this->user, '+2348012345678', 'task_reminder', ['title' => 'Task due'],
        );

        $this->assertSame('skipped', $log->status);
        $this->assertStringContainsString('not configured', $log->error);
        Http::assertNothingSent();
    }

    // ── SMS: templates, segments, cost in minor units (R7) ───────────

    public function test_sms_cost_is_tracked_per_segment_in_minor_units(): void
    {
        config()->set('messaging.sms.driver', 'log');
        config()->set('messaging.sms.cost_per_segment_minor', 400);
        config()->set('messaging.sms.cost_currency', 'NGN');

        SmsTemplate::where('key', 'task_reminder')->update([
            'body' => str_repeat('Reminder — {{title}}. ', 12).'Open: {{link}}',
        ]);

        $log = app(SmsService::class)->send(
            $this->tenant->id, $this->user, '+2348012345678', 'task_reminder',
            ['title' => 'Access review'], config('app.url').'/my-tasks',
        );

        $this->assertSame('sent', $log->status);
        $this->assertGreaterThan(1, $log->segments);
        $this->assertSame($log->segments * 400, $log->cost_minor);
        $this->assertSame('NGN', $log->cost_currency);
        $this->assertSame('***678', $log->recipient_masked);
    }

    public function test_a_delivery_receipt_updates_status_and_actual_cost(): void
    {
        config()->set('messaging.sms.driver', 'log');
        config()->set('messaging.sms.receipt_token', 'receipt-secret');

        $log = app(SmsService::class)->send(
            $this->tenant->id, $this->user, '+2348012345678', 'task_reminder', ['title' => 'Due'],
        );

        $this->postJson(route('webhooks.sms.receipts', ['token' => 'receipt-secret']), [
            'message_id' => $log->provider_message_id,
            'status' => 'delivered',
            'cost_minor' => 850,
            'cost_currency' => 'NGN',
        ])->assertOk();

        $log->refresh();
        $this->assertSame('delivered', $log->status);
        $this->assertSame(850, $log->cost_minor);
        $this->assertNotNull($log->delivered_at);
    }

    public function test_receipt_webhook_rejects_a_bad_token(): void
    {
        config()->set('messaging.sms.receipt_token', 'receipt-secret');

        $this->postJson(route('webhooks.sms.receipts', ['token' => 'wrong']), [
            'message_id' => 'x', 'status' => 'delivered',
        ])->assertForbidden();
    }

    public function test_sms_guard_blocks_personal_data_too(): void
    {
        config()->set('messaging.sms.driver', 'log');

        $log = app(SmsService::class)->send(
            $this->tenant->id, $this->user, '+2348012345678', 'task_reminder',
            ['title' => 'BVN 12345678901 flagged'],
        );

        $this->assertSame('skipped', $log->status);
    }

    // ── Web Push ─────────────────────────────────────────────────────

    public function test_push_subscription_lifecycle_and_unconfigured_skip(): void
    {
        $this->postJson(route('push.subscribe'), [
            'endpoint' => 'https://push.example.com/sub/abc',
            'keys' => ['p256dh' => 'key', 'auth' => 'auth'],
        ])->assertOk();

        $this->assertDatabaseCount('push_subscriptions', 1);

        // No VAPID keys configured → skip with a logged reason, no crash.
        $sent = app(WebPushService::class)->sendToUser($this->user);

        $this->assertSame(0, $sent);
        $this->assertSame('skipped', MessageLog::withoutGlobalScope('tenant')->where('channel', 'push')->latest('id')->first()->status);

        $this->deleteJson(route('push.unsubscribe'), [
            'endpoint' => 'https://push.example.com/sub/abc',
        ])->assertOk();

        $this->assertDatabaseCount('push_subscriptions', 0);
    }
}
