<?php

return [
    'assume_installed' => (bool) env('INSTALLER_ASSUME_INSTALLED', false),
    'lock_path' => env('INSTALLER_LOCK_PATH', storage_path('app/.installed')),
    'original_queue_connection' => env('QUEUE_CONNECTION', 'database'),
];
