<?php

namespace App\Services\Update;

use App\Services\Backup\ApplicationSchemaFingerprint;
use App\Services\Backup\CapturedSystemState;
use App\Services\Backup\CaptureSystemState;
use App\Services\Backup\RestoreJournal;
use App\Support\InstallationState;
use App\Support\Lifecycle\LifecycleMutex;
use App\Support\Lifecycle\LifecycleMutexHandle;
use App\Support\Lifecycle\ProductVersion;
use App\Support\Update\ActiveReleasePointer;
use App\Support\Update\UpdatePackageReader;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

final class PrepareSystemUpdate
{
    public function __construct(
        private readonly UpdateJournal $journal,
        private readonly RestoreJournal $restoreJournal,
        private readonly LifecycleMutex $mutex,
        private readonly ProductVersion $productVersion,
        private readonly ApplicationSchemaFingerprint $schemaFingerprint,
        private readonly UpdatePackageReader $reader,
        private readonly UpdateQuiescence $quiescence,
        private readonly CaptureSystemState $captureSystemState,
        private readonly InstallationState $installationState,
        private readonly UpdateReleaseStager $stager,
        private readonly ActiveReleasePointer $activeReleasePointer,
        private readonly UpdateMigrationRunner $migrationRunner,
    ) {}

    public function handle(string $expectedPackageDigest): PreparedSystemUpdate
    {
        $state = $this->verifiedState($expectedPackageDigest);
        $uploadId = $this->requiredUploadId($state);
        $ownership = $this->mutex->acquire('update');

        try {
            return $this->prepareUnderLock($ownership, $uploadId, $expectedPackageDigest);
        } finally {
            $ownership->release();
        }
    }

