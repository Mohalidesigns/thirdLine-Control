<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Activity Log retention (CR3)
    |--------------------------------------------------------------------------
    | How long activity-log rows stay in the hot table before
    | `audit:archive` moves them to cold storage (gzipped JSONL on the
    | archive disk). Archived rows are exported and verified before they
    | are removed — the log is never simply deleted. A tenant may override
    | the window via tenant->settings['audit_retention_months'] (Security
    | Policy page); the floor below is the default. 120 months = 10 years,
    | matching the longest evidence-retention expectation a Nigerian bank
    | regulator applies to control records.
    */

    'retention_months' => (int) env('AUDIT_LOG_RETENTION_MONTHS', 120),

    'archive' => [
        'disk' => env('AUDIT_LOG_ARCHIVE_DISK', 'local'),
        'path' => 'audit-archive',
    ],

];
