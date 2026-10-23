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

final class RestoreSystemBackup
{
    public function __construct(
        private readonly BackupBundleReader $reader,
        private readonly ApplicationTableCatalog $catalog,
        private readonly ApplicationSchemaFingerprint $fingerprint,
        private readonly DatabaseRowCodec $codec,
        private readonly ProductVersion $productVersion,
        private readonly RestoreJournal $journal,
        private readonly CleanRestoreTarget $cleanRestoreTarget,
        private readonly CaptureSystemState $captureSystemState,
        private readonly EnvironmentSettingsUpdater $environmentSettingsUpdater,
        private readonly InstallationState $installationState,
        private readonly LifecycleMutex $mutex,
        private readonly MaintenanceWindow $maintenanceWindow,
    ) {}

    public function handle(string $expectedDigest): void
    {
        $state = $this->verifiedState($expectedDigest);
        $uploadId = $state['upload_id'];
        $ownership = null;

        try {
            $ownership = $this->mutex->acquire('restore');

            $this->maintenanceWindow->run('restore', function () use ($ownership, $uploadId, $expectedDigest): void {
                $this->performRestore($ownership, $uploadId, $expectedDigest);
            });
        } catch (BackupException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BackupException(
                'restore_failed',
                'بازیابی کامل سیستم انجام نشد.',
                $exception,
            );
        } finally {
            if ($ownership instanceof LifecycleMutexHandle) {
                $ownership->release();
            }
        }
    }

    private function performRestore(
        LifecycleMutexHandle $ownership,
        string $uploadId,
        string $expectedDigest,
    ): void {
        $ownership->assertActive();
        $this->verifiedState($expectedDigest);
        $this->cleanRestoreTarget->assertReadyForRestore($uploadId, $ownership);

        $this->journal->transition('verified', 'confirmed', [
            'confirmed_digest' => $expectedDigest,
        ]);

        [$safetyBundlePath, $safetyMetadataPath] = $this->captureSafetySnapshot($ownership, $uploadId);
        $stagingRoot = null;
        $databaseCommitted = false;

        try {
            [$manifest, $stagingRoot] = $this->stageVerifiedBundle($uploadId, $expectedDigest);

            $this->journal->transition('confirmed', 'database_restoring', [
                'safety_snapshot_path' => $safetyBundlePath,
                'safety_metadata_path' => $safetyMetadataPath,
            ]);

            $this->restoreDatabase($manifest, $stagingRoot);
            $databaseCommitted = true;
            $this->journal->transition('database_restoring', 'database_committed');

            $this->journal->transition('database_committed', 'files_restoring');
            $this->restoreDurableFiles($stagingRoot);

            $this->journal->transition('files_restoring', 'key_rotating');
            $this->restoreRuntimeSettings($stagingRoot);

            $this->journal->transition('key_rotating', 'finalizing');
            $restoredAt = gmdate('Y-m-d\TH:i:s\Z');
            $manifestData = $manifest->toArray();
            $sourceOwnerUserId = $manifestData['source_owner_user_id'] ?? null;
            $sourceInstallationId = $manifestData['source_installation_id'] ?? null;

            if (! is_int($sourceOwnerUserId) || $sourceOwnerUserId < 1 || ! is_string($sourceInstallationId)) {
                throw $this->invalidBundle();
            }

            $this->installationState->updateMetadata([
                'owner_user_id' => $sourceOwnerUserId,
                'restored_from_installation_id' => $sourceInstallationId,
                'restored_at' => $restoredAt,
            ]);
            $this->clearVolatileTables();

            $this->journal->transition('finalizing', 'complete', [
                'restored_at' => $restoredAt,
            ]);

            $this->cleanupPath($stagingRoot);
            $this->cleanupPath($safetyBundlePath);
            $this->cleanupPath($safetyMetadataPath);
            $this->cleanupPath($this->uploadedBundlePath($uploadId));
        } catch (Throwable $exception) {
            if ($databaseCommitted) {
                $this->markRecoveryRequired();
            } else {
                if (is_string($stagingRoot)) {
                    $this->cleanupPath($stagingRoot);
                }
                $this->cleanupPath($safetyBundlePath);
                $this->cleanupPath($safetyMetadataPath);
                $this->cleanupPath($this->uploadedBundlePath($uploadId));
                $this->journal->clear();
            }

            if ($exception instanceof BackupException) {
                throw $exception;
            }

            throw new BackupException(
                'restore_failed',
                'بازیابی کامل سیستم انجام نشد.',
                $exception,
            );
        }
    }

