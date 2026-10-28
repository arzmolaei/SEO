<?php

namespace App\Services\Update;

use App\Services\Backup\ApplicationSchemaFingerprint;
use App\Services\Backup\RestoreJournal;
use App\Support\Lifecycle\LifecycleMutex;
use App\Support\Lifecycle\ProductVersion;
use App\Support\Update\ActiveReleasePointer;
use App\Support\Update\UpdatePackageReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

final class VerifyUpdatePackage
{
    public function __construct(
        private readonly UpdatePackageReader $reader,
        private readonly ProductVersion $productVersion,
        private readonly ApplicationSchemaFingerprint $schemaFingerprint,
        private readonly UpdateJournal $journal,
        private readonly RestoreJournal $restoreJournal,
        private readonly ActiveReleasePointer $pointer,
        private readonly LifecycleMutex $mutex,
    ) {}

    public function handle(UploadedFile $upload): UpdatePreview
    {
        $ownership = $this->mutex->acquire('update-verify');
        try {
            return $this->verifyUnderLock($upload);
        } finally {
            $ownership->release();
        }
    }

    private function verifyUnderLock(UploadedFile $upload): UpdatePreview
    {
        $this->assertLifecycleAvailable();
        $source = $this->pointer->current();
        if ($source['kind'] === 'root') {
            $this->pointer->activateRoot($source['product_version']);
        }

        $uploadId = bin2hex(random_bytes(16));
        $path = $this->uploadPath($uploadId);
        $journalStarted = false;

        try {
            $this->storeUpload($upload, $path);
            $packageDigest = hash_file('sha256', $path);
            if (! is_string($packageDigest) || preg_match('/\A[a-f0-9]{64}\z/', $packageDigest) !== 1) {
                throw $this->invalidPackage();
            }

            $this->journal->startUpload($uploadId);
            $journalStarted = true;

            $stream = @fopen($path, 'rb');
            if ($stream === false) {
                throw $this->invalidPackage();
            }

            try {
                $manifest = $this->reader->read($stream);
            } finally {
                fclose($stream);
            }

            $data = $manifest->toArray();
            $currentVersion = $this->productVersion->requireDistributable();
            $currentSchema = $this->schemaFingerprint->forConnection(DB::connection());

            $sources = $data['allowed_source_product_versions'] ?? null;
            if (! is_array($sources) || $sources !== [$currentVersion]) {
                throw new UpdateException(
                    'update_source_mismatch',
                    'این بسته برای نسخه فعلی برنامه ساخته نشده است.',
                );
            }

            if (($data['source_schema_fingerprint'] ?? null) !== $currentSchema) {
                throw new UpdateException(
                    'update_schema_mismatch',
                    'ساختار داده فعلی با پیش‌نیاز این بسته به‌روزرسانی سازگار نیست.',
                );
            }

            $targetVersion = $this->requiredString($data['target_product_version'] ?? null);
            if (! version_compare($targetVersion, $currentVersion, '>')) {
                throw new UpdateException(
                    'update_version_not_newer',
                    'نسخه مقصد باید جدیدتر از نسخه فعلی باشد.',
                );
            }

            $minimumPhp = $this->requiredString($data['minimum_php_version'] ?? null);
            if (! version_compare(PHP_VERSION, $minimumPhp, '>=')) {
                throw new UpdateException(
                    'update_runtime_incompatible',
                    'نسخه PHP میزبان با این به‌روزرسانی سازگار نیست.',
                );
            }

            $extensions = $data['required_php_extensions'] ?? null;
            if (! is_array($extensions) || ! array_is_list($extensions)) {
                throw $this->invalidPackage();
            }
            foreach ($extensions as $extension) {
                if (! is_string($extension) || ! extension_loaded($extension)) {
                    throw new UpdateException(
                        'update_runtime_incompatible',
                        'افزونه‌های PHP موردنیاز این به‌روزرسانی روی میزبان کامل نیستند.',
                    );
                }
            }

            $entries = $data['entries'] ?? null;
            if (! is_array($entries) || ! array_is_list($entries)) {
                throw $this->invalidPackage();
            }

            $migrationCount = 0;
            foreach ($entries as $entry) {
                if (! is_array($entry) || ! is_string($entry['path'] ?? null)) {
                    throw $this->invalidPackage();
                }
                if (str_starts_with($entry['path'], 'database/migrations/')) {
                    $migrationCount++;
                }
            }

            $preview = new UpdatePreview(
                uploadId: $uploadId,
                packageDigestSha256: $packageDigest,
                sourceProductVersion: $currentVersion,
                targetProductVersion: $targetVersion,
                channel: $this->requiredString($data['channel'] ?? null),
                minimumPhpVersion: $minimumPhp,
                requiredPhpExtensions: $extensions,
                migrationCount: $migrationCount,
                migrationTreeSha256: $this->requiredString($data['migration_tree_sha256'] ?? null),
                compatibility: [
                    'source_version' => 'compatible',
                    'source_schema' => 'compatible',
                    'launcher' => 'compatible',
                    'php_runtime' => 'compatible',
                    'required_extensions' => 'compatible',
                ],
            );

            $this->journal->transition('uploaded', 'verified', [
                'package_digest_sha256' => $packageDigest,
                'source_schema_fingerprint' => $currentSchema,
                'target_schema_fingerprint' => $this->requiredString($data['target_schema_fingerprint'] ?? null),
                'launcher_schema' => $this->requiredString($data['launcher_schema'] ?? null),
                'preview' => $preview->toArray(),
            ]);

            return $preview;
        } catch (UpdateException $exception) {
            $this->cleanupOwnFailure($uploadId, $path, $journalStarted);

            throw $exception;
        } catch (Throwable $exception) {
            $this->cleanupOwnFailure($uploadId, $path, $journalStarted);

            throw new UpdateException(
                'update_invalid_package',
                'بسته به‌روزرسانی معتبر نیست.',
                $exception,
            );
        }
    }

