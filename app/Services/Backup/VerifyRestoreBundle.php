<?php

namespace App\Services\Backup;

use App\Support\Backup\BackupBundleReader;
use App\Support\Lifecycle\ProductVersion;
use Illuminate\Database\Connection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

final class VerifyRestoreBundle
{
    public function __construct(
        private readonly BackupBundleReader $reader,
        private readonly ApplicationTableCatalog $catalog,
        private readonly ApplicationSchemaFingerprint $fingerprint,
        private readonly ProductVersion $productVersion,
        private readonly RestoreJournal $journal,
        private readonly CleanRestoreTarget $cleanRestoreTarget,
    ) {}

    public function handle(UploadedFile $upload): RestorePreview
    {
        $uploadId = bin2hex(random_bytes(16));
        $destination = $this->uploadDirectory().DIRECTORY_SEPARATOR.$uploadId.'.seobak';
        $journalStarted = false;

        try {
            [$digest, $bundleBytes] = $this->copyPrivateUpload($upload, $destination);
            $this->journal->startUpload($uploadId);
            $journalStarted = true;

            $stream = fopen($destination, 'rb');
            if ($stream === false) {
                throw $this->invalidBundle();
            }

            try {
                $manifest = $this->reader->verify($stream);
            } finally {
                fclose($stream);
            }

            $connection = DB::connection();
            $manifest->assertCompatible(
                $this->productVersion->requireDistributable(),
                $this->fingerprint->forConnection($connection),
            );

            [$recordCount, $durableFileCount] = $this->assertExactContract($manifest, $connection);
            $this->cleanRestoreTarget->assertReady($uploadId);

            $data = $manifest->toArray();
            $sourceInstallationId = $data['source_installation_id'] ?? null;
            $sourceCreatedAtUtc = $data['created_at_utc'] ?? null;
            $sourceProductVersion = $data['product_version'] ?? null;

            if (
                ! is_string($sourceInstallationId)
                || ! is_string($sourceCreatedAtUtc)
                || ! is_string($sourceProductVersion)
            ) {
                throw $this->invalidBundle();
            }

            $preview = new RestorePreview(
                uploadId: $uploadId,
                digest: $digest,
                sourceInstallationId: $sourceInstallationId,
                sourceCreatedAtUtc: $sourceCreatedAtUtc,
                sourceProductVersion: $sourceProductVersion,
                tableRecordCount: $recordCount,
                durableFileCount: $durableFileCount,
                bundleBytes: $bundleBytes,
            );

            $this->journal->markVerified($preview);

            return $preview;
        } catch (BackupException $exception) {
            @unlink($destination);
            if ($journalStarted) {
                $this->journal->clear();
            }

            throw $exception;
        } catch (Throwable $exception) {
            @unlink($destination);
            if ($journalStarted) {
                $this->journal->clear();
            }

            throw new BackupException(
                'restore_invalid_bundle',
                'فایل پشتیبان معتبر نیست.',
                $exception,
            );
        }
    }

    /** @return array{string, int} */
    private function copyPrivateUpload(UploadedFile $upload, string $destination): array
    {
        if (! $upload->isValid()) {
            throw $this->invalidBundle();
        }

        $sourcePath = $upload->getRealPath();
        if (! is_string($sourcePath) || $sourcePath === '') {
            throw $this->invalidBundle();
        }

        $directory = dirname($destination);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw $this->invalidBundle();
        }
        @chmod($directory, 0700);

        $source = fopen($sourcePath, 'rb');
        $target = fopen($destination, 'x+b');
        if ($source === false || $target === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }
            @unlink($destination);

