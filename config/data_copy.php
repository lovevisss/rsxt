<?php

return [
    'sources' => [
        'uchr' => [
            'host' => env('COPY_SOURCE_LEGACY_HOST', env('LEGACY_DB_HOST')),
            'port' => env('COPY_SOURCE_LEGACY_PORT', env('LEGACY_DB_PORT', 3306)),
            'database' => env('COPY_SOURCE_LEGACY_DATABASE', 'uchr'),
            'username' => env('COPY_SOURCE_LEGACY_USERNAME', env('LEGACY_DB_USERNAME')),
            'password' => env('COPY_SOURCE_LEGACY_PASSWORD', env('LEGACY_DB_PASSWORD')),
        ],
        'rsxt' => [
            'host' => env('COPY_SOURCE_AUX_HOST', env('DB_HOST')),
            'port' => env('COPY_SOURCE_AUX_PORT', env('DB_PORT', 3306)),
            'database' => env('COPY_SOURCE_AUX_DATABASE', 'rsxt'),
            'username' => env('COPY_SOURCE_AUX_USERNAME', env('DB_USERNAME')),
            'password' => env('COPY_SOURCE_AUX_PASSWORD', env('DB_PASSWORD')),
        ],
    ],
    'target' => [
        'host' => env('COPY_TARGET_HOST', '10.1.12.183'),
        'port' => env('COPY_TARGET_PORT', 3306),
        'username' => env('COPY_TARGET_USERNAME', ''),
        'password' => env('COPY_TARGET_PASSWORD', ''),
    ],
    'manifest' => storage_path('app/private/migration/instance-copy.json'),
    'reset_tables' => ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'],
    'post_copy_migrations' => ['2026_10_29_000001_create_legacy_file_mappings'],
];
