<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Widget cache
    |--------------------------------------------------------------------------
    |
    | Seconds a resolved widget dataset is held. Every cache key includes the
    | tenant and the viewer's permission fingerprint, so a cached tile can
    | never be served to someone entitled to less than the person who warmed
    | it. Zero disables caching, which is what the test suite runs with —
    | a stale tile in a test is a false green.
    |
    */

    'cache_ttl' => (int) env('DASHBOARD_CACHE_TTL', env('APP_ENV') === 'testing' ? 0 : 60),

    /*
    |--------------------------------------------------------------------------
    | Layout limits
    |--------------------------------------------------------------------------
    |
    | R6: a dashboard is one round-trip on a mid-range Android over 4G. The
    | widget cap is what keeps that promise — past this many tiles the page
    | stops being useful before it finishes resolving.
    |
    */

    'max_widgets' => (int) env('DASHBOARD_MAX_WIDGETS', 24),

    'grid_columns' => 12,

    /*
    |--------------------------------------------------------------------------
    | Report delivery
    |--------------------------------------------------------------------------
    |
    | Hours a generated report's secure download link stays live, and how long
    | the generated file itself is retained before the retention machinery
    | takes it. A board pack link that never expires is a leak waiting for a
    | forwarded email.
    |
    */

    'report_link_ttl_hours' => (int) env('REPORT_LINK_TTL_HOURS', 72),

    'report_disk' => env('REPORT_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Submission packs
    |--------------------------------------------------------------------------
    |
    | The completeness floor below which the Submit action is refused. It is a
    | setting rather than a constant because a regulator's tolerance for a
    | partial return is a compliance judgement, not an engineering one (R1).
    |
    */

    'submission_completeness_threshold' => (int) env('SUBMISSION_COMPLETENESS_THRESHOLD', 95),

];
