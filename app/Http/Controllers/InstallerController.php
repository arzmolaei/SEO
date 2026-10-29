<?php

namespace App\Http\Controllers;

use App\Actions\Installer\FinalizeInstallation;
use App\Support\Installer\CreateInitialOwner;
use App\Support\Installer\DatabaseVerificationResult;
use App\Support\Installer\DatabaseVerifier;
use App\Support\Installer\EnvironmentWriter;
use App\Support\Installer\InstallerEnvironment;
use App\Support\Installer\InstallerJournal;
use App\Support\Installer\InstallerMigrationFailed;
use App\Support\Installer\InstallerMigrationRunner;
use App\Support\Installer\InstallerMutex;
use App\Support\Installer\InstallerPhase;
use App\Support\Installer\InstallerPreflight;
use App\Support\Installer\InstallerStatus;
use App\Support\Operations\OperationalHealth;
use App\Support\Operations\QueueProbe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class InstallerController extends Controller
{
    public function __construct(
        private readonly InstallerPreflight $preflight,
        private readonly InstallerStatus $status,
        private readonly InstallerEnvironment $environment,
        private readonly EnvironmentWriter $environmentWriter,
        private readonly DatabaseVerifier $databaseVerifier,
        private readonly InstallerMigrationRunner $migrationRunner,
        private readonly CreateInitialOwner $initialOwner,
        private readonly OperationalHealth $operationalHealth,
        private readonly QueueProbe $queueProbe,
        private readonly FinalizeInstallation $finalizeInstallation,
        private readonly InstallerJournal $journal,
        private readonly InstallerMutex $mutex,
    ) {}

    public function index(): Response
    {
        $snapshot = $this->status->snapshot();
        $preflight = $this->preflight->check();
        $durablePhase = $snapshot['phase'];
        $displayPhase = $durablePhase === InstallerPhase::Preflight && $preflight['ready']
            ? InstallerPhase::Environment
            : $durablePhase;
        $migrations = $this->migrationStatus($displayPhase);
        $operations = $displayPhase === InstallerPhase::Operations
            ? $this->operationalHealth->snapshot()
            : null;

        return Inertia::render('install/index', [
            'installer' => [
                'phase' => $displayPhase->value,
                'installed' => $snapshot['installed'],
                'values' => in_array($displayPhase, [InstallerPhase::Preflight, InstallerPhase::Environment], true)
                    ? []
                    : $this->environment->publicValues(),
                'actions' => match ($displayPhase) {
                    InstallerPhase::Preflight => ['refresh_preflight'],
                    InstallerPhase::Environment => ['refresh_preflight', 'save_environment'],
                    InstallerPhase::Database => ['save_environment', 'verify_database'],
                    InstallerPhase::Migrations => ['run_migrations'],
                    InstallerPhase::Owner => ['create_owner'],
                    InstallerPhase::Operations => ['dispatch_queue_probe', 'continue_operations'],
                    InstallerPhase::Finalize => ['finalize_installation'],
                    default => [],
                },
            ],
            'preflight' => $preflight,
            'migrations' => $migrations,
            'operations' => $operations,
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

            $verification = $this->databaseVerifier->verify($this->databaseConnection());

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

    public function runMigrations(): RedirectResponse
    {
        try {
            $error = $this->mutex->run(function (): ?string {
                if ($this->status->snapshot()['phase'] !== InstallerPhase::Migrations) {
                    return 'Migrations are not available in the current installer phase.';
                }

                $journal = $this->journal->read();
                $storedFingerprint = $journal['database_fingerprint'] ?? null;
                $currentFingerprint = $this->databaseVerifier->fingerprint($this->databaseConnection());

                if (! is_string($storedFingerprint) || ! hash_equals($storedFingerprint, $currentFingerprint)) {
                    $replacement = [
                        'phase' => InstallerPhase::Database->value,
                    ];

                    if (isset($journal['environment_updated_at']) && is_string($journal['environment_updated_at'])) {
                        $replacement['environment_updated_at'] = $journal['environment_updated_at'];
                    }

                    $this->journal->replace($replacement);

                    return 'Database settings changed or are no longer verified. Verify the database again before running migrations.';
                }

                try {
                    $result = $this->migrationRunner->runPending();
                } catch (InstallerMigrationFailed $exception) {
                    report($exception);

                    return 'Database migrations could not be completed. Retrying is safe. Reason: '.$exception->reason();
                } catch (Throwable $exception) {
                    report($exception);

                    return 'Database migrations could not be completed. Retrying is safe.';
                }

                $next = $journal;
                $next['migrations_last_attempted_at'] = now()->toIso8601String();
                unset($next['migrations_completed_at']);

                if ($result['complete']) {
                    $next['phase'] = InstallerPhase::Owner->value;
                    $next['migrations_completed_at'] = now()->toIso8601String();
                    unset($next['migrations_pending_count']);
                } else {
                    $next['phase'] = InstallerPhase::Migrations->value;
                    $next['migrations_pending_count'] = count($result['remaining']);
                }

                $this->journal->replace($next);

                return null;
            });
        } catch (Throwable $exception) {
            report($exception);
            $error = 'Database migrations could not be completed. Retrying is safe.';
        }

        if ($error !== null) {
            return redirect('/install')->withErrors(['migrations' => $error]);
        }

        return redirect('/install');
    }

    public function createOwner(Request $request): RedirectResponse
    {
        try {
            $error = $this->mutex->run(function () use ($request): ?string {
                $phase = $this->status->snapshot()['phase'];
                $journal = $this->journal->read();
                $recordedOwnerId = $journal['owner_user_id'] ?? null;
                $recordedRetry = $phase === InstallerPhase::Operations && is_int($recordedOwnerId);

                if ($phase !== InstallerPhase::Owner && ! $recordedRetry) {
                    return 'Initial owner creation is not available in the current installer phase.';
                }

                try {
                    $owner = $this->initialOwner->create($request->only([
                        'name',
                        'email',
                        'workspace_name',
                        'password',
                        'password_confirmation',
                    ]));
                } catch (RuntimeException) {
                    return 'Existing users or ownership data do not match this installer request.';
                }

                if ($recordedRetry && $recordedOwnerId !== $owner->id) {
                    return 'Existing ownership data do not match the recorded installer owner.';
                }

                $next = $journal;
                $next['phase'] = InstallerPhase::Operations->value;
                $next['owner_user_id'] = $owner->id;
                $next['owner_created_at'] = isset($journal['owner_created_at']) && is_string($journal['owner_created_at'])
                    ? $journal['owner_created_at']
                    : now()->toIso8601String();
                $this->journal->replace($next);

                return null;
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $error = 'Initial owner account could not be created safely.';
        }

        if ($error !== null) {
            return redirect('/install')->withErrors(['owner' => $error]);
        }

        return redirect('/install');
    }

    public function dispatchQueueProbe(): RedirectResponse
    {
        try {
            $error = $this->mutex->run(function (): ?string {
                if ($this->status->snapshot()['phase'] !== InstallerPhase::Operations) {
                    return 'Queue verification is not available in the current installer phase.';
                }

                try {
                    $probeId = $this->queueProbe->dispatch();
                } catch (Throwable) {
                    return 'Queue probe could not be dispatched safely.';
                }

                $next = $this->journal->read();
                $next['phase'] = InstallerPhase::Operations->value;
                $next['queue_probe_id'] = $probeId;
                $next['queue_probe_dispatched_at'] = now()->toIso8601String();
                unset(
                    $next['operations_verified_at'],
                    $next['operations_warnings_acknowledged_at'],
                    $next['operations_checked_at'],
                    $next['operations_scheduler_state'],
                    $next['operations_queue_state'],
                );
                $this->journal->replace($next);

                return null;
            });
        } catch (Throwable) {
            $error = 'Queue probe could not be dispatched safely.';
        }

        if ($error !== null) {
            return redirect('/install')->withErrors(['operations' => $error]);
        }

        return redirect('/install');
    }

    public function continueOperations(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'acknowledge_warnings' => ['required', 'boolean'],
        ]);
        $acknowledgeWarnings = (bool) $validated['acknowledge_warnings'];

        try {
            $error = $this->mutex->run(function () use ($acknowledgeWarnings): ?string {
                if ($this->status->snapshot()['phase'] !== InstallerPhase::Operations) {
                    return 'Operational activation is not available in the current installer phase.';
                }

                $operations = $this->operationalHealth->snapshot();
                if ($operations['blocking']) {
                    return 'A blocking scheduler or queue failure must be fixed before installation can continue.';
                }

                $hasWarnings = $operations['scheduler']['state'] === 'warning'
                    || $operations['queue']['state'] === 'warning';

                if ($hasWarnings && ! $acknowledgeWarnings) {
                    return 'Scheduler or queue execution has not been observed yet. Acknowledge the warning only after configuring the shown commands.';
                }

                $next = $this->journal->read();
                $next['phase'] = InstallerPhase::Finalize->value;
                $next['operations_checked_at'] = now()->toIso8601String();
                $next['operations_scheduler_state'] = $operations['scheduler']['state'];
                $next['operations_queue_state'] = $operations['queue']['state'];

                if ($hasWarnings) {
                    $next['operations_warnings_acknowledged_at'] = now()->toIso8601String();
                    unset($next['operations_verified_at']);
                } else {
                    $next['operations_verified_at'] = now()->toIso8601String();
                    unset($next['operations_warnings_acknowledged_at']);
                }

                $this->journal->replace($next);

                return null;
            });
        } catch (Throwable) {
            $error = 'Operational activation could not be completed safely.';
        }

        if ($error !== null) {
            return redirect('/install')->withErrors(['operations' => $error]);
        }

        return redirect('/install');
    }

    public function finalize(Request $request): RedirectResponse
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

    /**
     * @return array{pending_count: int|null, pending: list<string>, complete: bool, available: bool}|null
     */
    private function migrationStatus(InstallerPhase $phase): ?array
    {
        if ($phase !== InstallerPhase::Migrations) {
            return null;
        }

        try {
            $status = $this->migrationRunner->status();

            return [
                'pending_count' => count($status['pending']),
                'pending' => array_slice($status['pending'], 0, 20),
                'complete' => $status['complete'],
                'available' => true,
            ];
        } catch (Throwable) {
            return [
                'pending_count' => null,
                'pending' => [],
                'complete' => false,
                'available' => false,
            ];
        }
    }

    /** @return array{driver: string, host: string, port: int, database: string, username: string, password: string} */
    private function databaseConnection(): array
    {
        $values = $this->environment->values();

        return [
            'driver' => $values['DB_CONNECTION'] ?? '',
            'host' => $values['DB_HOST'] ?? '',
            'port' => (int) ($values['DB_PORT'] ?? 0),
            'database' => $values['DB_DATABASE'] ?? '',
            'username' => $values['DB_USERNAME'] ?? '',
            'password' => $values['DB_PASSWORD'] ?? '',
        ];
    }
}
