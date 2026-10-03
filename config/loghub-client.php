<?php

return [
    'enabled' => env('LOGHUB_CLIENT_ENABLED', true),
    'endpoint' => env('LOGHUB_CLIENT_URL', 'http://localhost:8000'),
    'api_key' => env('LOGHUB_CLIENT_API_KEY'),
    'environment' => env('LOGHUB_CLIENT_ENVIRONMENT', env('APP_ENV')),
    'level' => env('LOGHUB_CLIENT_LEVEL', 'debug'),

    'request_id' => [
        'enabled' => env('LOGHUB_CLIENT_REQUEST_ID_ENABLED', true),
        'header' => env('LOGHUB_CLIENT_REQUEST_ID_HEADER', 'X-Request-ID'),
    ],

    'spool' => [
        'path' => env('LOGHUB_CLIENT_SPOOL_PATH', storage_path('logs/loghub-spool')),
        'max_record_bytes' => (int) env('LOGHUB_CLIENT_MAX_RECORD_BYTES', 65_536),
        'max_file_bytes' => (int) env('LOGHUB_CLIENT_MAX_FILE_BYTES', 5_242_880),
        'max_total_bytes' => (int) env('LOGHUB_CLIENT_MAX_TOTAL_BYTES', 52_428_800),
        'lease_stale_seconds' => (int) env('LOGHUB_CLIENT_LEASE_STALE_SECONDS', 300),
    ],

    'delivery' => [
        'batch_size' => (int) env('LOGHUB_CLIENT_BATCH_SIZE', 100),
        'connect_timeout_seconds' => (float) env('LOGHUB_CLIENT_CONNECT_TIMEOUT', 0.5),
        'timeout_seconds' => (float) env('LOGHUB_CLIENT_TIMEOUT', 2.0),
        'retry_max_seconds' => (int) env('LOGHUB_CLIENT_RETRY_MAX_SECONDS', 300),
        'max_batches_per_run' => (int) env('LOGHUB_CLIENT_MAX_BATCHES_PER_RUN', 10),
    ],

    'queue' => [
        'enabled' => env('LOGHUB_CLIENT_QUEUE_ENABLED', false),
        'connection' => env('LOGHUB_CLIENT_QUEUE_CONNECTION'),
        'name' => env('LOGHUB_CLIENT_QUEUE', 'default'),
    ],

    'schedule' => [
        'enabled' => env('LOGHUB_CLIENT_SCHEDULE_ENABLED', false),
    ],
];
