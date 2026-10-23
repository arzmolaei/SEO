<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$installationRoot = dirname(__DIR__);
require_once $installationRoot.'/bootstrap/runtime-release.php';

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

// Determine if the stable installation is in maintenance mode...
if (file_exists($maintenance = $installationRoot.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

try {
    // Register the selected release Composer autoloader...
    require $release['release_root'].'/vendor/autoload.php';

    // Bootstrap the selected Laravel release...
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