    private function prepareUnderLock(
        LifecycleMutexHandle $ownership,
        string $uploadId,
        string $expectedPackageDigest,
    ): PreparedSystemUpdate {
        $ownership->assertActive();
        $state = $this->verifiedState($expectedPackageDigest);
        if ($this->requiredUploadId($state) !== $uploadId) {
            throw $this->invalidState();
        }
        if ($this->restoreJournal->state() !== null) {
            throw $this->lifecycleConflict();
        }

        $uploadPath = $this->uploadPath($uploadId);
        $actualDigest = hash_file('sha256', $uploadPath);
        if (! is_string($actualDigest) || ! hash_equals($expectedPackageDigest, $actualDigest)) {
            throw new UpdateException(
                'update_package_changed',
                'فایل به‌روزرسانی پس از تأیید تغییر کرده است.',
            );
        }

        $stream = @fopen($uploadPath, 'rb');
        if ($stream === false) {
            throw $this->invalidState();
        }
        try {
            $manifest = $this->reader->read($stream);
        } finally {
            fclose($stream);
        }

        $manifestData = $manifest->toArray();
        $currentVersion = $this->productVersion->requireDistributable();
        $sourceVersions = $manifestData['allowed_source_product_versions'] ?? null;
        if (! is_array($sourceVersions) || $sourceVersions !== [$currentVersion]) {
            throw new UpdateException(
                'update_source_mismatch',
                'نسخه فعال با مبدا بسته به‌روزرسانی سازگار نیست.',
            );
        }

        $currentSchema = $this->schemaFingerprint->forConnection(DB::connection());
        if (($manifestData['source_schema_fingerprint'] ?? null) !== $currentSchema) {
            throw new UpdateException(
                'update_schema_mismatch',
                'ساختار داده فعال با بسته به‌روزرسانی سازگار نیست.',
            );
        }
        $this->assertRuntimeCompatible($manifestData);

        $sourceRelease = $this->activeReleasePointer->current();
        if (($sourceRelease['product_version'] ?? null) !== $currentVersion) {
            throw new UpdateException(
                'update_source_mismatch',
                'نسخه فعال با هویت نصب جاری سازگار نیست.',
            );
        }
        $sourceKind = $sourceRelease['kind'] ?? null;
        $sourceReleaseId = $sourceRelease['release_id'] ?? null;
        if (! in_array($sourceKind, ['root', 'slot'], true)) {
            throw $this->invalidState();
        }
        if ($sourceKind === 'root') {
            $sourceReleaseId = null;
        } elseif (! is_string($sourceReleaseId) || $sourceReleaseId === '') {
            throw $this->invalidState();
        }

        $details = $this->installationState->details();
        if (! is_array($details) || ($details['product_version'] ?? null) !== $currentVersion) {
            throw new UpdateException(
                'update_source_mismatch',
                'هویت نصب جاری با نسخه فعال سازگار نیست.',
            );
        }

        $this->journal->transition('verified', 'preparing', [
            'lifecycle_mutex_path_sha256' => $ownership->pathFingerprint(),
            'source_release_kind' => $sourceKind,
            'source_release_id' => $sourceReleaseId,
            'source_product_version' => $currentVersion,
            'source_schema_fingerprint' => $currentSchema,
        ]);
        $this->journal->transition('preparing', 'quiescing');

        $ownership->assertActive();
        $this->quiescence->requestWorkerRestart();
        $this->quiescence->assertReady();

        $ownership->assertActive();
        [$safetyBundlePath, $safetyMetadataPath] = $this->captureSafetySnapshot(
            $ownership,
            $uploadId,
            $details,
        );
        $migrationState = $this->migrationRunner->snapshot();
        $migrationHistoryDigest = $migrationState->digestSha256;

        $this->journal->transition('quiescing', 'snapshot_ready', [
            'safety_snapshot_id' => $uploadId,
            'pre_update_migration_history_sha256' => $migrationHistoryDigest,
            'pre_update_migration_state' => $migrationState->toArray(),
        ]);

        $ownership->assertActive();
        $staged = $this->stager->stage($uploadPath, $manifest);
        $this->stager->preparePublicAssets($staged['release_root'], $manifest);

        $targetVersion = $this->requiredString($manifestData, 'target_product_version');
        $targetSchema = $this->requiredString($manifestData, 'target_schema_fingerprint');
        $this->journal->transition('snapshot_ready', 'staged', [
            'target_release_id' => $staged['release_id'],
            'target_product_version' => $targetVersion,
            'target_schema_fingerprint' => $targetSchema,
        ]);

        $ownership->assertActive();

        return new PreparedSystemUpdate(
            uploadId: $uploadId,
            packageDigestSha256: $expectedPackageDigest,
            sourceReleaseKind: $sourceKind,
            sourceReleaseId: $sourceReleaseId,
            sourceProductVersion: $currentVersion,
            sourceSchemaFingerprint: $currentSchema,
            targetReleaseId: $staged['release_id'],
            targetReleaseRoot: $staged['release_root'],
            targetProductVersion: $targetVersion,
            targetSchemaFingerprint: $targetSchema,
            safetyBundlePath: $safetyBundlePath,
            safetyMetadataPath: $safetyMetadataPath,
            preUpdateMigrationHistorySha256: $migrationHistoryDigest,
        );
    }

