<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\MessageLog;
use App\Models\Tenant;
use App\Services\CaseService;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

/**
 * Meta WhatsApp Cloud API webhook (15.3): the GET verification handshake,
 * outbound delivery statuses, and inbound replies.
 *
 * THE ANONYMISING BRIDGE LIVES HERE. An inbound message that opens with
 * the tenant's Speak-Up keyword becomes a whistleblowing case through
 * CaseService::openFromAnonymisingBridge — the phone number is used once,
 * in-memory, to reply with the reporter token over the open session
 * window, and never reaches persistence in any form (NCCG RP 19, CBN
 * whistleblowing guidance). Every payload is signature-verified first; a
 * webhook that accepts unsigned traffic is an intake anyone can spoof.
 */
class WhatsappWebhookController extends Controller
{
    public function __construct(
        private WhatsAppService $whatsApp,
        private CaseService $cases,
    ) {}

    /** Meta's one-time subscription handshake. */
    public function verify(Request $request): Response
    {
        $configured = config('messaging.whatsapp.webhook_verify_token');

        if ($configured
            && $request->query('hub_mode') === 'subscribe'
            && hash_equals($configured, (string) $request->query('hub_verify_token'))) {
            return response($request->query('hub_challenge'), 200);
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request): Response
    {
        if (! $this->whatsApp->verifySignature($request->getContent(), $request->header('X-Hub-Signature-256'))) {
            return response('Invalid signature', 403);
        }

        $tenantId = $this->defaultTenantId();

        if ($tenantId === null) {
            return response('OK', 200);
        }

        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                foreach ($value['statuses'] ?? [] as $status) {
                    $this->applyStatus($status);
                }

                foreach ($value['messages'] ?? [] as $message) {
                    $this->handleInbound($tenantId, $message);
                }
            }
        }

        return response('OK', 200);
    }

    /** Delivery receipts for messages we sent. */
    private function applyStatus(array $status): void
    {
        $mapped = match ($status['status'] ?? '') {
            'sent' => 'sent',
            'delivered' => 'delivered',
            'read' => 'read',
            'failed' => 'failed',
            default => null,
        };

        if (! $mapped || empty($status['id'])) {
            return;
        }

        MessageLog::withoutGlobalScope('tenant')
            ->where('channel', 'whatsapp')
            ->where('provider_message_id', $status['id'])
            ->update([
                'status' => $mapped,
                'delivered_at' => in_array($mapped, ['delivered', 'read'], true) ? now() : null,
            ]);
    }

    private function handleInbound(int $tenantId, array $message): void
    {
        $phone = (string) ($message['from'] ?? '');
        $text = trim((string) ($message['text']['body'] ?? ''));

        if ($phone === '') {
            return;
        }

        // Every inbound (re)opens the sender's 24-hour session window.
        $this->whatsApp->recordInbound($tenantId, $phone);

        $keyword = Tenant::find($tenantId)?->settings['cases']['whatsapp_intake_keyword'] ?? 'REPORT';

        if ($text !== '' && str_starts_with(mb_strtoupper($text), mb_strtoupper($keyword))) {
            $this->bridgeToCase($tenantId, $phone, $text, $keyword);

            return;
        }

        // An ordinary reply: log it with a masked recipient so the session
        // window is explainable. The body is not kept — inbound free text
        // is not the platform's to store.
        MessageLog::create([
            'tenant_id' => $tenantId,
            'channel' => 'whatsapp',
            'direction' => 'in',
            'recipient_masked' => MessageLog::mask($phone),
            'status' => 'delivered',
        ]);
    }

    /**
     * The anonymising bridge: persist the case with no identifier, then
     * reply with the reporter token over the still-open session window.
     * The reply goes out directly — not through WhatsAppService::send —
     * so no message-log row ever carries even a masked fragment of the
     * reporter's number.
     */
    private function bridgeToCase(int $tenantId, string $phone, string $text, string $keyword): void
    {
        $body = trim(mb_substr($text, mb_strlen($keyword)));

        $result = $this->cases->openFromAnonymisingBridge([
            'case_type' => 'whistleblowing',
            'title' => 'Speak-Up report received via WhatsApp',
            'description' => $body !== '' ? $body : $text,
            'confidentiality' => 'Highly Restricted',
            'severity' => 'High',
            'subject_persons' => [],
            'access_user_ids' => [],
        ], $tenantId, 'whatsapp');

        MessageLog::create([
            'tenant_id' => $tenantId,
            'channel' => 'whatsapp',
            'direction' => 'in',
            'recipient_masked' => null,
            'status' => 'anonymised',
            'was_anonymised' => true,
            'meta' => ['note' => 'Speak-Up intake; originating number stripped at the bridge.'],
        ]);

        if ($this->whatsApp->isConfigured()) {
            Http::withToken(config('messaging.whatsapp.access_token'))
                ->acceptJson()
                ->post(rtrim(config('messaging.whatsapp.base_url'), '/')
                    .'/'.config('messaging.whatsapp.phone_number_id').'/messages', [
                        'messaging_product' => 'whatsapp',
                        'to' => $phone,
                        'type' => 'text',
                        'text' => [
                            'body' => 'Your report has been received as '.$result['case']->case_ref
                                .'. Keep this access token safe — it is shown once and lets you follow up '
                                .'anonymously at '.route('whistleblowing.status').' : '.$result['token'],
                        ],
                    ]);
        }
    }

    private function defaultTenantId(): ?int
    {
        return Tenant::where('status', 'active')->orderBy('id')->value('id');
    }
}
