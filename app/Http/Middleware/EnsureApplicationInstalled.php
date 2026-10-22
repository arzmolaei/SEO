<?php

namespace App\Http\Middleware;

use App\Support\InstallationState;
use App\Support\RuntimeApplicationKey;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApplicationInstalled
{
    public function __construct(
        private InstallationState $installationState,
        private RuntimeApplicationKey $runtimeApplicationKey,
    ) {}

    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        $this->runtimeApplicationKey->ensureConfigured();

        $installerRequest = $request->is('install') || $request->is('install/*');
        $installed = $this->installationState->isInstalled();

        if ($installed && $installerRequest) {
            return redirect('/');
        }

        if (! $installed) {
            if (! $installerRequest) {
                return redirect('/install');
            }

            $this->configureInstallerRuntime();
        }

        return $next($request);
    }

    private function configureInstallerRuntime(): void
    {
        config([
            'session.driver' => 'file',
            'cache.default' => 'file',
            'queue.default' => 'sync',
        ]);
    }
}
