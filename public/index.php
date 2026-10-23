<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

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
    $release = (new SeoOsRuntimeReleaseLocator($installationRoot))->resolve();
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
try {
    require $release['release_root'].'/vendor/autoload.php';
    /** @var Application $app */
    $app = require_once $release['release_root'].'/bootstrap/app.php';
} catch (Throwable) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store, private');
    echo 'Application update recovery is required.';
    exit(1);
}
$app->handleRequest(Request::capture());