    /** @return array{upload_id:string, preview:array<string, mixed>} */
    private function verifiedState(string $expectedDigest): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $expectedDigest) !== 1) {
            throw $this->digestMismatch();
        }

        $state = $this->journal->state();
        $uploadId = $state['upload_id'] ?? null;
        $preview = $state['preview'] ?? null;

        if (
            ! is_array($state)
            || ($state['phase'] ?? null) !== 'verified'
            || ! is_string($uploadId)
            || preg_match('/\A[a-f0-9]{32}\z/', $uploadId) !== 1
            || ! is_array($preview)
            || ($preview['digest'] ?? null) !== $expectedDigest
        ) {
            throw $this->digestMismatch();
        }

        return [
            'upload_id' => $uploadId,
            'preview' => $preview,
        ];
    }

    /** @return array{string, string} */
    private function captureSafetySnapshot(LifecycleMutexHandle $ownership, string $uploadId): array
    {
        $directory = $this->restoreStateDirectory().DIRECTORY_SEPARATOR.'safety';
        $this->ensureDirectory($directory);
        $bundlePath = $directory.DIRECTORY_SEPARATOR.$uploadId.'.seobak';
        $metadataPath = $directory.DIRECTORY_SEPARATOR.$uploadId.'.json';

        if (file_exists($bundlePath) || file_exists($metadataPath)) {
            throw new BackupException(
                'restore_invalid_state',
                'وضعیت بازیابی معتبر نیست و ادامه عملیات متوقف شد.',
            );
        }

        $captured = $this->captureSystemState->handle($ownership);

        try {
            $captured->rewind();
            $target = fopen($bundlePath, 'x+b');
            if ($target === false) {
                throw $this->snapshotFailed();
            }

            try {
                @chmod($bundlePath, 0600);
                $this->copyStream($captured->stream(), $target);
                if (! fflush($target)) {
                    throw $this->snapshotFailed();
                }
            } finally {
                fclose($target);
            }
        } finally {
            $captured->close();
        }

        $details = $this->installationState->details();
        if (! is_array($details)) {
            $this->cleanupPath($bundlePath);
            throw $this->snapshotFailed();
        }

        try {
            $encoded = json_encode(
                $details,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            $this->cleanupPath($bundlePath);
            throw $this->snapshotFailed($exception);
        }

        if (file_put_contents($metadataPath, $encoded, LOCK_EX) === false || ! @chmod($metadataPath, 0600)) {
            $this->cleanupPath($bundlePath);
            $this->cleanupPath($metadataPath);
            throw $this->snapshotFailed();
        }

        return [$bundlePath, $metadataPath];
    }

    /** @return array{BackupManifest, string} */
    private function stageVerifiedBundle(string $uploadId, string $expectedDigest): array
    {
        $bundlePath = $this->uploadedBundlePath($uploadId);
        $actualDigest = is_file($bundlePath) ? hash_file('sha256', $bundlePath) : false;

        if (! is_string($actualDigest) || ! hash_equals($expectedDigest, $actualDigest)) {
            throw $this->digestMismatch();
        }

        $stagingRoot = $this->restoreStateDirectory().DIRECTORY_SEPARATOR.'staging'.DIRECTORY_SEPARATOR.$uploadId;
        if (file_exists($stagingRoot) || is_link($stagingRoot)) {
            throw new BackupException(
                'restore_invalid_state',
                'وضعیت بازیابی معتبر نیست و ادامه عملیات متوقف شد.',
            );
        }
        $this->ensureDirectory($stagingRoot);

        $stream = fopen($bundlePath, 'rb');
        if ($stream === false) {
            $this->cleanupPath($stagingRoot);
            throw $this->invalidBundle();
        }

        try {
            $manifest = $this->reader->read($stream, function (string $path, string $chunk) use ($stagingRoot): void {
                $target = $this->stagedPath($stagingRoot, $path);
                $this->ensureDirectory(dirname($target));

                if (file_put_contents($target, $chunk, FILE_APPEND | LOCK_EX) === false) {
                    throw $this->invalidBundle();
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
            $this->cleanupPath($stagingRoot);
            throw $this->invalidBundle();
        }

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_string($entry['path'] ?? null)) {
                $this->cleanupPath($stagingRoot);
                throw $this->invalidBundle();
            }

            $target = $this->stagedPath($stagingRoot, $entry['path']);
            if (! file_exists($target)) {
                $this->ensureDirectory(dirname($target));
                if (file_put_contents($target, '') === false) {
                    $this->cleanupPath($stagingRoot);
                    throw $this->invalidBundle();
                }
                @chmod($target, 0600);
            }
        }

        return [$manifest, $stagingRoot];
    }

    private function restoreDatabase(BackupManifest $manifest, string $stagingRoot): void
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
        $entries = $manifest->toArray()['entries'] ?? [];
        if (! is_array($entries)) {
            throw $this->invalidBundle();
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
                $stream = fopen($this->stagedPath($stagingRoot, $path), 'rb');
                if ($stream === false || ! isset($familiesByTable[$table], $recordCounts[$path])) {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                    throw $this->invalidBundle();
                }

                $count = 0;
                try {
                    while (($line = fgets($stream)) !== false) {
                        $line = rtrim($line, "\r\n");
                        if ($line === '') {
                            throw $this->invalidBundle();
                        }

                        try {
                            $encoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                        } catch (JsonException $exception) {
                            throw new BackupException(
                                'restore_invalid_bundle',
                                'فایل پشتیبان معتبر نیست.',
                                $exception,
                            );
                        }

                        if (! is_array($encoded)) {
                            throw $this->invalidBundle();
                        }

                        $connection->table($table)->insert(
                            $this->codec->decodeRow($encoded, $familiesByTable[$table]),
                        );
                        $count++;
                    }

                    if (! feof($stream) || $count !== $recordCounts[$path]) {
                        throw $this->invalidBundle();
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

    private function restoreDurableFiles(string $stagingRoot): void
    {
        $roots = config('backup.durable_file_roots', []);
        if (! is_array($roots)) {
            throw $this->invalidBundle();
        }

        foreach ($roots as $archiveRoot => $filesystemRoot) {
            if (! is_string($archiveRoot) || ! is_string($filesystemRoot)) {
                throw $this->invalidBundle();
            }

            if (is_link($filesystemRoot) || (file_exists($filesystemRoot) && ! is_dir($filesystemRoot))) {
                throw $this->invalidBundle();
            }

            if (is_dir($filesystemRoot) && ! @rmdir($filesystemRoot)) {
                throw new BackupException(
                    'restore_target_not_clean',
                    'این نصب برای بازیابی کامل خالی و آماده نیست.',
                );
            }

            $source = $this->stagedPath($stagingRoot, rtrim($archiveRoot, '/'));
            $this->ensureDirectory(dirname($filesystemRoot));

            if (is_dir($source)) {
                if (! @rename($source, $filesystemRoot)) {
                    throw new BackupException(
                        'restore_file_switch_failed',
                        'جایگزینی امن فایل‌های پایدار انجام نشد.',
                    );
                }
                @chmod($filesystemRoot, 0700);
            } else {
                $this->ensureDirectory($filesystemRoot);
            }
        }
    }

    private function restoreRuntimeSettings(string $stagingRoot): void
    {
        $path = $this->stagedPath($stagingRoot, 'settings/runtime.json');
        $raw = file_get_contents($path);
        if (! is_string($raw)) {
            throw $this->invalidBundle();
        }

        try {
            $settings = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BackupException(
                'restore_invalid_bundle',
                'فایل پشتیبان معتبر نیست.',
                $exception,
            );
        }

        if (! is_array($settings)) {
            throw $this->invalidBundle();
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

    private function markRecoveryRequired(): void
    {
        $state = $this->journal->state();
        $phase = $state['phase'] ?? null;

        if (! is_string($phase) || $phase === 'recovery_required') {
            return;
        }

        if (in_array($phase, [
            'database_restoring',
            'database_committed',
            'files_restoring',
            'key_rotating',
            'finalizing',
        ], true)) {
            $this->journal->transition($phase, 'recovery_required');
        }
    }

    /**
     * @param  resource  $source
     * @param  resource  $target
     */
    private function copyStream($source, $target): void
    {
        while (! feof($source)) {
            $chunk = fread($source, 64 * 1024);
            if ($chunk === false) {
                throw $this->snapshotFailed();
            }
            if ($chunk === '') {
                break;
            }

            $offset = 0;
            $length = strlen($chunk);
            while ($offset < $length) {
                $written = fwrite($target, substr($chunk, $offset));
                if ($written === false || $written === 0) {
                    throw $this->snapshotFailed();
                }
                $offset += $written;
            }
        }
    }

    private function stagedPath(string $stagingRoot, string $archivePath): string
    {
        return $stagingRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $archivePath);
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

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! @mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new BackupException(
                'restore_storage_unavailable',
                'فضای امن لازم برای بازیابی در دسترس نیست.',
            );
        }
        @chmod($path, 0700);
    }

    private function cleanupPath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->cleanupPath($path.DIRECTORY_SEPARATOR.$item);
        }

        @rmdir($path);
    }

    private function digestMismatch(): BackupException
    {
        return new BackupException(
            'restore_digest_mismatch',
            'فایل پشتیبان تأییدشده با درخواست بازیابی مطابقت ندارد.',
        );
    }

    private function invalidBundle(): BackupException
    {
        return new BackupException(
            'restore_invalid_bundle',
            'فایل پشتیبان معتبر نیست.',
        );
    }

    private function snapshotFailed(?Throwable $previous = null): BackupException
    {
        return new BackupException(
            'restore_safety_snapshot_failed',
            'ایجاد نسخه ایمنی قبل از بازیابی انجام نشد.',
            $previous,
        );
    }
}
