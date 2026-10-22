<?php

namespace App\Http\Middleware;

use App\Support\InstallationState;
use App\Support\Installer\InstallerPhase;
use App\Support\Installer\InstallerStatus;
use App\Support\RuntimeApplicationKey;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApplicationInstalled
{
    public function __construct(
        private InstallationState $installationState,
        private InstallerStatus $installerStatus,
        private RuntimeApplicationKey $runtimeApplicationKey,
    ) {}

    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        $this->runtimeApplicationKey->ensureConfigured();

        $installerRequest = $request->is('install') || $request->is('install/*');
        $installed = $this->installationState->isInstalled();

        if ($installed && $request->is('register') && ! config('installer.public_registration', false)) {
            abort(404);
        }

        if ($installed && $installerRequest) {
            return redirect('/');
        }

        if (! $installed) {
            if (! $installerRequest) {
                return redirect('/install');
            }

            $this->configureInstallerRuntime(
                $this->installerStatus->snapshot()['phase'] === InstallerPhase::Finalize,
            );
        }

        return $next($request);
    }

    private function configureInstallerRuntime(bool $finalizing): void
    {
        config([
            'session.driver' => $finalizing ? 'database' : 'file',
            'cache.default' => 'file',
            'queue.default' => 'sync',
        ]);
    }
}
