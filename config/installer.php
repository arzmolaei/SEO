<?php

return [
    'assume_installed' => (bool) env('INSTALLER_ASSUME_INSTALLED', false),
    'lock_path' => env('INSTALLER_LOCK_PATH', storage_path('app/.installed')),
    'journal_path' => env('INSTALLER_JOURNAL_PATH', storage_path('app/installer/journal.json')),
    'mutex_path' => env('INSTALLER_MUTEX_PATH', storage_path('app/installer/installer.lock')),
    'original_queue_connection' => env('QUEUE_CONNECTION', 'database'),
];
