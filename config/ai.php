<?php

use App\Models\BusinessProcess;
use App\Models\CheckResult;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\Document;
use App\Models\FrameworkRequirement;
use App\Models\Incident;
use App\Models\Policy;
use App\Models\RegulatoryChange;
use App\Models\RegulatoryObligation;
use App\Models\ReportRun;
use App\Models\Risk;
use App\Models\SubmissionPack;

/*
|--------------------------------------------------------------------------
| AI layer — Phase 14
|--------------------------------------------------------------------------
|
| Everything an operator might need to change without a deploy lives here or
| in ai_configurations / ai_prompts (R1). This file holds the shape of the
| layer; the tenant's rows hold its settings.
|
| Nothing in here is a credential. The Anthropic key lives in
| config/services.php, read from .env only (R9).
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Capabilities
    |--------------------------------------------------------------------------
    |
    | The nine capabilities of Part C §C.6. Each one names:
    |
    |   permission  the existing domain permission a user must already hold.
    |               AI never widens anyone's reach: drafting a control needs
    |               'create controls' exactly as writing one by hand does.
    |   tier        which configured model tier to use by default — fast for
    |               classification and extraction, default for drafting and
    |               review, reasoning for cross-framework and multi-document
    |               analysis.
    |   subject     the domain model an interaction attaches to, so the audit
    |               trail can answer "what did AI touch on this record".
    |   sources     which knowledge sources retrieval may draw on.
    |   rate        per-user requests per minute for this capability.
    |
    | Disabling a capability for a tenant is an ai_configurations row, not an
    | edit here.
    |
    */

    'capabilities' => [

        'control.draft' => [
            'label' => 'Control drafting & RCM generation',
            'description' => 'Drafts a control objective, description, attributes, evidence requirements and a test script from a risk, process or framework requirement.',
            'permission' => 'create controls',
            'tier' => 'default',
            'subject' => [Risk::class, BusinessProcess::class, FrameworkRequirement::class],
            'sources' => ['control', 'risk', 'framework_requirement', 'obligation'],
            'rate' => 10,
        ],

        'regulatory.parse' => [
            'label' => 'Regulatory intelligence',
            'description' => 'Parses a circular or directive into a draft regulatory change with proposed obligations, diffs against the existing register and an impact list.',
            'permission' => 'assess regulatory-changes',
            'tier' => 'reasoning',
            'subject' => [RegulatoryChange::class],
            'sources' => ['obligation', 'control', 'framework_requirement'],
            'rate' => 5,
        ],

        'risk.intelligence' => [
            'label' => 'Risk intelligence',
            'description' => 'Drafts risk statements from incidents, complaints and losses; suggests causes, consequences and KRIs; surfaces emerging themes and register gaps.',
            'permission' => 'create risks',
            'tier' => 'default',
            'subject' => [Risk::class, Incident::class],
            'sources' => ['risk', 'incident', 'complaint', 'control'],
            'rate' => 10,
        ],

        'evidence.review' => [
            'label' => 'Test evidence review',
            'description' => 'Assesses whether attached evidence supports a check item\'s assertion and flags missing, stale, illegible or contradictory evidence. Advisory only.',
            'permission' => 'execute tests',
            'tier' => 'default',
            'subject' => [CheckResult::class],
            'sources' => ['control'],
            'rate' => 30,
        ],

        'exception.triage' => [
            'label' => 'Exception triage & root cause',
            'description' => 'Classifies an exception, clusters recurrences, proposes a root cause and drafts a remediation plan.',
            'permission' => 'remediate exceptions',
            'tier' => 'default',
            'subject' => [ControlException::class],
            'sources' => ['exception', 'control', 'risk'],
            'rate' => 20,
        ],

        'narrative.generate' => [
            'label' => 'Narrative generation',
            'description' => 'Drafts management commentary for a report or submission section, grounded strictly in retrieved data with citations.',
            'permission' => 'design reports',
            'tier' => 'default',
            'subject' => [ReportRun::class, SubmissionPack::class],
            'sources' => ['control', 'risk', 'exception', 'incident', 'obligation'],
            'rate' => 10,
        ],

        'framework.map' => [
            'label' => 'Framework mapping assistant',
            'description' => 'Proposes cross-framework requirement mappings with rationale and coverage. Every proposal enters the maker-checker queue.',
            'permission' => 'map controls',
            'tier' => 'reasoning',
            'subject' => [FrameworkRequirement::class],
            'sources' => ['framework_requirement', 'control'],
            'rate' => 10,
        ],

        'vendor.screen' => [
            'label' => 'Vendor / third-party screening',
            'description' => 'Summarises a vendor risk profile from supplied documents and structured data, and drafts due-diligence questions.',
            'permission' => 'create risks',
            'tier' => 'default',
            'subject' => [Document::class, Risk::class],
            'sources' => ['document', 'risk', 'policy'],
            'rate' => 10,
        ],

        'atlas.chat' => [
            'label' => 'Atlas assistant',
            'description' => 'Conversational assistant grounded in the tenant\'s own records, permission-filtered at retrieval and always citing what it used.',
            'permission' => 'use ai',
            'tier' => 'default',
            'subject' => [],
            'sources' => ['control', 'risk', 'policy', 'obligation', 'framework_requirement', 'incident', 'exception', 'document'],
            'rate' => 20,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Redaction (Part C §C.5)
    |--------------------------------------------------------------------------
    |
    | Deterministic and regex-driven, never model-based. Order matters: the
    | longest and most specific identifiers run first so an 11-digit BVN is
    | not first eaten by a 10-digit account pattern.
    |
    | `label` is the placeholder. Patterns that need per-value stability
    | (people's names) are handled by RedactionService's name pass, which
    | numbers placeholders within a request so "[PERSON_1] approved
    | [PERSON_2]'s posting" still reads as two distinct people.
    |
    */

    'redaction' => [

        'enabled' => env('AI_REDACTION_ENABLED', true),

        // Off by default: monetary amounts are usually the point of the
        // analysis. A tenant handling customer-level balances turns it on.
        'redact_amounts' => env('AI_REDACT_AMOUNTS', false),

        'patterns' => [
            'EMAIL' => '/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/',
            'PHONE' => '/(?<!\d)(?:\+?234|0)[789][01]\d{8}(?!\d)|(?<!\d)\+\d{1,3}[\s\-]?\d{6,12}(?!\d)/',
            'PAN' => '/(?<!\d)(?:\d[ \-]?){12,18}\d(?!\d)/',
            'BVN' => '/(?<!\d)\d{11}(?!\d)/',
            'ACCOUNT' => '/(?<!\d)\d{10}(?!\d)/',
        ],

        // Applied only when redact_amounts is on.
        'amount_pattern' => '/(?:₦|NGN|GHS|KES|ZAR|USD|EUR|GBP|\$|£|€)\s?[\d,]+(?:\.\d{1,2})?/i',

        /*
         | The PAN pattern is deliberately loose — it matches any 13-19 digit
         | run. RedactionService only replaces a match that passes Luhn, so a
         | reference number that happens to be sixteen digits survives.
         */
        'luhn_check_pan' => true,

        /*
         | Structured fields excluded from any assembled context, whatever the
         | source model. Belt and braces alongside the regex pass.
         */
        'excluded_attributes' => [
            'customer_name', 'customer_email', 'customer_phone', 'customer_address',
            'account_number', 'bvn', 'nin', 'subject_persons', 'reporter_token',
            'password', 'secret', 'credentials', 'api_key', 'token',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retrieval (Part C §C.7)
    |--------------------------------------------------------------------------
    |
    | Each source declares the model it indexes, the permission a user must
    | hold to be shown a chunk from it, and the route name a citation resolves
    | to. `permission` is the retrieval filter — it runs in SQL, before the
    | model sees anything, which is the single most important control here.
    |
    */

    'retrieval' => [

        'chunk_tokens' => (int) env('AI_CHUNK_TOKENS', 800),
        'chunk_overlap_tokens' => (int) env('AI_CHUNK_OVERLAP_TOKENS', 100),

        // Top-k after reciprocal rank fusion, and the relevance floor a chunk
        // must clear to be shown at all. A retrieval that returns nothing
        // makes the model say so rather than invent.
        'top_k' => (int) env('AI_RETRIEVAL_TOP_K', 12),
        'candidate_pool' => (int) env('AI_RETRIEVAL_CANDIDATES', 200),
        'relevance_floor' => (float) env('AI_RETRIEVAL_FLOOR', 0.05),
        'rrf_k' => 60,

        'sources' => [
            'control' => [
                'model' => Control::class,
                'permission' => 'view controls',
                'route' => 'controls.show',
                'label' => 'Control',
            ],
            'risk' => [
                'model' => Risk::class,
                'permission' => 'view risks',
                'route' => 'risks.show',
                'label' => 'Risk',
            ],
            'policy' => [
                'model' => Policy::class,
                'permission' => 'view policies',
                'route' => 'policies.show',
                'label' => 'Policy',
            ],
            'obligation' => [
                'model' => RegulatoryObligation::class,
                'permission' => 'view obligations',
                'route' => 'obligations.show',
                'label' => 'Obligation',
            ],
            'framework_requirement' => [
                'model' => FrameworkRequirement::class,
                'permission' => 'view frameworks',
                'route' => null,
                'label' => 'Framework requirement',
            ],
            'incident' => [
                'model' => Incident::class,
                'permission' => 'view incidents',
                'route' => 'incidents.show',
                'label' => 'Incident',
            ],
            'exception' => [
                'model' => ControlException::class,
                'permission' => 'view exceptions',
                'route' => 'exceptions.show',
                'label' => 'Exception',
            ],
            'document' => [
                'model' => Document::class,
                'permission' => 'view documents',
                'route' => 'documents.show',
                'label' => 'Document',
            ],
        ],

        /*
         | Investigation cases are deliberately absent from `sources`. Case
         | content is allowlist-confidential (Phase 11.4) and Part C excludes
         | Highly Restricted case content from retrieval entirely. Adding a
         | case source here would be a confidentiality regression, not a
         | feature.
         */
    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | Anthropic publishes no embedding endpoint, and shipping tenant records
    | to a third-party embedding provider would breach R5 for the sake of a
    | ranking signal. The default driver is therefore local and deterministic:
    | a hashed bag-of-terms projected into a fixed-width vector, cosine-scored
    | in PHP over a keyword-prefiltered candidate set exactly as Part C §C.7
    | permits ("correctness first, optimise later").
    |
    | It is a lexical-semantic hybrid, not a neural embedding, and it is
    | honest about that: keyword recall does the heavy lifting and the vector
    | supplies the ranking. Swapping in a real embedding model later is a new
    | driver plus a re-index, with no change above EmbeddingService.
    |
    */

    'embeddings' => [
        'driver' => env('AI_EMBEDDING_DRIVER', 'hash'),
        'dimensions' => (int) env('AI_EMBEDDING_DIMENSIONS', 256),
        'min_term_length' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Budgets & rate limits (Part C §C.1.5)
    |--------------------------------------------------------------------------
    |
    | Defaults for a period that has no ai_budgets row yet. hard_stop true
    | means the gateway refuses the call outright rather than warning — the
    | only honest behaviour for a spend cap.
    |
    */

    'budget' => [
        'default_monthly_tokens' => (int) env('AI_MONTHLY_TOKEN_BUDGET', 5000000),
        'default_monthly_cost_minor' => (int) env('AI_MONTHLY_COST_BUDGET_MINOR', 50000),
        'currency' => env('AI_BUDGET_CURRENCY', 'USD'),
        'hard_stop' => (bool) env('AI_BUDGET_HARD_STOP', true),
        'alert_thresholds' => [50, 80, 95],
    ],

    'rate_limits' => [
        // Per user, per minute, across all capabilities.
        'per_user' => (int) env('AI_RATE_PER_USER', 30),
        // Per tenant, per minute, across all users.
        'per_tenant' => (int) env('AI_RATE_PER_TENANT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Output handling
    |--------------------------------------------------------------------------
    */

    'output' => [
        // One repair attempt on malformed output, then fail gracefully
        // (Part C §C.4 step 9).
        'repair_attempts' => (int) env('AI_REPAIR_ATTEMPTS', 1),

        // A draft below this confidence is recorded but flagged in the review
        // panel. ai_configurations.min_confidence_to_surface overrides it.
        'min_confidence_to_surface' => (float) env('AI_MIN_CONFIDENCE', 0.35),

        // Raw model output kept on the interaction for this many days, then
        // pruned by ai:prune. The interaction row itself is never deleted —
        // the audit trail is append-only (R3).
        'raw_output_retention_days' => (int) env('AI_RAW_OUTPUT_RETENTION_DAYS', 180),
    ],
];