    private function assertLifecycleAvailable(): void
    {
        try {
            if ($this->journal->state() !== null || $this->restoreJournal->state() !== null) {
                throw $this->lifecycleConflict();
            }
        } catch (UpdateException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new UpdateException(
                'update_lifecycle_conflict',
                'یک عملیات بازیابی یا به‌روزرسانی دیگر باید ابتدا تعیین تکلیف شود.',
                $exception,
            );
        }
    }

    private function storeUpload(UploadedFile $upload, string $path): void
    {
        $directory = dirname($path);
        if (is_link($directory)) {
            throw $this->invalidPackage();
        }
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw $this->invalidPackage();
        }
        @chmod($directory, 0700);

        $sourcePath = $upload->getRealPath();
        if (! is_string($sourcePath) || is_link($sourcePath) || ! is_file($sourcePath)) {
            throw $this->invalidPackage();
        }

        $source = @fopen($sourcePath, 'rb');
        $destination = @fopen($path, 'xb');
        if ($source === false || $destination === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($destination)) {
                fclose($destination);
            }
            @unlink($path);
            throw $this->invalidPackage();
        }

        try {
            if (stream_copy_to_stream($source, $destination) === false) {
                throw $this->invalidPackage();
            }
        } finally {
            fclose($source);
            fclose($destination);
        }

        @chmod($path, 0600);
    }

    private function uploadPath(string $uploadId): string
    {
        $directory = (string) config(
            'update.upload_path',
            storage_path('app/lifecycle/update/uploads'),
        );

        return rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$uploadId.'.seoupdate';
    }

    private function cleanupOwnFailure(string $uploadId, string $path, bool $journalStarted): void
    {
        @unlink($path);
        @unlink($path.'.tmp');

        if (! $journalStarted) {
            return;
        }

        try {
            $state = $this->journal->state();
            if (is_array($state) && ($state['upload_id'] ?? null) === $uploadId) {
                $this->journal->clear();
            }
        } catch (Throwable) {
            // Keep the original verification failure authoritative and fail closed later.
        }
    }

    private function requiredString(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            throw $this->invalidPackage();
        }

        return $value;
    }

    private function invalidPackage(): UpdateException
    {
        return new UpdateException('update_invalid_package', 'بسته به‌روزرسانی معتبر نیست.');
    }

    private function lifecycleConflict(): UpdateException
    {
        return new UpdateException(
            'update_lifecycle_conflict',
            'یک عملیات بازیابی یا به‌روزرسانی دیگر باید ابتدا تعیین تکلیف شود.',
        );
    }
}
