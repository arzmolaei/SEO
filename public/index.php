<?php

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

define('LARAVEL_START', microtime(true));
$installationRoot = dirname(__DIR__);
require_once $installationRoot.'/bootstrap/runtime-release.php';
$updateGate = new SeoOsUpdateMaintenanceGate($installationRoot);
if ($updateGate->shouldBlockHttp($_SERVER['REQUEST_URI'] ?? '/')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store, private');
    echo 'Application update is in progress.';
    exit(1);
}
try {
    $locator = new SeoOsRuntimeReleaseLocator($installationRoot);
    $release = $locator->resolve();
} catch (Throwable) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store, private');
    echo 'Application update recovery is required.';
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
$failBootstrap = static function () use (&$bootstrapFailureHandled, &$emergencyMemory, $bufferLevel, $locator, $release): void {
    if ($bootstrapFailureHandled) {
        return;
    }
    $bootstrapFailureHandled = true;
    $emergencyMemory = null;
    while (ob_get_level() > $bufferLevel) {
        ob_end_clean();
    }
    $locator->recoverPreMigrationBootstrapFailure($release);
    http_response_code(503);
    if (! headers_sent()) {
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store, private');
    }
    echo 'Application update recovery is required.';
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
} catch (Throwable) {
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