            throw $this->invalidBundle();
        }

        $maximum = max(1, (int) config('backup.bundle.max_bundle_bytes', 2 * 1024 * 1024 * 1024));
        $chunkBytes = max(1, (int) config('backup.bundle.chunk_bytes', 64 * 1024));
        $hash = hash_init('sha256');
        $size = 0;

        try {
            if (! @chmod($destination, 0600)) {
                throw $this->invalidBundle();
            }

            while (! feof($source)) {
                $chunk = fread($source, $chunkBytes);
                if ($chunk === false) {
                    throw $this->invalidBundle();
                }
                if ($chunk === '') {
                    break;
                }

                $size += strlen($chunk);
                if ($size > $maximum) {
                    throw $this->invalidBundle();
                }

                hash_update($hash, $chunk);
                $this->writeAll($target, $chunk);
            }

            if (! fflush($target)) {
                throw $this->invalidBundle();
            }
        } finally {
            fclose($source);
            fclose($target);
        }

        return [hash_final($hash), $size];
    }

    /** @return array{int, int} */
    private function assertExactContract(BackupManifest $manifest, Connection $connection): array
    {
        $data = $manifest->toArray();
        $tables = $data['tables'] ?? null;
        $excluded = $data['excluded_runtime_tables'] ?? null;
        $entries = $data['entries'] ?? null;

        if (
            ! is_array($tables)
            || ! $this->sameContract($tables, $this->catalog->metadata($connection))
            || ! is_array($excluded)
            || $excluded !== $this->catalog->excludedTables()
            || ! is_array($entries)
            || ! array_is_list($entries)
        ) {
            throw $this->incompatibleSchema();
        }

        $expectedDatabasePaths = array_map(
            fn (string $table): string => $this->catalog->entryPath($table),
            $this->catalog->tableNames(),
        );
        $seenDatabasePaths = [];
        $settingsSeen = false;
        $recordCount = 0;
        $durableFileCount = 0;
        $durablePrefixes = array_map(
            static fn (string $path): string => rtrim($path, '/').'/',
            array_values(array_filter(
                array_keys((array) config('backup.durable_file_roots', [])),
                'is_string',
            )),
        );

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_string($entry['path'] ?? null)) {
                throw $this->invalidBundle();
            }

            $path = $entry['path'];
            if (str_starts_with($path, 'database/')) {
                if (
                    ! in_array($path, $expectedDatabasePaths, true)
                    || ! is_int($entry['record_count'] ?? null)
                    || $entry['record_count'] < 0
                    || ($entry['media_type'] ?? null) !== 'application/x-ndjson'
                ) {
                    throw $this->incompatibleSchema();
                }

                $seenDatabasePaths[] = $path;
                $recordCount += $entry['record_count'];

                continue;
            }

            if ($path === 'settings/runtime.json') {
                if ($settingsSeen || ($entry['media_type'] ?? null) !== 'application/json') {
                    throw $this->invalidBundle();
                }
                $settingsSeen = true;

                continue;
            }

            $durable = false;
            foreach ($durablePrefixes as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    $durable = true;
                    break;
                }
            }

            if (! $durable || ($entry['media_type'] ?? null) !== 'application/octet-stream') {
                throw $this->invalidBundle();
            }
            $durableFileCount++;
        }

        if ($seenDatabasePaths !== $expectedDatabasePaths || ! $settingsSeen) {
            throw $this->incompatibleSchema();
        }

        return [$recordCount, $durableFileCount];
    }

    /**
     * Canonicalize only associative key order while preserving list order and scalar types.
     * This keeps restore-contract comparison strict without rejecting canonicalized manifest JSON.
     *
     * @param  array<array-key, mixed>  $actual
     * @param  array<array-key, mixed>  $expected
     */
    private function sameContract(array $actual, array $expected): bool
    {
        return $this->canonicalizeContract($actual) === $this->canonicalizeContract($expected);
    }

    private function canonicalizeContract(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $result = [];
            foreach ($value as $item) {
                $result[] = $this->canonicalizeContract($item);
            }

            return $result;
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalizeContract($item);
        }

        return $value;
    }

    /** @param resource $stream */
    private function writeAll($stream, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            $written = fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw $this->invalidBundle();
            }
            $offset += $written;
        }
    }

    private function uploadDirectory(): string
    {
        return (string) config(
            'backup.restore.upload_path',
            storage_path('app/lifecycle/restore/uploads'),
        );
    }

    private function invalidBundle(): BackupException
    {
        return new BackupException(
            'restore_invalid_bundle',
            'فایل پشتیبان معتبر نیست.',
        );
    }

    private function incompatibleSchema(): BackupException
    {
        return new BackupException(
            'restore_incompatible_schema',
            'فایل پشتیبان با نسخه یا ساختار این نصب سازگار نیست.',
        );
    }
}
