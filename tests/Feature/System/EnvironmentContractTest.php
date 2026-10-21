<?php

use Illuminate\Support\Facades\Config;

it('uses SEO OS self-hosted runtime defaults', function () {
    expect(PHP_VERSION_ID)->toBeGreaterThanOrEqual(80300)
        ->and(Config::get('app.name'))->toBe('SEO OS')
        ->and(Config::get('app.locale'))->toBe('fa')
        ->and(Config::get('app.fallback_locale'))->toBe('en')
        ->and(Config::get('queue.default'))->toBe('database')
        ->and(Config::get('filesystems.default'))->toBe('local');
});
