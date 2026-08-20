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
    | Ollama (Phase 14 — AI layer)
    |--------------------------------------------------------------------------
    |
    | The provider is a locally hosted Ollama server running IBM Granite, so
    | tenant data never leaves the machine — the strongest possible reading
    | of R5. `api_key` is optional and exists only for an Ollama placed
    | behind an authenticating reverse proxy; when set it lives in .env and
    | is read only from here (R9): never written to a seeder, a test, a
    | prompt, a log line or a client bundle. AiGateway scrubs it from every
    | exception message it re-throws, and OllamaClient is the only class
    | that ever reads it.
    |
    | The installation runs one Granite model (granite4:micro), so all three
    | tiers default to it. A larger install can pull a bigger Granite for
    | the reasoning tier and point OLLAMA_REASONING_MODEL at it — these are
    | only the installation defaults; the operative model per capability is
    | a row in ai_configurations, so a tenant can pin a model without a
    | deploy.
    |
    */

    'ollama' => [
        'api_key' => env('OLLAMA_API_KEY'),
        'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),

        'default_model' => env('OLLAMA_MODEL', 'granite4:micro'),
        'reasoning_model' => env('OLLAMA_REASONING_MODEL', 'granite4:micro'),
        'fast_model' => env('OLLAMA_FAST_MODEL', 'granite4:micro'),

        'max_tokens' => (int) env('OLLAMA_MAX_TOKENS', 8192),
        // The context window requested per call. Ollama's own default is
        // small enough to silently truncate an assembled retrieval context,
        // which surfaces as the model ignoring its instructions.
        'num_ctx' => (int) env('OLLAMA_NUM_CTX', 16384),
        // Local inference on modest hardware is slow; a timeout tuned for a
        // hosted API would abandon perfectly healthy generations.
        'timeout' => (int) env('OLLAMA_TIMEOUT', 300),
        'connect_timeout' => (int) env('OLLAMA_CONNECT_TIMEOUT', 10),
        'max_retries' => (int) env('OLLAMA_MAX_RETRIES', 3),
        'retry_base_ms' => (int) env('OLLAMA_RETRY_BASE_MS', 500),

        /*
         | Per-model capability matrix. Sending an unsupported parameter is
         | an error or a silent no-op depending on the model, so the gateway
         | asks this table before it builds a payload rather than assuming.
         | Unknown models fall back to `default`, which sends nothing
         | optional — the safe direction. `thinking` is for Granite/Ollama
         | models that support the think toggle; granite4:micro does not.
         */
        'features' => [
            'default' => [
                'thinking' => false,
                'structured_output' => false,
                'temperature' => false,
            ],
            'granite4:micro' => [
                'thinking' => false,
                'structured_output' => true,
                'temperature' => true,
            ],
        ],

        /*
         | Prices in USD per million tokens, used to cost every interaction
         | and stored per interaction in minor units (R7). Local Ollama
         | inference has no per-token invoice, so this is empty and every
         | call costs zero — but the plumbing is kept intact so a paid or
         | chargeback-metered provider is a config change, not a code one.
         */
        'pricing' => [],
        'pricing_currency' => env('OLLAMA_PRICING_CURRENCY', 'USD'),
    ],

];
