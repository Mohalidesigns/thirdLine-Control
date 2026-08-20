<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Declared data plane
    |--------------------------------------------------------------------------
    |
    | The country this installation's data plane runs in. A tenant's own
    | declaration (tenants.data_residency) takes precedence when one is
    | resolvable; this is the floor for console work with no tenant in
    | scope. This is infrastructure fact, not regulatory content — the
    | lawful grounds for moving data are seeded records (R1).
    |
    */

    'default_country' => env('RESIDENCY_DEFAULT_COUNTRY', 'NG'),

    /*
    |--------------------------------------------------------------------------
    | Region maps — filesystem, queue, backup, integration
    |--------------------------------------------------------------------------
    |
    | Where each disk, queue broker and integration host physically lives.
    | The residency guard consults these maps before any write, dispatch,
    | backup or outbound call and blocks — not warns — on a mismatch (16.2).
    | '*' means in-process/same-plane (sync queue, array driver).
    |
    | There is deliberately no kill switch here and none in the database:
    | the guard cannot be disabled at runtime. Changing a declaration is a
    | re-provisioning event.
    |
    */

    'disk_regions' => [
        'local' => env('RESIDENCY_DISK_LOCAL', env('RESIDENCY_DEFAULT_COUNTRY', 'NG')),
        'public' => env('RESIDENCY_DISK_PUBLIC', env('RESIDENCY_DEFAULT_COUNTRY', 'NG')),
        's3' => env('RESIDENCY_DISK_S3', env('RESIDENCY_DEFAULT_COUNTRY', 'NG')),
    ],

    'queue_regions' => [
        'sync' => '*',
        'database' => env('RESIDENCY_QUEUE_DATABASE', '*'),
        'redis' => env('RESIDENCY_QUEUE_REDIS', env('RESIDENCY_DEFAULT_COUNTRY', 'NG')),
    ],

    // The disk atheris:backup writes to, and the country it lives in.
    'backup_disk' => env('RESIDENCY_BACKUP_DISK', 'local'),

    // Host (suffix-matched) → country for outbound integration endpoints.
    // An unmapped host is assumed to sit in this installation's own data
    // plane — production deployments map every integration endpoint
    // (docs/deployment). A host mapped to a foreign country is blocked.
    'endpoint_regions' => [
        // 'thirdline.example.ng' => 'NG',
    ],

];
