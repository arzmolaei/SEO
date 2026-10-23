<?php

return [
    'package' => [
        'max_manifest_bytes' => 4 * 1024 * 1024,
        'max_path_bytes' => 1024,
        'max_entries' => 100_000,
        'max_entry_bytes' => 1024 * 1024 * 1024,
        'max_package_bytes' => 2 * 1024 * 1024 * 1024,
        'chunk_bytes' => 64 * 1024,
    ],
];
