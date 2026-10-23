<?php

namespace App\Services\Backup;

use App\Support\Backup\BackupBundleReader;
use App\Support\InstallationState;
use App\Support\Lifecycle\LifecycleMutex;
use App\Support\Lifecycle\LifecycleMutexHandle;
use App\Support\Lifecycle\MaintenanceWindow;
use App\Support\Lifecycle\ProductVersion;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

final class RecoverInterruptedRestore
{
    public function __construct(
        private readonly BackupBundleReader $reader,
        private readonly ApplicationTableCatalog $catalog,
        private readonly ApplicationSchemaFingerprint $fingerprint,
        private readonly DatabaseRowCodec $codec,
        private readonly ProductVersion $productVersion,
        private readonly EnvironmentSettingsUpdater $environmentSettingsUpdater,
        private readonly InstallationState $installationState,
        private readonly RestoreJournal $journal,
        private readonly LifecycleMutex $mutex,
        private readonly MaintenanceWindow $maintenanceWindow,
    ) {}

    public function handle(): void
    {
        $uploadId = $this->recoveryUploadId();
        $ownership = null;

        try {
            $ownership = $this->mutex->acquire('restore-recovery');

            $this->maintenanceWindow->run('restore-recovery', function () use ($ownership, $uploadId): void {
                $this->recover($ownership, $uploadId);
            });
        } catch (BackupException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->recoveryFailed($exception);
        } finally {
            if ($ownership instanceof LifecycleMutexHandle) {
                $ownership->release();
            }
        }
    }

    private function recover(LifecycleMutexHandle $ownership, string $uploadId): void
    {
        $ownership->assertActive();

        if ($this->recoveryUploadId() !== $uploadId) {
            throw $this->invalidState();
        }

        $stateDirectory = $this->restoreStateDirectory();
        $safetyDirectory = $stateDirectory.DIRECTORY_SEPARATOR.'safety';
        $safetyBundlePath = $safetyDirectory.DIRECTORY_SEPARATOR.$uploadId.'.seobak';
        $safetyMetadataPath = $safetyDirectory.DIRECTORY_SEPARATOR.$uploadId.'.json';
        $recoveryRoot = $stateDirectory.DIRECTORY_SEPARATOR.'recovery'.DIRECTORY_SEPARATOR.$uploadId;

        if (
            is_link($safetyBundlePath)
            || ! is_file($safetyBundlePath)
            || is_link($safetyMetadataPath)
            || ! is_file($safetyMetadataPath)
        ) {
            throw $this->invalidState();
        }

        $installationSnapshot = $this->loadInstallationSnapshot($safetyMetadataPath);

        try {
            $manifest = $this->stageSafetyBundle($safetyBundlePath, $recoveryRoot);
            $this->restoreDatabase($manifest, $recoveryRoot);
            $this->restoreDurableFiles($recoveryRoot);
            $this->restoreRuntimeSettings($recoveryRoot);
            $this->installationState->restoreRecoverySnapshot($installationSnapshot);
            $this->clearVolatileTables();

            $this->cleanupRecoveryArtifacts(
                $uploadId,
                $recoveryRoot,
                $safetyBundlePath,
                $safetyMetadataPath,
            );
            $this->journal->clear();
        } catch (BackupException $exception) {
            $this->cleanupTransientRecoveryStaging($recoveryRoot);

            throw $exception;
        } catch (Throwable $exception) {
            $this->cleanupTransientRecoveryStaging($recoveryRoot);

            throw $this->recoveryFailed($exception);
        }
    }

    private function recoveryUploadId(): string
    {
        $state = $this->journal->state();
        $uploadId = $state['upload_id'] ?? null;

        if (
            ! is_array($state)
            || ($state['phase'] ?? null) !== 'recovery_required'
            || ! is_string($uploadId)
            || preg_match('/\A[a-f0-9]{32}\z/', $uploadId) !== 1
        ) {
            throw $this->invalidState();
        }

        return $uploadId;
    }

    /** @return array<string, mixed> */
    private function loadInstallationSnapshot(string $path): array
    {
        $raw = @file_get_contents($path);
        if (! is_string($raw)) {
            throw $this->invalidState();
        }

        try {
            $snapshot = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw $this->recoveryFailed($exception);
        }

        if (! is_array($snapshot)) {
            throw $this->invalidState();
        }

        return $snapshot;
    }

