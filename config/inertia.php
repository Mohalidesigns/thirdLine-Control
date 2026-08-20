<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | This repo follows the ThirdLine standard: page components live in
    | resources/js/Pages (capital P). inertia-laravel v3 defaults to the
    | lowercase js/pages, which resolves on a case-insensitive macOS disk
    | but NOT on the Linux CI runner — every ->component() assertion there
    | failed with "page component file does not exist". The path is pinned
    | here with its real casing so both filesystems agree.
    |
    */

    'pages' => [

        'ensure_pages_exist' => false,

        'paths' => [

            resource_path('js/Pages'),

        ],

        'extensions' => [

            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | assertInertia checks that an asserted component exists as a file under
    | pages.paths with one of pages.extensions. Kept on: a misnamed page is
    | exactly the class of bug this catches.
    |
    */

    'testing' => [

        'ensure_pages_exist' => true,

    ],

];
