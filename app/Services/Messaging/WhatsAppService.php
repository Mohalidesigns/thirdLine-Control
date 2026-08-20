<?php

namespace App\Services\Messaging;

use App\Models\MessageLog;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Models\WhatsappTemplate;
use App\Services\Messaging\Exceptions\BlockedContentException;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Business Cloud API driver (15.3).
 *
 * Three rules live here:
 *  - Notification-and-link only: every payload passes OutboundContentGuard
 *    before any HTTP call (NDPA — content traverses Meta infrastructure).
 *  - The 24-hour window: a free-form text is only sent inside the session
 *    window opened by the recipient's last inbound message; outside it,
 *    only a Meta-approved template goes out.
 *  - Unconfigured is not an error: with no credentials in .env every send
 *    records a `skipped` log row and the business operation continues.
 */
class WhatsAppService
{
    public function __construct(private OutboundContentGuard $guard) {}

    public function isConfigured(): bool
    {
        return (bool) (config('messaging.whatsapp.access_token')
            && config('messaging.whatsapp.phone_number_id'));
    }

    /**
     * Send a templated notification. $variables fill the template body;
     * $link is the deep link back into the platform (the only substance a
     * WhatsApp message ever carries).
     */
    public function send(
        int $tenantId,
        ?User $user,
        string $phone,
        string $templateKey,
        array $variables = [],
        ?string $link = null,
    ): MessageLog {
        $log = fn (array $attributes) => MessageLog::create([
            'tenant_id' => $tenantId,
            'user_id' => $user?->id,
            'channel' => 'whatsapp',
            'direction' => 'out',
            'template_key' => $templateKey,
            'recipient_masked' => MessageLog::mask($phone),
            ...$attributes,
        ]);

        try {
            $this->guard->assert($variables + ($link !== null ? ['link' => $link] : []), $link);
        } catch (BlockedContentException $e) {
            return $log(['status' => 'skipped', 'error' => $e->getMessage()]);
        }

        $template = WhatsappTemplate::forKey($templateKey, $tenantId);
        $window = $this->sessionOpen($tenantId, $phone) ? 'session' : 'template';

        // Outside the 24-hour window Meta only accepts approved templates.
        if ($window === 'template' && ! $template?->isSendable()) {
            return $log([
                'status' => 'skipped',
                'window' => $window,
                'error' => $template
                    ? "Template '{$templateKey}' is not approved by Meta (status: {$template->approval_status})."
                    : "No template found for key '{$templateKey}'.",
            ]);
        }

        if (! $this->isConfigured()) {
            return $log([
                'status' => 'skipped',
                'window' => $window,
                'error' => 'WhatsApp channel not configured — set WHATSAPP_ACCESS_TOKEN and WHATSAPP_PHONE_NUMBER_ID.',
            ]);
        }

        $body = $template?->render([...$variables, 'link' => $link]) ?? '';

        $payload = $window === 'template'
            ? $this->templatePayload($phone, $template, $variables, $link)
            : ['messaging_product' => 'whatsapp', 'to' => $phone, 'type' => 'text', 'text' => ['body' => $body]];

        $response = Http::withToken(config('messaging.whatsapp.access_token'))
            ->acceptJson()
            ->post(rtrim(config('messaging.whatsapp.base_url'), '/')
                .'/'.config('messaging.whatsapp.phone_number_id').'/messages', $payload);

        if ($response->failed()) {
            return $log([
                'status' => 'failed',
                'window' => $window,
                'error' => 'HTTP '.$response->status().': '.substr($response->body(), 0, 500),
            ]);
        }

        return $log([
            'status' => 'sent',
            'window' => $window,
            'provider_message_id' => $response->json('messages.0.id'),
        ]);
    }

    /** Is the recipient's 24-hour customer-service window still open? */
    public function sessionOpen(int $tenantId, string $phone): bool
    {
        $lastInbound = WhatsappSession::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('phone_hash', WhatsappSession::hashPhone($phone))
            ->value('last_inbound_at');

        return $lastInbound !== null
            && now()->diffInHours($lastInbound, true) < (int) config('messaging.whatsapp.session_window_hours', 24);
    }

    /** An inbound message (re)opens the sender's session window. */
    public function recordInbound(int $tenantId, string $phone): void
    {
        WhatsappSession::withoutGlobalScope('tenant')->updateOrCreate(
            ['tenant_id' => $tenantId, 'phone_hash' => WhatsappSession::hashPhone($phone)],
            ['last_inbound_at' => now()],
        );
    }

    /**
     * Pull each template's review state from Meta so the admin screen shows
     * what is actually sendable. Matches on template name.
     *
     * @return int templates updated
     */
    public function syncTemplateStatuses(): int
    {
        $wabaId = config('messaging.whatsapp.business_account_id');

        if (! $wabaId || ! config('messaging.whatsapp.access_token')) {
            return 0;
        }

        $response = Http::withToken(config('messaging.whatsapp.access_token'))
            ->acceptJson()
            ->get(rtrim(config('messaging.whatsapp.base_url'), '/')."/{$wabaId}/message_templates", [
                'fields' => 'id,name,status,language',
                'limit' => 200,
            ]);

        if ($response->failed()) {
            return 0;
        }

        $updated = 0;

        foreach ($response->json('data', []) as $remote) {
            $status = match (strtoupper((string) ($remote['status'] ?? ''))) {
                'APPROVED' => 'approved',
                'REJECTED' => 'rejected',
                default => 'pending',
            };

            $updated += WhatsappTemplate::query()
                ->where('name', $remote['name'] ?? '')
                ->when(isset($remote['language']), fn ($q) => $q->where('language', $remote['language']))
                ->update([
                    'meta_template_id' => $remote['id'] ?? null,
                    'approval_status' => $status,
                    'synced_at' => now(),
                ]);
        }

        return $updated;
    }

    /** Constant-time check of Meta's X-Hub-Signature-256 webhook header. */
    public function verifySignature(string $rawPayload, ?string $signatureHeader): bool
    {
        $secret = config('messaging.whatsapp.app_secret');

        if (! $secret || ! $signatureHeader) {
            return false;
        }

        return hash_equals(
            'sha256='.hash_hmac('sha256', $rawPayload, $secret),
            $signatureHeader,
        );
    }

    private function templatePayload(string $phone, WhatsappTemplate $template, array $variables, ?string $link): array
    {
        $parameters = collect($template->variables ?? [])
            ->map(fn (string $name) => [
                'type' => 'text',
                'text' => (string) ($name === 'link' ? ($link ?? '') : ($variables[$name] ?? '')),
            ])
            ->values()
            ->all();

        return [
            'messaging_product' => 'whatsapp',
            'to' => $phone,
            'type' => 'template',
            'template' => [
                'name' => $template->name,
                'language' => ['code' => $template->language],
                'components' => $parameters === [] ? [] : [
                    ['type' => 'body', 'parameters' => $parameters],
                ],
            ],
        ];
    }
}
