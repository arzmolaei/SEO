<?php

return [
    'import' => [
        // Original files live inside a durable backup root (see config/backup.php)
        // and are never web-served.
        'storage_root' => storage_path('app/private/uploads/keyword-imports'),
        'max_bytes' => (int) env('KEYWORD_IMPORT_MAX_BYTES', 20 * 1024 * 1024),
        'max_paste_bytes' => 1024 * 1024,
        'max_paste_lines' => 50_000,
        'preview_rows' => 5,
    ],
];
