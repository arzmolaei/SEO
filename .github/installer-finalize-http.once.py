from pathlib import Path

controller_path = Path('app/Http/Controllers/InstallerController.php')
controller = controller_path.read_text()

controller = controller.replace(
    "namespace App\\Http\\Controllers;\n\nuse App\\Support\\Installer\\CreateInitialOwner;",
    "namespace App\\Http\\Controllers;\n\nuse App\\Actions\\Installer\\FinalizeInstallation;\nuse App\\Support\\Installer\\CreateInitialOwner;",
    1,
)
controller = controller.replace(
    "use Illuminate\\Http\\Request;\nuse Illuminate\\Validation\\ValidationException;",
    "use Illuminate\\Http\\Request;\nuse Illuminate\\Support\\Facades\\Auth;\nuse Illuminate\\Validation\\ValidationException;",
    1,
)
controller = controller.replace(
    "        private readonly QueueProbe $queueProbe,\n        private readonly InstallerJournal $journal,",
    "        private readonly QueueProbe $queueProbe,\n        private readonly FinalizeInstallation $finalizeInstallation,\n        private readonly InstallerJournal $journal,",
    1,
)
controller = controller.replace(
    "                    InstallerPhase::Operations => ['dispatch_queue_probe', 'continue_operations'],\n                    default => [],",
    "                    InstallerPhase::Operations => ['dispatch_queue_probe', 'continue_operations'],\n                    InstallerPhase::Finalize => ['finalize_installation'],\n                    default => [],",
    1,
)

method_anchor = "    /**\n     * @return array{pending_count: int|null, pending: list<string>, complete: bool, available: bool}|null\n     */\n    private function migrationStatus"
if method_anchor not in controller:
    raise SystemExit('controller method anchor missing')
finalize_method = """    public function finalize(Request $request): RedirectResponse
    {
        if ($this->status->snapshot()['phase'] !== InstallerPhase::Finalize) {
            return redirect('/install')->withErrors([
                'finalize' => 'Installation is not ready for finalization.',
            ]);
        }

        try {
            $owner = $this->finalizeInstallation->handle();
        } catch (Throwable) {
            return redirect('/install')->withErrors([
                'finalize' => 'Installation could not be finalized safely. Review the current checks and retry.',
            ]);
        }

        Auth::login($owner);
        $request->session()->regenerate();

        return redirect('/dashboard');
    }

"""
controller = controller.replace(method_anchor, finalize_method + method_anchor, 1)
controller_path.write_text(controller)

middleware_path = Path('app/Http/Middleware/EnsureApplicationInstalled.php')
middleware = middleware_path.read_text()
middleware = middleware.replace(
    "use App\\Support\\InstallationState;\nuse App\\Support\\RuntimeApplicationKey;",
    "use App\\Support\\InstallationState;\nuse App\\Support\\Installer\\InstallerPhase;\nuse App\\Support\\Installer\\InstallerStatus;\nuse App\\Support\\RuntimeApplicationKey;",
    1,
)
middleware = middleware.replace(
    "        private InstallationState $installationState,\n        private RuntimeApplicationKey $runtimeApplicationKey,",
    "        private InstallationState $installationState,\n        private InstallerStatus $installerStatus,\n        private RuntimeApplicationKey $runtimeApplicationKey,",
    1,
)
middleware = middleware.replace(
    "            $this->configureInstallerRuntime();",
    "            $this->configureInstallerRuntime(\n                $this->installerStatus->snapshot()['phase'] === InstallerPhase::Finalize,\n            );",
    1,
)
middleware = middleware.replace(
    "    private function configureInstallerRuntime(): void\n    {\n        config([\n            'session.driver' => 'file',",
    "    private function configureInstallerRuntime(bool $finalizing): void\n    {\n        config([\n            'session.driver' => $finalizing ? 'database' : 'file',",
    1,
)
middleware_path.write_text(middleware)

routes_path = Path('routes/web.php')
routes = routes_path.read_text()
route_anchor = "Route::post('install/operations/continue', [InstallerController::class, 'continueOperations'])\n    ->middleware('throttle:6,1')\n    ->name('install.operations.continue');"
if route_anchor not in routes:
    raise SystemExit('route anchor missing')
routes = routes.replace(
    route_anchor,
    route_anchor + "\nRoute::post('install/finalize', [InstallerController::class, 'finalize'])\n    ->middleware('throttle:6,1')\n    ->name('install.finalize');",
    1,
)
routes_path.write_text(routes)
