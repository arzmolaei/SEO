<?php

use App\Http\Middleware\EnsureApplicationInstalled;
use App\Http\Middleware\EnsureLifecycleRequestAdmission;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        // Import originals preserve source whitespace before deriving normalized values.
        $middleware->trimStrings(except: [
            fn (Request $request): bool => $request->is('projects/*/imports*'),
        ]);

        $middleware->web(
            prepend: [
                EnsureLifecycleRequestAdmission::class,
                EnsureApplicationInstalled::class,
            ],
            append: [
                HandleAppearance::class,
                HandleInertiaRequests::class,
                AddLinkHeadersForPreloadedAssets::class,
            ],
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

if (defined('SEO_OS_INSTALLATION_ROOT')) {
    $installationRoot = constant('SEO_OS_INSTALLATION_ROOT');

    if (! is_string($installationRoot) || $installationRoot === '') {
        throw new RuntimeException('Stable installation root is unavailable.');
    }

    $resolvedInstallationRoot = realpath($installationRoot);
    if ($resolvedInstallationRoot === false || ! is_dir($resolvedInstallationRoot)) {
        throw new RuntimeException('Stable installation root is unavailable.');
    }

    $app->useEnvironmentPath($resolvedInstallationRoot);
    $app->useStoragePath($resolvedInstallationRoot.DIRECTORY_SEPARATOR.'storage');
}

return $app;
