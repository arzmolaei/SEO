<?php

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

// First, before anything that needs it: on older PHP, Composer's platform
// check would fail during boot and be misreported as an update recovery.
if (PHP_VERSION_ID < 80300) {
    http_response_code(500);
    if (! headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, private');
    }
    $phpVersion = htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
        .'<meta name="viewport" content="width=device-width, initial-scale=1"><title>PHP 8.3+</title></head>'
        .'<body style="font-family:system-ui,Tahoma,sans-serif;margin:10vh auto;max-width:36rem;padding:1rem;line-height:1.8">'
        .'<h1 data-seo-os-php-version="unsupported">PHP 8.3+ لازم است (نسخه فعلی: '.$phpVersion.')</h1>'
        .'<p lang="en" dir="ltr">PHP 8.3+ required (current: '.$phpVersion.')</p></body></html>';
    exit(1);
}

define('LARAVEL_START', microtime(true));
$installationRoot = dirname(__DIR__);
// Dependency-free maintenance response: browsers get a bilingual
// (Persian-first) page that retries itself; scripts and monitors keep the
// stable plain-text sentence. Must stay cheap: it also runs after fatal
// bootstrap errors under memory pressure.
$serviceUnavailable = static function (bool $inProgress): void {
    http_response_code(503);
    $english = $inProgress ? 'Application update is in progress.' : 'Application update recovery is required.';
    $html = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html');
    if (! headers_sent()) {
        header('Content-Type: '.($html ? 'text/html' : 'text/plain').'; charset=UTF-8');
        header('Cache-Control: no-store, private');
        header('Retry-After: '.($inProgress ? '15' : '60'));
    }
    if (! $html) {
        echo $english;

        return;
    }
    $persian = $inProgress
        ? ['به‌روزرسانی برنامه در حال انجام است', 'چند لحظه صبر کنید؛ این صفحه هر ۱۵ ثانیه خودکار دوباره بارگذاری می‌شود.']
        : ['بازیابی به‌روزرسانی لازم است', 'مالک سیستم باید از صفحه به‌روزرسانی و بازیابی نسخه، بازیابی را ادامه دهد.'];
    $englishDetail = $inProgress
        ? 'Please wait; this page reloads itself every 15 seconds.'
        : 'The system owner must continue recovery from the update and recovery page.';
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
        .'<meta name="viewport" content="width=device-width, initial-scale=1">'
        .($inProgress ? '<meta http-equiv="refresh" content="15">' : '')
        .'<title>'.$persian[0].' — '.$english.'</title>'
        .'<style>body{font-family:system-ui,Tahoma,sans-serif;margin:0;padding:2rem;background:#f8fafc;color:#0f172a}'
        .'main{max-width:36rem;margin:10vh auto;padding:2rem;border:1px solid #e2e8f0;border-radius:1rem;background:#fff}'
        .'h1{font-size:1.25rem}p{line-height:1.8}section+section{margin-top:1.5rem;padding-top:1.5rem;border-top:1px solid #e2e8f0}'
        .'a{color:#1d4ed8}@media (prefers-color-scheme:dark){body{background:#0f172a;color:#e2e8f0}main{background:#111827;border-color:#334155}a{color:#93c5fd}}</style>'
        .'</head><body><main role="alert" data-seo-os-maintenance="'.($inProgress ? 'update' : 'recovery').'">'
        .'<section><h1>'.$persian[0].'</h1><p>'.$persian[1].'</p>'
        .($inProgress ? '' : '<p><a href="/settings/update">صفحه به‌روزرسانی و بازیابی نسخه</a></p>')
        .'</section><section lang="en" dir="ltr"><h1>'.$english.'</h1><p>'.$englishDetail.'</p>'
        .($inProgress ? '' : '<p><a href="/settings/update">Update and recovery</a></p>')
        .'</section></main></body></html>';
};
require_once $installationRoot.'/bootstrap/runtime-release.php';
$updateGate = new SeoOsUpdateMaintenanceGate($installationRoot);
if ($updateGate->shouldBlockHttp($_SERVER['REQUEST_URI'] ?? '/')) {
    $serviceUnavailable(true);
    exit(1);
}
try {
    $locator = new SeoOsRuntimeReleaseLocator($installationRoot);
    $release = $locator->resolve();
} catch (Throwable $bootError) {
    $serviceUnavailable(false);
    exit(1);
}
if (! defined('SEO_OS_INSTALLATION_ROOT')) {
    define('SEO_OS_INSTALLATION_ROOT', $installationRoot);
}
if (file_exists($maintenance = $installationRoot.'/storage/framework/maintenance.php')) {
    require $maintenance;
}
$bootstrapComplete = false;
$bootstrapFailureHandled = false;
$emergencyMemory = str_repeat(' ', 65536);
$bufferLevel = ob_get_level();
ob_start();
$failBootstrap = static function () use (&$bootstrapFailureHandled, &$emergencyMemory, $bufferLevel, $locator, $release, $serviceUnavailable): void {
    if ($bootstrapFailureHandled) {
        return;
    }
    $bootstrapFailureHandled = true;
    $emergencyMemory = null;
    while (ob_get_level() > $bufferLevel) {
        ob_end_clean();
    }
    $locator->recoverPreMigrationBootstrapFailure($release);
    $serviceUnavailable(false);
};
register_shutdown_function(static function () use (&$bootstrapComplete, &$bootstrapFailureHandled, $failBootstrap): void {
    if ($bootstrapComplete || $bootstrapFailureHandled) {
        return;
    }
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        $failBootstrap();
        exit(1);
    }
});
$displayErrors = ini_set('display_errors', '0');
$logErrors = ini_set('log_errors', '0');
try {
    require $release['release_root'].'/vendor/autoload.php';
    /** @var Application $app */
    $app = require_once $release['release_root'].'/bootstrap/app.php';
    if (! $app instanceof Application) {
        throw new RuntimeException('The application release could not boot.');
    }
    $request = Request::capture();
    $app->instance('request', $request);
    Facade::clearResolvedInstance('request');
    $app->make(HttpKernel::class)->bootstrap();
} catch (Throwable $bootError) {
    $failBootstrap();
    exit(1);
}
$bootstrapComplete = true;
$emergencyMemory = null;
ob_end_clean();
if (is_string($displayErrors)) {
    ini_set('display_errors', $displayErrors);
}
if (is_string($logErrors)) {
    ini_set('log_errors', $logErrors);
}
$app->handleRequest($request);
