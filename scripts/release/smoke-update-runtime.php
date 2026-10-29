#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Services\Update\BuildSystemUpdatePackage;
use App\Services\Update\UpdateReleaseStager;
use App\Support\ReleasePackageBuilder;
use App\Support\ReleasePackageContract;
use App\Support\Update\ActiveReleasePointer;
use App\Support\Update\UpdatePackageReader;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

// Build/CI acceptance only: the production updater never invokes a shell or process.
$projectRoot = dirname(__DIR__, 2);
require $projectRoot.'/vendor/autoload.php';
if ($argc !== 2 || ! is_dir($argv[1])) {
    fwrite(STDERR, "Usage: php scripts/release/smoke-update-runtime.php <built-runtime-release>\n");
    exit(2);
}
$app = require $projectRoot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$fixture = sys_get_temp_dir().'/seo-update-runtime-'.bin2hex(random_bytes(8));
$installation = $fixture.'/installation';
$target = $fixture.'/target-build';
$server = null;
$failed = false;

$require = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

try {
    (new ReleasePackageBuilder)->build($argv[1], $installation);
    (new ReleasePackageBuilder)->build($argv[1], $target);
    File::put($installation.'/VERSION', "0.1.0-alpha.1\n");
    File::put($target.'/VERSION', "0.1.0-alpha.2\n");
    $key = 'base64:'.base64_encode(random_bytes(32));
    File::put($installation.'/.env', "APP_ENV=production\nAPP_NAME=SEOStableRuntimeSmoke\nAPP_KEY={$key}\nSESSION_DRIVER=file\nCACHE_STORE=file\nINSTALLER_ASSUME_INSTALLED=false\n");
    File::put($installation.'/storage/app/stable-proof.txt', 'stable storage proof');

    // These assertions run in each genuinely booted release, without sharing class graphs.
    $wrapper = <<<'PHP'
<?php
$app = require __DIR__.'/smoke-original-app.php';
if ($app->basePath() !== dirname(__DIR__)
    || $app->storagePath() !== SEO_OS_INSTALLATION_ROOT.'/storage'
    || $app->environmentPath() !== SEO_OS_INSTALLATION_ROOT
    || file_get_contents($app->storagePath().'/app/stable-proof.txt') !== 'stable storage proof') {
    throw new RuntimeException('Stable runtime context was not preserved.');
}
return $app;
PHP;
    foreach ([$installation => 'source', $target => 'target'] as $root => $label) {
        File::move($root.'/bootstrap/app.php', $root.'/bootstrap/smoke-original-app.php');
        File::put($root.'/bootstrap/app.php', $wrapper);
        File::append($root.'/resources/views/app.blade.php', "\n<!-- seo-update-smoke-{$label} -->\n");
    }
    $sourceAsset = 'source runtime asset';
    $targetAsset = 'target runtime asset';
    $sourceAssetName = 'smoke-'.hash('sha256', $sourceAsset).'.js';
    $targetAssetName = 'smoke-'.hash('sha256', $targetAsset).'.js';
    File::put($installation.'/public/build/assets/'.$sourceAssetName, $sourceAsset);
    File::put($target.'/public/build/assets/'.$targetAssetName, $targetAsset);

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    $require(is_resource($socket), 'Unable to reserve runtime smoke port.');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $require(is_string($address), 'Unable to resolve runtime smoke port.');
    $server = new Process([PHP_BINARY, '-S', $address, '-t', $installation.'/public'], $installation, [
        'APP_ENV' => 'production', 'APP_NAME' => false, 'APP_KEY' => false,
        'SESSION_DRIVER' => 'file', 'CACHE_STORE' => 'file',
        'INSTALLER_ASSUME_INSTALLED' => 'false',
    ]);
    $server->start();
    $get = static function (string $path) use ($address): string {
        $body = @file_get_contents('http://'.$address.$path, false, stream_context_create([
            'http' => ['timeout' => 10, 'ignore_errors' => true],
        ]));
        if (! is_string($body)) {
            throw new RuntimeException('Runtime smoke HTTP request failed.');
        }

        return $body;
    };
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        try {
            $html = $get('/install');
            if (str_contains($html, 'seo-update-smoke-source')) {
                $ready = true;
                break;
            }
        } catch (Throwable) {
            // Wait only for the local test server, not an external dependency.
        }
        usleep(100_000);
    }
    $require($ready, 'Root runtime did not boot.');
    $require(str_contains($html, 'SEOStableRuntimeSmoke'), 'Root did not use stable environment.');

    $manifest = $app->make(BuildSystemUpdatePackage::class)->build($target, $fixture.'/runtime.seoupdate', [
        'source_product_version' => '0.1.0-alpha.1',
        'source_schema_fingerprint' => str_repeat('1', 64),
        'target_schema_fingerprint' => str_repeat('1', 64),
        'channel' => 'development',
        'minimum_php_version' => '8.3.0',
        'required_php_extensions' => ['ctype', 'json', 'pdo'],
        'created_at_utc' => '2026-10-23T00:00:00Z',
    ]);
    $stager = new UpdateReleaseStager($app->make(UpdatePackageReader::class), new ReleasePackageContract, installationRoot: $installation);
    $slot = $stager->stage($fixture.'/runtime.seoupdate', $manifest);
    $stager->preparePublicAssets($slot['release_root'], $manifest);
    $pointer = new ActiveReleasePointer($installation);
    $pointer->activateSlot($slot['release_id'], '0.1.0-alpha.2', hash_file('sha256', $fixture.'/runtime.seoupdate'));

    $html = $get('/install');
    $require(str_contains($html, 'seo-update-smoke-target'), 'Private target release did not boot.');
    $require(! str_contains($html, 'seo-update-smoke-source'), 'Source classes/views were reused after activation.');
    $require(str_contains($html, 'SEOStableRuntimeSmoke'), 'Target did not use stable environment.');
    $require($get('/build/assets/'.$targetAssetName) === $targetAsset, 'Target public asset was not served.');
    $require($get('/build/assets/'.$sourceAssetName) === $sourceAsset, 'Source public asset was lost.');
    $require(! is_file($slot['release_root'].'/.env'), 'Environment leaked into immutable target slot.');

    $pointer->activateRoot('0.1.0-alpha.1');
    $html = $get('/install');
    $require(str_contains($html, 'seo-update-smoke-source'), 'Source runtime did not boot after pointer rollback.');
    $require(! str_contains($html, 'seo-update-smoke-target'), 'Target view survived source rollback.');
    $require($get('/build/assets/'.$sourceAssetName) === $sourceAsset, 'Source asset failed after rollback.');
    fwrite(STDOUT, "Runtime update package smoke passed: root -> private slot -> root; stable environment/storage and source/target assets verified.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    if ($server !== null) {
        fwrite(STDERR, $server->getErrorOutput());
    }
    $failed = true;
} finally {
    $server?->stop();
    File::deleteDirectory($fixture);
}

if ($failed) {
    exit(1);
}
