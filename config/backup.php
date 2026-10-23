<?php

return [
    'lifecycle_mutex_path' => env('BACKUP_LIFECYCLE_LOCK_PATH', storage_path('app/lifecycle/lifecycle.lock')),
    'maintenance_retry_after' => (int) env('BACKUP_MAINTENANCE_RETRY', 60),

    'bundle' => [
        'max_manifest_bytes' => 4 * 1024 * 1024,
        'max_path_bytes' => 1024,
        'max_entries' => 100_000,
        'max_entry_bytes' => 1024 * 1024 * 1024,
        'max_bundle_bytes' => 2 * 1024 * 1024 * 1024,
        'chunk_bytes' => 64 * 1024,
    ],

    'capture' => [
        'batch_size' => 250,
        'temp_memory_bytes' => 2 * 1024 * 1024,
    ],

    'durable_file_roots' => [
        'storage/app/private/uploads' => storage_path('app/private/uploads'),
        'storage/app/public/uploads' => storage_path('app/public/uploads'),
    ],
];
