<?php

return [
    'assume_installed' => (bool) env('INSTALLER_ASSUME_INSTALLED', false),
    'lock_path' => env('INSTALLER_LOCK_PATH', storage_path('app/.installed')),
    'journal_path' => env('INSTALLER_JOURNAL_PATH', storage_path('app/installer/journal.json')),
    'mutex_path' => env('INSTALLER_MUTEX_PATH', storage_path('app/installer/installer.lock')),
    'runtime_key_path' => env('INSTALLER_RUNTIME_KEY_PATH', storage_path('app/installer/runtime-app-key')),
    'scheduler_heartbeat_path' => env('INSTALLER_SCHEDULER_HEARTBEAT_PATH', storage_path('app/operations/scheduler-heartbeat.json')),
    'queue_probe_path' => env('INSTALLER_QUEUE_PROBE_PATH', storage_path('app/operations/queue-probes')),
    'original_session_driver' => env('SESSION_DRIVER', 'database'),
    'original_queue_connection' => env('QUEUE_CONNECTION', 'database'),
    'product_version' => env('SEO_OS_VERSION', 'dev'),
    'public_registration' => (bool) env('SELF_HOST_REGISTRATION', false),
];
