<?php

namespace App\Services\Backup;

use App\Support\Backup\BackupBundleReader;
use App\Support\InstallationState;
use App\Support\Lifecycle\LifecycleMutexHandle;
use App\Support\Lifecycle\ProductVersion;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

final class ApplySystemStateSnapshot
{
    public function __construct(
        private readonly BackupBundleReader $reader,
        private readonly ApplicationTableCatalog $catalog,
        private readonly ApplicationSchemaFingerprint $fingerprint,
        private readonly DatabaseRowCodec $codec,
        private readonly ProductVersion $productVersion,
        private readonly EnvironmentSettingsUpdater $environmentSettingsUpdater,
        private readonly InstallationState $installationState,
    ) {}

    public function handle(
        string $bundlePath,
        string $metadataPath,
        string $stagingRoot,
        LifecycleMutexHandle $ownership,
        bool $preserveQueuedWork = false,
        bool $preserveRuntimeSettings = false,
    ): void {
        $ownership->assertActive();

        if (
            is_link($bundlePath)
            || ! is_file($bundlePath)
            || is_link($metadataPath)
            || ! is_file($metadataPath)
        ) {
            throw $this->invalidState();
        }

        $installationSnapshot = $this->loadInstallationSnapshot($metadataPath);
        $manifest = $this->stageSafetyBundle($bundlePath, $stagingRoot, $ownership);

        if ($preserveRuntimeSettings) {
            $this->assertRuntimeSettingsUnchanged($stagingRoot);
        }

        $ownership->assertActive();
        $this->restoreDatabase($manifest, $stagingRoot, $ownership, $preserveQueuedWork);

        $ownership->assertActive();
        $this->restoreDurableFiles($stagingRoot);

        $ownership->assertActive();
        if (! $preserveRuntimeSettings) {
            $this->restoreRuntimeSettings($stagingRoot);
        }

        $ownership->assertActive();
        $this->installationState->restoreRecoverySnapshot($installationSnapshot);
        $this->clearVolatileTables(preserveQueuedWork: $preserveQueuedWork);

        $ownership->assertActive();
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

    private function stageSafetyBundle(
        string $bundlePath,
        string $stagingRoot,
        LifecycleMutexHandle $ownership,
    ): BackupManifest {
        $ownership->assertActive();

        if (file_exists($stagingRoot) || is_link($stagingRoot)) {
            $this->removePathStrict($stagingRoot);
        }
        $this->ensureDirectory($stagingRoot);

        $stream = @fopen($bundlePath, 'rb');
        if ($stream === false) {
            throw $this->invalidState();
        }

        try {
            $manifest = $this->reader->read($stream, function (string $path, string $chunk) use ($stagingRoot, $ownership): void {
                $ownership->assertActive();
                $target = $this->stagedPath($stagingRoot, $path);
                $this->ensureDirectory(dirname($target));

                if (is_link($target) || file_put_contents($target, $chunk, FILE_APPEND | LOCK_EX) === false) {
                    throw $this->recoveryFailed();
                }
                @chmod($target, 0600);
            });
        } finally {
            fclose($stream);
        }

        $ownership->assertActive();
        $manifest->assertCompatible(
            $this->productVersion->requireDistributable(),
            $this->fingerprint->forConnection(DB::connection()),
        );

        $entries = $manifest->toArray()['entries'] ?? null;
        if (! is_array($entries)) {
            throw $this->invalidState();
        }

        foreach ($entries as $entry) {
            $ownership->assertActive();

            if (! is_array($entry) || ! is_string($entry['path'] ?? null)) {
                throw $this->invalidState();
            }

            $target = $this->stagedPath($stagingRoot, $entry['path']);
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

    private function restoreDatabase(
        BackupManifest $manifest,
        string $stagingRoot,
        LifecycleMutexHandle $ownership,
        bool $preserveQueuedWork,
    ): void {
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

        $ownership->assertActive();
        $connection->beginTransaction();

        try {
            foreach ($this->catalog->deleteOrder() as $table) {
                $ownership->assertActive();
                $connection->table($table)->delete();
            }
            $this->clearVolatileTables($connection, $preserveQueuedWork);

            foreach ($this->catalog->insertOrder() as $table) {
                $ownership->assertActive();
                $path = $this->catalog->entryPath($table);
                $families = $familiesByTable[$table] ?? null;
                $recordCount = $recordCounts[$path] ?? null;

                if (! is_array($families) || ! is_int($recordCount)) {
                    throw $this->invalidState();
                }

                $stream = @fopen($this->stagedPath($stagingRoot, $path), 'rb');
                if ($stream === false) {
                    throw $this->invalidState();
                }

                $count = 0;
                try {
                    while (($line = fgets($stream)) !== false) {
                        $ownership->assertActive();
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

            $ownership->assertActive();
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

            $source = $this->stagedPath($stagingRoot, rtrim($archiveRoot, '/'));
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

    private function assertRuntimeSettingsUnchanged(string $stagingRoot): void
    {
        $raw = @file_get_contents($this->stagedPath($stagingRoot, 'settings/runtime.json'));
        if (! is_string($raw)) {
            throw $this->invalidState();
        }
        $settings = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $expected = [
            'APP_KEY' => (string) config('app.key'),
            'APP_LOCALE' => (string) config('app.locale'),
            'APP_FALLBACK_LOCALE' => (string) config('app.fallback_locale'),
            'SELF_HOST_REGISTRATION' => (bool) config('installer.public_registration', false),
        ];
        if (! is_array($settings) || count($settings) !== count($expected)) {
            throw $this->invalidState();
        }
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $settings) || $settings[$key] !== $value) {
                throw $this->invalidState();
            }
        }
    }

    private function restoreRuntimeSettings(string $stagingRoot): void
    {
        $raw = @file_get_contents($this->stagedPath($stagingRoot, 'settings/runtime.json'));
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

    private function clearVolatileTables(?Connection $connection = null, bool $preserveQueuedWork = false): void
    {
        $connection ??= DB::connection();

        foreach ($this->catalog->excludedTables() as $table) {
            if ($table === 'migrations' || ($preserveQueuedWork && in_array($table, ['jobs', 'job_batches', 'failed_jobs'], true))) {
                continue;
            }

            $connection->table($table)->delete();
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

    private function stagedPath(string $stagingRoot, string $archivePath): string
    {
        return $stagingRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $archivePath);
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
