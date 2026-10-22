#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Support\ReleasePackageBuilder;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php scripts/release/build-release.php <destination>\n");
    exit(2);
}

$destination = $argv[1];

try {
    (new ReleasePackageBuilder)->build($root, $destination);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}

fwrite(STDOUT, "Release tree verified: {$destination}\n");
