#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Services\Update\BuildSystemUpdatePackage;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

if ($argc < 6 || $argc > 7) {
    fwrite(STDERR, "Usage: php scripts/release/build-update-package.php <target-release-root> <output-file> <source-version> <source-schema-fingerprint> <target-schema-fingerprint> [channel]\n");
    exit(2);
}

try {
    $epoch = getenv('SOURCE_DATE_EPOCH');
    if ($epoch !== false && preg_match('/\A[0-9]{1,10}\z/', $epoch) !== 1) {
        throw new RuntimeException('Invalid SOURCE_DATE_EPOCH.');
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $manifest = $app->make(BuildSystemUpdatePackage::class)->build($argv[1], $argv[2], [
        'source_product_version' => $argv[3],
        'source_schema_fingerprint' => $argv[4],
        'target_schema_fingerprint' => $argv[5],
        'channel' => $argv[6] ?? 'stable',
        'minimum_php_version' => '8.3.0',
        'required_php_extensions' => ['ctype', 'fileinfo', 'json', 'mbstring', 'openssl', 'pdo', 'tokenizer', 'xml'],
        'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z', $epoch === false ? time() : (int) $epoch),
    ]);
    fwrite(STDOUT, 'Update package verified: '.$manifest->toArray()['target_product_version']."\n");
} catch (Throwable) {
    fwrite(STDERR, "Unable to build a verified update package. Check release inputs and explicit compatibility metadata.\n");
    exit(1);
}
