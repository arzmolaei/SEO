<?php

return [
    // Intranet sites only: lets the crawler connect to private/loopback
    // addresses. Off by default so a crawl can never reach internal services.
    'allow_private_networks' => (bool) env('CRAWLER_ALLOW_PRIVATE_NETWORKS', false),

    // URLs claimed per queue job and the wall-clock budget of one job. A
    // short job keeps update quiescence (no reserved jobs) reachable.
    'batch_size' => 25,
    'batch_seconds' => 45,

    'max_attempts' => 3,
    'max_urls_limit' => 100000,
    'max_depth_limit' => 50,
    'max_sitemap_files' => 50,
    'default_delay_ms' => 500,
];