    /** @return array<string, mixed> */
    private function verifiedState(string $expectedPackageDigest): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $expectedPackageDigest) !== 1) {
            throw $this->invalidState();
        }

        $state = $this->journal->state();
        if (
            ! is_array($state)
            || ($state['phase'] ?? null) !== 'verified'
            || ($state['package_digest_sha256'] ?? null) !== $expectedPackageDigest
        ) {
            throw $this->invalidState();
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    private function requiredUploadId(array $state): string
    {
        $uploadId = $state['upload_id'] ?? null;
        if (! is_string($uploadId) || preg_match('/\A[a-f0-9]{32}\z/', $uploadId) !== 1) {
            throw $this->invalidState();
        }

        return $uploadId;
    }

    /** @param array<string, mixed> $manifest */
    private function assertRuntimeCompatible(array $manifest): void
    {
        if (($manifest['launcher_schema'] ?? null) !== UpdateManifest::LAUNCHER_SCHEMA) {
            throw $this->invalidState();
        }

        $minimumPhp = $this->requiredString($manifest, 'minimum_php_version');
        if (! version_compare(PHP_VERSION, $minimumPhp, '>=')) {
            throw new UpdateException(
                'update_runtime_incompatible',
                'نسخه PHP میزبان با این به‌روزرسانی سازگار نیست.',
            );
        }

        $extensions = $manifest['required_php_extensions'] ?? null;
        if (! is_array($extensions) || ! array_is_list($extensions)) {
            throw $this->invalidState();
        }
        foreach ($extensions as $extension) {
            if (! is_string($extension) || ! extension_loaded($extension)) {
                throw new UpdateException(
                    'update_runtime_incompatible',
                    'افزونه‌های PHP موردنیاز این به‌روزرسانی روی میزبان کامل نیستند.',
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $installationDetails
     * @return array{string, string}
     */
    private function captureSafetySnapshot(
        LifecycleMutexHandle $ownership,
        string $uploadId,
        array $installationDetails,
    ): array {
        $safetyRoot = rtrim((string) config(
            'update.safety_path',
            storage_path('app/lifecycle/update/safety'),
        ), DIRECTORY_SEPARATOR);
        if (is_link($safetyRoot)) {
            throw $this->invalidState();
        }
        if (! is_dir($safetyRoot) && ! @mkdir($safetyRoot, 0700, true) && ! is_dir($safetyRoot)) {
            throw $this->invalidState();
        }
        @chmod($safetyRoot, 0700);

        $bundlePath = $safetyRoot.DIRECTORY_SEPARATOR.$uploadId.'.seobak';
        $metadataPath = $safetyRoot.DIRECTORY_SEPARATOR.$uploadId.'.json';
        if (file_exists($bundlePath) || is_link($bundlePath) || file_exists($metadataPath) || is_link($metadataPath)) {
            throw $this->invalidState();
        }

        $captured = $this->captureSystemState->handle($ownership);
        try {
            $this->writeCapturedBundle($captured, $bundlePath);
            $this->writeMetadataSnapshot($installationDetails, $metadataPath);
        } catch (Throwable $exception) {
            @unlink($bundlePath);
            @unlink($bundlePath.'.tmp');
            @unlink($metadataPath);
            @unlink($metadataPath.'.tmp');
            throw $exception;
        } finally {
            $captured->close();
        }

        return [$bundlePath, $metadataPath];
    }

    private function writeCapturedBundle(CapturedSystemState $captured, string $path): void
    {
        $captured->rewind();
        $temporary = $path.'.tmp';
        $destination = @fopen($temporary, 'xb');
        if ($destination === false) {
            throw $this->invalidState();
        }

        try {
            if (stream_copy_to_stream($captured->stream(), $destination) === false) {
                throw $this->invalidState();
            }
        } finally {
            fclose($destination);
        }

        @chmod($temporary, 0600);
        if (! @rename($temporary, $path)) {
            @unlink($temporary);
            throw $this->invalidState();
        }
        @chmod($path, 0600);
    }

    /** @param array<string, mixed> $details */
    private function writeMetadataSnapshot(array $details, string $path): void
    {
        try {
            $json = json_encode(
                $details,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new UpdateException(
                'update_invalid_state',
                'وضعیت ایمن به‌روزرسانی معتبر نیست.',
                $exception,
            );
        }

        $temporary = $path.'.tmp';
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw $this->invalidState();
        }
        @chmod($temporary, 0600);
        if (! @rename($temporary, $path)) {
            @unlink($temporary);
            throw $this->invalidState();
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

    /** @param array<string, mixed> $value */
    private function requiredString(array $value, string $key): string
    {
        $item = $value[$key] ?? null;
        if (! is_string($item) || $item === '') {
            throw $this->invalidState();
        }

        return $item;
    }

    private function invalidState(): UpdateException
    {
        return new UpdateException('update_invalid_state', 'وضعیت ایمن به‌روزرسانی معتبر نیست.');
    }

    private function lifecycleConflict(): UpdateException
    {
        return new UpdateException(
            'update_lifecycle_conflict',
            'یک عملیات بازیابی دیگر باید ابتدا تعیین تکلیف شود.',
        );
    }
}
