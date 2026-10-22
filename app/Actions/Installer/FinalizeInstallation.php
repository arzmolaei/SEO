<?php

namespace App\Actions\Installer;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use App\Support\InstallationState;
use App\Support\Installer\DatabaseVerifier;
use App\Support\Installer\InstallerEnvironment;
use App\Support\Installer\InstallerJournal;
use App\Support\Installer\InstallerMigrationRunner;
use App\Support\Installer\InstallerMutex;
use App\Support\Installer\InstallerPreflight;
use App\Support\Operations\OperationalHealth;
use App\Support\RuntimeApplicationKey;
use RuntimeException;
use Throwable;

class FinalizeInstallation
{
    public function __construct(
        private readonly InstallerPreflight $preflight,
        private readonly InstallerJournal $journal,
        private readonly InstallerMutex $mutex,
        private readonly InstallerEnvironment $environment,
        private readonly DatabaseVerifier $databaseVerifier,
        private readonly InstallerMigrationRunner $migrationRunner,
        private readonly OperationalHealth $operationalHealth,
        private readonly InstallationState $installationState,
        private readonly RuntimeApplicationKey $runtimeApplicationKey,
    ) {}

    public function handle(): User
    {
        return $this->mutex->run(fn (): User => $this->finalizeLocked());
    }

    private function finalizeLocked(): User
    {
        if ($this->installationState->isInstalled()) {
            throw new RuntimeException('Application is already installed.');
        }

        $journal = $this->journal->read();
        if (($journal['phase'] ?? null) !== 'finalize') {
            throw new RuntimeException('Installer is not ready for finalization.');
        }

        if (! $this->preflight->check()['ready']) {
            throw new RuntimeException('Host preflight is no longer ready.');
        }

        $environment = $this->environment->values();
        $persistentKey = trim((string) ($environment['APP_KEY'] ?? ''));
        $runtimeKey = trim((string) config('app.key', ''));

        if (
            $persistentKey === ''
            || $runtimeKey === ''
            || ! hash_equals($runtimeKey, $persistentKey)
            || ! $this->runtimeApplicationKey->persistentKeyIsCompatible($persistentKey)
        ) {
            throw new RuntimeException('Persistent application key does not match the active installer key.');
        }

        $connection = $this->databaseConnection($environment);
        $verification = $this->databaseVerifier->verify($connection);
        $expectedFingerprint = trim((string) ($journal['database_fingerprint'] ?? ''));

        if (
            ! $verification->verified
            || $verification->fingerprint === null
            || $expectedFingerprint === ''
            || ! hash_equals($expectedFingerprint, $verification->fingerprint)
        ) {
            throw new RuntimeException('Database verification evidence is no longer valid.');
        }

        if (! $this->migrationRunner->status()['complete']) {
            throw new RuntimeException('Database migrations are not complete.');
        }

        $owner = $this->validatedOwner($journal);
        $this->assertOperationsCanFinalize($journal);

        $this->installationState->markInstalled([
            'installer_schema_version' => 2,
            'product_version' => $this->productVersion(),
            'installation_id' => bin2hex(random_bytes(16)),
            'database_fingerprint' => $verification->fingerprint,
        ]);

        $this->cleanupTransientInstallerState($persistentKey);

        return $owner;
    }

    /** @param array<string, mixed> $journal */
    private function validatedOwner(array $journal): User
    {
        $ownerId = filter_var($journal['owner_user_id'] ?? null, FILTER_VALIDATE_INT);
        if (! is_int($ownerId) || $ownerId < 1) {
            throw new RuntimeException('Installer owner evidence is missing.');
        }

        $owner = User::query()->find($ownerId);
        if (
            $owner === null
            || $owner->email_verified_at === null
            || $owner->onboarded_at === null
        ) {
            throw new RuntimeException('Installer owner account is not ready.');
        }

        $hasOwnerWorkspace = Membership::query()
            ->where('user_id', $owner->id)
            ->where('role', WorkspaceRole::Owner->value)
            ->whereHas('workspace')
            ->exists();

        if (! $hasOwnerWorkspace) {
            throw new RuntimeException('Installer owner workspace membership is invalid.');
        }

        return $owner;
    }

    /** @param array<string, mixed> $journal */
    private function assertOperationsCanFinalize(array $journal): void
    {
        $operations = $this->operationalHealth->snapshot();
        if ($operations['blocking']) {
            throw new RuntimeException('Operational health has a blocking failure.');
        }

        $verifiedPreviously = trim((string) ($journal['operations_verified_at'] ?? '')) !== '';
        $warningsAcknowledged = trim((string) ($journal['operations_warnings_acknowledged_at'] ?? '')) !== '';

        if (! $verifiedPreviously && ! $warningsAcknowledged) {
            $currentlyVerified = $operations['scheduler']['state'] === 'verified'
                && $operations['queue']['state'] === 'verified';

            if (! $currentlyVerified) {
                throw new RuntimeException('Operational warnings have not been acknowledged.');
            }
        }
    }

    /**
     * @param  array<string, string>  $environment
     * @return array{driver: string, host: string, port: int, database: string, username: string, password: string}
     */
    private function databaseConnection(array $environment): array
    {
        $driver = trim((string) ($environment['DB_CONNECTION'] ?? ''));
        $host = trim((string) ($environment['DB_HOST'] ?? ''));
        $port = (int) ($environment['DB_PORT'] ?? 0);
        $database = trim((string) ($environment['DB_DATABASE'] ?? ''));
        $username = trim((string) ($environment['DB_USERNAME'] ?? ''));

        if (
            ! in_array($driver, ['mysql', 'mariadb'], true)
            || $host === ''
            || $port < 1
            || $port > 65535
            || $database === ''
            || $username === ''
            || ! array_key_exists('DB_PASSWORD', $environment)
        ) {
            throw new RuntimeException('Persisted database configuration is incomplete.');
        }

        return [
            'driver' => $driver,
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => (string) $environment['DB_PASSWORD'],
        ];
    }

    private function productVersion(): string
    {
        $version = trim((string) config('installer.product_version', 'dev'));

        return $version !== '' ? $version : 'dev';
    }

    private function cleanupTransientInstallerState(string $persistentKey): void
    {
        foreach ([
            fn (): null => $this->discardRuntimeKey($persistentKey),
            fn (): null => $this->clearJournal(),
        ] as $cleanup) {
            try {
                $cleanup();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function discardRuntimeKey(string $persistentKey): null
    {
        $this->runtimeApplicationKey->discardFallback($persistentKey);

        return null;
    }

    private function clearJournal(): null
    {
        $this->journal->clear();

        return null;
    }
}