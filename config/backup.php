<?php

return [
    'lifecycle_mutex_path' => env(
        'BACKUP_LIFECYCLE_MUTEX_PATH',
        storage_path('app/lifecycle/lifecycle.lock'),
    ),

    'maintenance_retry_seconds' => (int) env('BACKUP_MAINTENANCE_RETRY_SECONDS', 60),
];
