<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Snapshot storage
    |--------------------------------------------------------------------------
    |
    | Extracted data is written as newline-delimited JSON under a path that
    | begins with the tenant's declared data-residency country. Point this
    | disk at in-country storage; the path layout makes it possible to
    | prove, per file, which data plane a snapshot lives in (12.7, R5).
    |
    */

    'snapshot_disk' => env('CCM_SNAPSHOT_DISK', 'local'),

    'snapshot_root' => env('CCM_SNAPSHOT_ROOT', 'ccm/snapshots'),

    /*
    |--------------------------------------------------------------------------
    | Extraction limits
    |--------------------------------------------------------------------------
    |
    | A runaway extract must not fill the disk or the memory of a box that
    | is also serving a bank's control function. Both limits are ceilings,
    | not targets; a dataset may lower them in its extraction_config.
    |
    */

    'max_rows_per_snapshot' => (int) env('CCM_MAX_ROWS', 500000),

    'max_bytes_per_snapshot' => (int) env('CCM_MAX_BYTES', 268435456),

    /*
    |--------------------------------------------------------------------------
    | Rule execution
    |--------------------------------------------------------------------------
    |
    | custom_sql runs against a throwaway in-memory SQLite built from the
    | snapshot, never the application database. These bound that sandbox.
    |
    */

    'sql_timeout_seconds' => (int) env('CCM_SQL_TIMEOUT', 10),

    'sql_max_result_rows' => (int) env('CCM_SQL_MAX_ROWS', 50000),

    'max_findings_per_run' => (int) env('CCM_MAX_FINDINGS', 2000),

    /*
    |--------------------------------------------------------------------------
    | PII pseudonymisation
    |--------------------------------------------------------------------------
    |
    | Salt for the HMAC applied to sensitive personal fields at ingestion.
    | Set CCM_PII_SALT in production and rotate it only with intent: the
    | hash is what joins a finding to a subject across snapshots, so a
    | rotation deliberately breaks that continuity. Falls back to APP_KEY
    | so a fresh installation is never unsalted.
    |
    */

    'pii_salt' => env('CCM_PII_SALT', env('APP_KEY', '')),

];