    private function stageSafetyBundle(string $bundlePath, string $recoveryRoot): BackupManifest
    {
        if (file_exists($recoveryRoot) || is_link($recoveryRoot)) {
            $this->removePathStrict($recoveryRoot);
        }
        $this->ensureDirectory($recoveryRoot);

        $stream = @fopen($bundlePath, 'rb');
        if ($stream === false) {
            throw $this->invalidState();
        }

        try {
            $manifest = $this->reader->read($stream, function (string $path, string $chunk) use ($recoveryRoot): void {
                $target = $this->stagedPath($recoveryRoot, $path);
                $this->ensureDirectory(dirname($target));

                if (is_link($target) || file_put_contents($target, $chunk, FILE_APPEND | LOCK_EX) === false) {
                    throw $this->recoveryFailed();
                }
                @chmod($target, 0600);
            });
        } finally {
            fclose($stream);
        }

        $manifest->assertCompatible(
            $this->productVersion->requireDistributable(),
            $this->fingerprint->forConnection(DB::connection()),
        );

        $entries = $manifest->toArray()['entries'] ?? null;
        if (! is_array($entries)) {
            throw $this->invalidState();
        }

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_string($entry['path'] ?? null)) {
                throw $this->invalidState();
            }

            $target = $this->stagedPath($recoveryRoot, $entry['path']);
            if (! file_exists($target)) {
                $this->ensureDirectory(dirname($target));
                if (file_put_contents($target, '') === false) {
                    throw $this->recoveryFailed();
                }
                @chmod($target, 0600);
            }
        }

        return $manifest;
    }

    private function restoreDatabase(BackupManifest $manifest, string $recoveryRoot): void
    {
        $connection = DB::connection();
        $metadata = $this->catalog->metadata($connection);
        $familiesByTable = [];

        foreach ($metadata as $table) {
            $families = [];
            foreach ($table['columns'] as $column) {
                $families[$column['name']] = $column['family'];
            }
            $familiesByTable[$table['name']] = $families;
        }

        $recordCounts = [];
        $entries = $manifest->toArray()['entries'] ?? null;
        if (! is_array($entries)) {
            throw $this->invalidState();
        }

        foreach ($entries as $entry) {
            if (
                is_array($entry)
                && is_string($entry['path'] ?? null)
                && is_int($entry['record_count'] ?? null)
            ) {
                $recordCounts[$entry['path']] = $entry['record_count'];
            }
        }

        $connection->beginTransaction();

        try {
            foreach ($this->catalog->deleteOrder() as $table) {
                $connection->table($table)->delete();
            }
            $this->clearVolatileTables($connection);

            foreach ($this->catalog->insertOrder() as $table) {
                $path = $this->catalog->entryPath($table);
                $families = $familiesByTable[$table] ?? null;
                $recordCount = $recordCounts[$path] ?? null;

                if (! is_array($families) || ! is_int($recordCount)) {
                    throw $this->invalidState();
                }

                $stream = @fopen($this->stagedPath($recoveryRoot, $path), 'rb');
                if ($stream === false) {
                    throw $this->invalidState();
                }

                $count = 0;
                try {
                    while (($line = fgets($stream)) !== false) {
                        $line = rtrim($line, "\r\n");
                        if ($line === '') {
                            throw $this->invalidState();
                        }

                        try {
                            $encoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                        } catch (JsonException $exception) {
                            throw $this->recoveryFailed($exception);
                        }

                        if (! is_array($encoded)) {
                            throw $this->invalidState();
                        }

                        $connection->table($table)->insert(
                            $this->codec->decodeRow($encoded, $families),
                        );
                        $count++;
                    }

                    if (! feof($stream) || $count !== $recordCount) {
                        throw $this->invalidState();
                    }
                } finally {
                    fclose($stream);
                }
            }

            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    private function restoreDurableFiles(string $recoveryRoot): void
    {
        $roots = config('backup.durable_file_roots', []);
        if (! is_array($roots)) {
            throw $this->invalidState();
        }

        foreach ($roots as $archiveRoot => $filesystemRoot) {
            if (! is_string($archiveRoot) || ! is_string($filesystemRoot)) {
                throw $this->invalidState();
            }

            if (is_link($filesystemRoot)) {
                throw $this->invalidState();
            }

            if (file_exists($filesystemRoot)) {
                if (! is_dir($filesystemRoot)) {
                    throw $this->invalidState();
                }
                $this->removePathStrict($filesystemRoot);
            }

            $source = $this->stagedPath($recoveryRoot, rtrim($archiveRoot, '/'));
            $this->ensureDirectory(dirname($filesystemRoot));

            if (is_dir($source) && ! is_link($source)) {
                if (! @rename($source, $filesystemRoot)) {
                    throw $this->recoveryFailed();
                }
                @chmod($filesystemRoot, 0700);

                continue;
            }

            if (file_exists($source) || is_link($source)) {
                throw $this->invalidState();
            }

            $this->ensureDirectory($filesystemRoot);
        }
    }

    private function restoreRuntimeSettings(string $recoveryRoot): void
    {
        $raw = @file_get_contents($this->stagedPath($recoveryRoot, 'settings/runtime.json'));
        if (! is_string($raw)) {
            throw $this->invalidState();
        }

        try {
            $settings = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw $this->recoveryFailed($exception);
        }

        if (! is_array($settings)) {
            throw $this->invalidState();
        }

        $this->environmentSettingsUpdater->apply($settings);
    }

    private function clearVolatileTables(?Connection $connection = null): void
    {
        $connection ??= DB::connection();

        foreach ($this->catalog->excludedTables() as $table) {
            if ($table === 'migrations') {
                continue;
            }

            $connection->table($table)->delete();
        }
    }

    private function cleanupRecoveryArtifacts(
        string $uploadId,
        string $recoveryRoot,
        string $safetyBundlePath,
        string $safetyMetadataPath,
    ): void {
        $stateDirectory = $this->restoreStateDirectory();
        $originalStagingRoot = $stateDirectory.DIRECTORY_SEPARATOR.'staging'.DIRECTORY_SEPARATOR.$uploadId;

        foreach ([
            $originalStagingRoot,
            $recoveryRoot,
            $this->uploadedBundlePath($uploadId),
            $safetyMetadataPath,
            $safetyBundlePath,
        ] as $path) {
            if (file_exists($path) || is_link($path)) {
                $this->removePathStrict($path);
            }
        }

        foreach (['staging', 'recovery', 'safety'] as $directory) {
            $this->removeEmptyDirectory($stateDirectory.DIRECTORY_SEPARATOR.$directory);
        }
    }

    private function cleanupTransientRecoveryStaging(string $recoveryRoot): void
    {
        try {
            if (file_exists($recoveryRoot) || is_link($recoveryRoot)) {
                $this->removePathStrict($recoveryRoot);
            }
            $this->removeEmptyDirectory(dirname($recoveryRoot));
        } catch (Throwable) {
            // Keep the original recovery failure authoritative. A later recovery attempt
            // removes stale recovery staging before reading the protected safety snapshot.
        }
    }

    private function removePathStrict(string $path): void
    {
        if (is_link($path)) {
            throw $this->invalidState();
        }

        if (is_file($path)) {
            if (! @unlink($path)) {
                throw $this->recoveryFailed();
            }

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            throw $this->recoveryFailed();
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->removePathStrict($path.DIRECTORY_SEPARATOR.$item);
        }

        if (! @rmdir($path)) {
            throw $this->recoveryFailed();
        }
    }

    private function removeEmptyDirectory(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            throw $this->recoveryFailed();
        }

        if (array_values(array_diff($items, ['.', '..'])) === [] && ! @rmdir($path)) {
            throw $this->recoveryFailed();
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (is_link($path)) {
            throw $this->invalidState();
        }

        if (! is_dir($path) && ! @mkdir($path, 0700, true) && ! is_dir($path)) {
            throw $this->recoveryFailed();
        }
        @chmod($path, 0700);
    }

    private function stagedPath(string $recoveryRoot, string $archivePath): string
    {
        return $recoveryRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $archivePath);
    }

    private function uploadedBundlePath(string $uploadId): string
    {
        $directory = (string) config(
            'backup.restore.upload_path',
            storage_path('app/lifecycle/restore/uploads'),
        );

        return $directory.DIRECTORY_SEPARATOR.$uploadId.'.seobak';
    }

    private function restoreStateDirectory(): string
    {
        $journalPath = (string) config(
            'backup.restore.journal_path',
            storage_path('app/lifecycle/restore/state.json'),
        );

        return dirname($journalPath);
    }

    private function invalidState(): BackupException
    {
        return new BackupException(
            'restore_recovery_invalid_state',
            'وضعیت لازم برای بازیابی ایمن در دسترس نیست.',
        );
    }

    private function recoveryFailed(?Throwable $previous = null): BackupException
    {
        return new BackupException(
            'restore_recovery_failed',
            'بازگردانی ایمن وضعیت قبلی کامل نشد و باید دوباره تلاش شود.',
            $previous,
        );
    }
}
