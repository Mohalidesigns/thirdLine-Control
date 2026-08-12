<?php

/*
|--------------------------------------------------------------------------
| Omnichannel messaging (Phase 15)
|--------------------------------------------------------------------------
|
| WhatsApp Business Cloud API, SMS aggregator and Web Push credentials.
| Everything here is env-driven (R9) and OPTIONAL: with the credentials
| blank every channel driver reports itself unconfigured and records a
| `skipped` message-log row instead of failing the business operation —
| the channels are wired now, switched on later by filling in .env.
|
| HARD RULES the drivers enforce (not this file):
|  - WhatsApp/SMS carry a notification and a link, never the substance
|    (NDPA — message content traverses third-party infrastructure).
|  - WhatsApp is never an identity-bearing whistleblowing channel: the
|    inbound webhook strips the phone number through the anonymising
|    bridge before anything is persisted.
*/

return [

    'whatsapp' => [
        // Meta WhatsApp Business Cloud API. Template messages must be
        // pre-approved by Meta; sync_status pulls each template's state.
        'base_url' => env('WHATSAPP_BASE_URL', 'https://graph.facebook.com/v21.0'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        // Shared secret Meta signs webhook payloads with (X-Hub-Signature-256).
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        // Token echoed back during Meta's webhook verification handshake.
        'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        // Free-form replies are only allowed inside this many hours after
        // the recipient's last inbound message; outside it, template only.
        'session_window_hours' => (int) env('WHATSAPP_SESSION_WINDOW_HOURS', 24),
    ],

    'sms' => [
        // 'log' writes to the message log without an HTTP call — the safe
        // default until an aggregator account exists.
        'driver' => env('SMS_DRIVER', 'log'),
        'sender_id' => env('SMS_SENDER_ID', 'SecondLine'),
        // Cost per SMS segment in minor units (R7) — used for per-tenant
        // cost tracking; delivery receipts refine it when the aggregator
        // reports actual cost.
        'cost_per_segment_minor' => (int) env('SMS_COST_PER_SEGMENT_MINOR', 400),
        'cost_currency' => env('SMS_COST_CURRENCY', 'NGN'),

        'termii' => [
            'base_url' => env('TERMII_BASE_URL', 'https://api.ng.termii.com'),
            'api_key' => env('TERMII_API_KEY'),
        ],

        'africastalking' => [
            'base_url' => env('AFRICASTALKING_BASE_URL', 'https://api.africastalking.com'),
            'api_key' => env('AFRICASTALKING_API_KEY'),
            'username' => env('AFRICASTALKING_USERNAME'),
        ],

        // Shared secret the aggregator includes on delivery-receipt
        // callbacks; blank disables the receipt webhook.
        'receipt_token' => env('SMS_RECEIPT_TOKEN'),
    ],

    'push' => [
        // VAPID key pair for Web Push. Generate once per installation:
        // openssl ecparam -genkey -name prime256v1. Public key ships to the
        // browser; the private key never leaves the server.
        'vapid_public_key' => env('VAPID_PUBLIC_KEY'),
        'vapid_private_key' => env('VAPID_PRIVATE_KEY'),
        'vapid_subject' => env('VAPID_SUBJECT', 'mailto:ops@example.com'),
        'ttl' => (int) env('PUSH_TTL', 86400),
    ],

    'ussd' => [
        // USSD attestation flow (15.4) — behind the `ussd` feature flag AND
        // this shared secret; both must be set for the webhook to answer.
        'webhook_token' => env('USSD_WEBHOOK_TOKEN'),
    ],

];
