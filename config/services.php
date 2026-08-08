<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Anthropic (Phase 14 — AI layer)
    |--------------------------------------------------------------------------
    |
    | The key lives in .env and is read only from here (R9). It is never
    | written to a seeder, a test, a prompt, a log line or a client bundle —
    | AiGateway redacts it from every exception message it re-throws, and
    | AnthropicClient is the only class that ever reads it.
    |
    | Model identifiers were verified against Anthropic's model documentation
    | at build time (2026-08). The prompt pack's draft named `claude-*-4-6`
    | identifiers; the current generation is Claude Opus 5 / Sonnet 5, with
    | Haiku 4.5 as the fast tier (there is no Haiku 4.6). These are only the
    | installation defaults — the operative model per capability is a row in
    | ai_configurations, so a tenant can pin an older model without a deploy.
    |
    | `sampling_models` exists because Opus 5, Sonnet 5, Opus 4.7/4.8 and
    | Fable 5 reject `temperature` with a 400. ai_configurations still carries
    | a temperature column (the spec's data model), but the gateway only puts
    | it on the wire for a model listed here. Empty by default: send nothing.
    |
    */

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),

        'default_model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
        'reasoning_model' => env('ANTHROPIC_REASONING_MODEL', 'claude-opus-5'),
        'fast_model' => env('ANTHROPIC_FAST_MODEL', 'claude-haiku-4-5'),

        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 8192),
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 120),
        'connect_timeout' => (int) env('ANTHROPIC_CONNECT_TIMEOUT', 10),
        'max_retries' => (int) env('ANTHROPIC_MAX_RETRIES', 3),
        'retry_base_ms' => (int) env('ANTHROPIC_RETRY_BASE_MS', 500),

        /*
         | Per-model capability matrix. Sending an unsupported parameter is a
         | 400, so the gateway asks this table before it builds a payload
         | rather than assuming. Unknown models fall back to `default`, which
         | sends nothing optional — the safe direction.
         */
        'features' => [
            'default' => [
                'thinking' => false,
                'effort' => false,
                'structured_output' => false,
                'temperature' => false,
            ],
            'claude-opus-5' => [
                'thinking' => true, 'effort' => true,
                'structured_output' => true, 'temperature' => false,
            ],
            'claude-sonnet-5' => [
                'thinking' => true, 'effort' => true,
                'structured_output' => true, 'temperature' => false,
            ],
            'claude-haiku-4-5' => [
                'thinking' => false, 'effort' => false,
                'structured_output' => true, 'temperature' => true,
            ],
        ],

        /*
         | Published list prices in USD per million tokens, used to cost every
         | interaction. Stored per interaction in minor units (R7). Update
         | alongside Anthropic's pricing page; a model absent here costs zero
         | rather than guessing, and the governance page flags it.
         */
        'pricing' => [
            'claude-opus-5' => ['input' => 5.00, 'output' => 25.00],
            'claude-sonnet-5' => ['input' => 3.00, 'output' => 15.00],
            'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00],
        ],
        'pricing_currency' => env('ANTHROPIC_PRICING_CURRENCY', 'USD'),
    ],

];
