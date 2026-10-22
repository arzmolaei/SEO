<?php

namespace App\Http\Controllers;

use App\Support\Installer\InstallerPhase;
use App\Support\Installer\InstallerPreflight;
use App\Support\Installer\InstallerStatus;
use Inertia\Inertia;
use Inertia\Response;

class InstallerController extends Controller
{
    public function __construct(
        private readonly InstallerPreflight $preflight,
        private readonly InstallerStatus $status,
    ) {}

    public function index(): Response
    {
        $snapshot = $this->status->snapshot();
        $phase = $snapshot['phase'];

        return Inertia::render('install/index', [
            'installer' => [
                'phase' => $phase->value,
                'installed' => $snapshot['installed'],
                'values' => [],
                'actions' => $phase === InstallerPhase::Preflight
                    ? ['refresh_preflight']
                    : [],
            ],
            'preflight' => $this->preflight->check(),
        ]);
    }
}
