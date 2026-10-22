<?php

namespace App\Http\Controllers;

use App\Support\InstallationState;
use App\Support\SystemHealthChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class InstallerController extends Controller
{
    public function __construct(
        private SystemHealthChecker $healthChecker,
        private InstallationState $installationState,
    ) {}

    public function index(): Response
    {
        return Inertia::render('install/index', [
            'report' => $this->healthChecker->check(),
            'installed' => $this->installationState->isInstalled(),
        ]);
    }

    public function complete(Request $request): RedirectResponse|SymfonyResponse
    {
        if ($this->installationState->isInstalled()) {
            return redirect('/');
        }

        $report = $this->healthChecker->check();

        if (! $report['ready']) {
            return Inertia::render('install/index', [
                'report' => $report,
                'installed' => false,
            ])->toResponse($request)->setStatusCode(422);
        }

        $this->installationState->markInstalled();

        return redirect('/');
    }
}
