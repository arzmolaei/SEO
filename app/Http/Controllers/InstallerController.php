<?php

namespace App\Http\Controllers;

use App\Support\Installer\DatabaseVerificationResult;
use App\Support\Installer\DatabaseVerifier;
use App\Support\Installer\EnvironmentWriter;
use App\Support\Installer\InstallerEnvironment;
use App\Support\Installer\InstallerJournal;
use App\Support\Installer\InstallerMutex;
use App\Support\Installer\InstallerPhase;
use App\Support\Installer\InstallerPreflight;
use App\Support\Installer\InstallerStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class InstallerController extends Controller
{
    public function __construct(
        private readonly InstallerPreflight $preflight,
        private readonly InstallerStatus $status,
        private readonly InstallerEnvironment $environment,
        private readonly EnvironmentWriter $environmentWriter,
        private readonly DatabaseVerifier $databaseVerifier,
        private readonly InstallerJournal $journal,
        private readonly InstallerMutex $mutex,
    ) {}

    public function index(): Response
    {
        $snapshot = $this->status->snapshot();
        $phase = $snapshot['phase'];

        return Inertia::render('install/index', [
            'installer' => [
                'phase' => $phase->value,
                'installed' => $snapshot['installed'],
                'values' => $phase === InstallerPhase::Preflight
                    ? []
                    : $this->environment->publicValues(),
                'actions' => match ($phase) {
                    InstallerPhase::Preflight => ['refresh_preflight'],
                    InstallerPhase::Database => ['save_environment', 'verify_database'],
                    default => [],
                },
            ],
            'preflight' => $this->preflight->check(),
        ]);
    }

    public function saveEnvironment(Request $request): RedirectResponse
    {
        if (! $this->preflight->check()['ready']) {
            return redirect('/install')->withErrors([
                'preflight' => 'Host preflight must pass before environment settings can be saved.',
            ]);
        }

        $validated = $request->validate([
            'app_url' => ['required', 'string', 'max:2048'],
            'database_driver' => ['required', 'string', 'in:mysql,mariadb'],
            'database_host' => ['required', 'string', 'max:255'],
            'database_port' => ['required', 'integer', 'between:1,65535'],
            'database_name' => ['required', 'string', 'max:128'],
            'database_username' => ['required', 'string', 'max:128'],
            'database_password' => ['nullable', 'string', 'max:4096'],
            'keep_database_password' => ['required', 'boolean'],
        ]);

        $environmentValues = [
            'app_url' => (string) $validated['app_url'],
            'database_driver' => (string) $validated['database_driver'],
            'database_host' => (string) $validated['database_host'],
            'database_port' => (int) $validated['database_port'],
            'database_name' => (string) $validated['database_name'],
            'database_username' => (string) $validated['database_username'],
        ];
        $password = isset($validated['database_password'])
            ? (string) $validated['database_password']
            : null;
        $keepPassword = (bool) $validated['keep_database_password'];

        try {
            $this->mutex->run(function () use ($environmentValues, $password, $keepPassword): void {
                $this->environmentWriter->persist($environmentValues, $password, $keepPassword);
                $this->journal->replace([
                    'phase' => InstallerPhase::Database->value,
                    'environment_updated_at' => now()->toIso8601String(),
                ]);
            });
        } catch (InvalidArgumentException $exception) {
            return redirect('/install')->withErrors([
                'environment' => $exception->getMessage(),
            ]);
        } catch (RuntimeException) {
            return redirect('/install')->withErrors([
                'environment' => 'Environment settings could not be saved safely.',
            ]);
        }

        return redirect('/install');
    }

    public function verifyDatabase(): RedirectResponse
    {
        $result = $this->mutex->run(function (): DatabaseVerificationResult {
            if ($this->status->snapshot()['phase'] !== InstallerPhase::Database) {
                return DatabaseVerificationResult::failure(
                    'invalid_phase',
                    'Database verification is not available in the current installer phase.',
                    [
                        'driver' => '',
                        'host' => '',
                        'port' => 0,
                        'database' => '',
                    ],
                );
            }

            $values = $this->environment->values();
            $connection = [
                'driver' => $values['DB_CONNECTION'] ?? '',
                'host' => $values['DB_HOST'] ?? '',
                'port' => (int) ($values['DB_PORT'] ?? 0),
                'database' => $values['DB_DATABASE'] ?? '',
                'username' => $values['DB_USERNAME'] ?? '',
                'password' => $values['DB_PASSWORD'] ?? '',
            ];
            $verification = $this->databaseVerifier->verify($connection);

            if ($verification->verified && $verification->fingerprint !== null) {
                $this->journal->replace([
                    'phase' => InstallerPhase::Migrations->value,
                    'database_fingerprint' => $verification->fingerprint,
                    'database_facts' => $verification->facts,
                    'database_verified_at' => now()->toIso8601String(),
                ]);
            }

            return $verification;
        });

        if (! $result->verified) {
            return redirect('/install')
                ->withErrors(['database' => $result->message])
                ->with('database_verification_code', $result->code);
        }

        return redirect('/install');
    }
}
