<?php

namespace App\Services\Update;

use App\Services\Backup\RestoreJournal;
use App\Support\Backup\BackupBundleReader;
use App\Support\InstallationState;
use App\Support\Lifecycle\LifecycleMutex;
use App\Support\Lifecycle\ProductVersion;
use App\Support\Update\ActiveReleasePointer;
use App\Support\Update\UpdatePackageFormat;
use Throwable;

final class ContinueSystemUpdate
{
    public function __construct(
        private readonly UpdateJournal $journal,
        private readonly RestoreJournal $restoreJournal,
        private readonly LifecycleMutex $mutex,
        private readonly ProductVersion $productVersion,
        private readonly ActiveReleasePointer $pointer,
        private readonly UpdateMigrationRunner $migrations,
        private readonly UpdateRuntimeIdentity $runtimeIdentity,
        private readonly ValidateSystemUpdateHealth $health,
        private readonly InstallationState $installationState,
        private readonly BackupBundleReader $backupReader,
        private readonly UpdateQuiescence $quiescence,
    ) {}

    /** @return array{status:'complete',product_version:string} */
    public function handle(): array
    {
        $ownership = $this->mutex->acquire('update-continue');
        try {
            $state = $this->journal->state();
            if ($state === null) {
                return $this->completedReplay();
            }
            if (! in_array($state['phase'], ['target_active', 'migrating', 'validating', 'complete'], true)) {
                throw $this->invalidState();
            }
            $this->assertTarget($state);
            if ($this->restoreJournal->hasActiveOperation()) {
                throw new UpdateException('update_lifecycle_conflict', 'یک عملیات بازیابی دیگر باید ابتدا تعیین تکلیف شود.');
            }
            if ($state['phase'] === 'complete') {
                $this->assertCompletedInstallation($state);
                $this->cleanupCompleted($state);

                return $this->result();
            }
            $this->quiescence->assertReady();
            $healthAccepted = false;
            try {
                if (($state['lifecycle_mutex_path_sha256'] ?? null) !== $ownership->pathFingerprint()) {
                    throw $this->recoveryRequired();
                }
                $this->assertRuntimeIdentity($state);
                $this->assertSafetySnapshot($state);
                $before = $this->migrationState($state, 'pre_update_migration_state');
                if (($state['pre_update_migration_history_sha256'] ?? null) !== $before->digestSha256) {
                    throw $this->recoveryRequired();
                }
                if ($state['phase'] === 'target_active') {
                    if (($state['migration_started'] ?? null) !== false) {
                        throw $this->recoveryRequired();
                    }
                    $state = $this->journal->transition('target_active', 'migrating', [
                        'migration_started' => true,
                        'migration_state' => $before->toArray(),
                    ]);
                }
                if (($state['migration_started'] ?? null) !== true) {
                    throw $this->recoveryRequired();
                }
                $cursor = $this->migrationState($state, 'migration_state');
                $this->assertMigrationPrefix($before, $cursor);
                if ($state['phase'] === 'migrating') {
                    $cursor = $this->migrations->runPending($cursor, function (UpdateMigrationState $completed): void {
                        $this->journal->checkpoint('migrating', ['migration_state' => $completed->toArray()]);
                    });
                    $state = $this->journal->transition('migrating', 'validating', ['migration_state' => $cursor->toArray()]);
                } else {
                    $current = $this->migrations->snapshot();
                    if ($current->ran !== $cursor->ran || $current->schemaFingerprintSha256 !== $cursor->schemaFingerprintSha256) {
                        throw $this->recoveryRequired();
                    }
                }
                $report = $this->health->handle($state);
                if (! $report->healthy) {
                    $this->journal->transition('validating', 'rollback_required', ['error_code' => 'update_health_failed', 'health_failures' => $report->failures]);

                    throw new UpdateException('update_health_failed', 'سلامت نسخه جدید تأیید نشد؛ بازیابی نسخه قبلی لازم است.');
                }
                $healthAccepted = true;
                $this->installationState->updateMetadata(['product_version' => $this->productVersion->requireDistributable()]);
                $this->writeCompletion($state);
                $state = $this->journal->transition('validating', 'complete');
            } catch (UpdateException $exception) {
                if ($healthAccepted) {
                    throw $this->finalizationRetry($exception);
                }
                $code = in_array($exception->codeName(), ['update_health_failed', 'update_migration_failed'], true)
                    ? $exception->codeName() : 'update_recovery_required';
                $this->recordFailure($code);
                if ($code === $exception->codeName()) {
                    throw $exception;
                }
                throw $this->recoveryRequired($exception);
            } catch (Throwable $exception) {
                if ($healthAccepted) {
                    throw $this->finalizationRetry($exception);
                }
                $this->recordFailure('update_recovery_required');

                throw $this->recoveryRequired($exception);
            }
            $this->cleanupCompleted($state);

            return $this->result();
        } finally {
            $ownership->release();
        }
    }

    /** @param array<string, mixed> $state */
    private function assertTarget(array $state): void
    {
        $current = $this->pointer->current();
        if (
            ($current['kind'] ?? null) !== 'slot'
            || ($current['release_id'] ?? null) !== $this->requiredString($state, 'target_release_id')
            || ($current['product_version'] ?? null) !== $this->requiredString($state, 'target_product_version')
            || ($current['package_digest_sha256'] ?? null) !== $this->requiredString($state, 'package_digest_sha256')
            || ($current['release_root'] ?? null) !== realpath(base_path())
            || $this->productVersion->requireDistributable() !== $current['product_version']
        ) {
            throw $this->invalidState();
        }
    }

    /** @param array<string, mixed> $state */
    private function assertRuntimeIdentity(array $state): void
    {
        foreach ($this->runtimeIdentity->capture() as $key => $value) {
            if (! is_string($state[$key] ?? null) || ! hash_equals($state[$key], $value)) {
                throw $this->recoveryRequired();
            }
        }
    }

    /** @param array<string, mixed> $state */
    private function assertSafetySnapshot(array $state): void
    {
        $id = $this->requiredId($state, 'safety_snapshot_id');
        $root = (string) config('update.safety_path', storage_path('app/lifecycle/update/safety'));
        $bundle = $root.'/'.$id.'.seobak';
        $metadataPath = $root.'/'.$id.'.json';
        if (is_link($root) || is_link($bundle) || is_link($metadataPath) || ! is_file($bundle) || ! is_file($metadataPath)) {
            throw $this->recoveryRequired();
        }
        $actualDigest = hash_file('sha256', $bundle);
        $expectedDigest = $state['safety_snapshot_sha256'] ?? null;
        if (! is_string($actualDigest) || ! is_string($expectedDigest) || ! hash_equals($expectedDigest, $actualDigest)) {
            throw $this->recoveryRequired();
        }
        $stream = @fopen($bundle, 'rb');
        if ($stream === false) {
            throw $this->recoveryRequired();
        }
        try {
            $manifest = $this->backupReader->read($stream);
            $manifest->assertCompatible($this->requiredString($state, 'source_product_version'), $this->requiredString($state, 'source_schema_fingerprint'));
        } finally {
            fclose($stream);
        }
        $metadata = $this->readPrivateJson($metadataPath);
        if (($metadata['product_version'] ?? null) !== ($state['source_product_version'] ?? null)) {
            throw $this->recoveryRequired();
        }
        $manifestData = $manifest->toArray();
        if (($manifestData['source_installation_id'] ?? null) !== ($metadata['installation_id'] ?? null) || ($manifestData['source_owner_user_id'] ?? null) !== ($metadata['owner_user_id'] ?? null)) {
            throw $this->recoveryRequired();
        }
        unset($metadata['product_version']);
        $digest = hash('sha256', UpdatePackageFormat::canonicalMetadataJson($metadata));
        if (($state['installation_identity_sha256'] ?? null) !== $digest) {
            throw $this->recoveryRequired();
        }
    }

    /** @param array<string, mixed> $state */
    private function migrationState(array $state, string $key): UpdateMigrationState
    {
        if (! is_array($state[$key] ?? null)) {
            throw $this->recoveryRequired();
        }

        return UpdateMigrationState::fromArray($state[$key]);
    }

    private function assertMigrationPrefix(UpdateMigrationState $before, UpdateMigrationState $cursor): void
    {
        if (
            array_slice($cursor->ran, 0, count($before->ran)) !== $before->ran
            || count($cursor->ran) !== count($before->ran) + count($cursor->appliedSuffix)
            || ($cursor->appliedSuffix === [] && $cursor->schemaFingerprintSha256 !== $before->schemaFingerprintSha256)
        ) {
            throw $this->recoveryRequired();
        }
    }

    private function recordFailure(string $code): void
    {
        try {
            $phase = $this->journal->state()['phase'] ?? null;
            if (is_string($phase) && in_array($phase, ['target_active', 'migrating', 'validating'], true)) {
                $this->journal->transition($phase, $code === 'update_recovery_required' ? 'recovery_required' : 'rollback_required', ['error_code' => $code]);
            }
        } catch (Throwable) {
            // Retain the prior maintenance phase and durable attempt evidence.
        }
    }

    /** @param array<string, mixed> $state */
    private function writeCompletion(array $state): void
    {
        $record = ['schema' => 'seo-update-completion/1', 'completed_at_utc' => gmdate('Y-m-d\TH:i:s\Z')];
        foreach (['source_release_kind', 'source_release_id', 'source_product_version', 'target_release_id', 'target_product_version', 'target_schema_fingerprint', 'package_digest_sha256', 'safety_snapshot_id'] as $key) {
            $record[$key] = $state[$key] ?? null;
        }
        $path = $this->completionPath();
        $directory = dirname($path);
        if (is_link($directory) || is_link($path) || is_link($path.'.tmp')) {
            throw $this->recoveryRequired();
        }
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw $this->recoveryRequired();
        }
        $json = UpdatePackageFormat::canonicalMetadataJson($record);
        if (file_put_contents($path.'.tmp', $json, LOCK_EX) !== strlen($json)) {
            throw $this->recoveryRequired();
        }
        @chmod($path.'.tmp', 0600);
        if (! rename($path.'.tmp', $path)) {
            throw $this->recoveryRequired();
        }
        @chmod($path, 0600);
    }

    /** @param array<string, mixed> $state */
    private function cleanupCompleted(array $state): void
    {
        $uploadId = $this->requiredId($state, 'upload_id');
        $root = (string) config('update.upload_path', storage_path('app/lifecycle/update/uploads'));
        $path = $root.'/'.$uploadId.'.seoupdate';
        if (is_link($root) || is_link($path) || (file_exists($path) && ! unlink($path))) {
            throw new UpdateException('update_cleanup_failed', 'نسخه جدید فعال است؛ پاک‌سازی وضعیت به‌روزرسانی باید دوباره انجام شود.');
        }
        $this->journal->clear();
        if ($this->journal->state() !== null) {
            throw new UpdateException('update_cleanup_failed', 'نسخه جدید فعال است؛ پاک‌سازی وضعیت به‌روزرسانی باید دوباره انجام شود.');
        }
    }

    /** @return array{status:'complete',product_version:string} */
    private function completedReplay(): array
    {
        $record = $this->readPrivateJson($this->completionPath());
        if (($record['schema'] ?? null) !== 'seo-update-completion/1') {
            throw $this->invalidState();
        }
        $this->assertTarget($record);
        $this->assertCompletedInstallation($record);

        return $this->result();
    }

    /** @param array<string, mixed> $state */
    private function assertCompletedInstallation(array $state): void
    {
        if (($this->installationState->details()['product_version'] ?? null) !== ($state['target_product_version'] ?? null)) {
            throw $this->invalidState();
        }
    }

    private function completionPath(): string
    {
        return (string) config('update.retention_path', storage_path('app/lifecycle/update/last-complete.json'));
    }

    /** @return array<string, mixed> */
    private function readPrivateJson(string $path): array
    {
        if (is_link($path) || ! is_file($path)) {
            throw $this->recoveryRequired();
        }
        $raw = file_get_contents($path);
        if (! is_string($raw) || strlen($raw) > 65536) {
            throw $this->recoveryRequired();
        }
        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw $this->recoveryRequired();
        }

        return $data;
    }

    /** @param array<string, mixed> $state */
    private function requiredString(array $state, string $key): string
    {
        $value = $state[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw $this->invalidState();
        }

        return $value;
    }

    /** @param array<string, mixed> $state */
    private function requiredId(array $state, string $key): string
    {
        $value = $this->requiredString($state, $key);
        if (preg_match('/\A[a-f0-9]{32}\z/', $value) !== 1) {
            throw $this->invalidState();
        }

        return $value;
    }

    /** @return array{status:'complete',product_version:string} */
    private function result(): array
    {
        return ['status' => 'complete', 'product_version' => $this->productVersion->requireDistributable()];
    }

    private function finalizationRetry(Throwable $previous): UpdateException
    {
        return new UpdateException('update_finalization_retry', 'سلامت نسخه جدید تأیید شده است؛ ثبت نهایی را دوباره ادامه دهید.', $previous);
    }

    private function invalidState(): UpdateException
    {
        return new UpdateException('update_invalid_state', 'وضعیت نسخه فعال برای ادامه به‌روزرسانی معتبر نیست.');
    }

    private function recoveryRequired(?Throwable $previous = null): UpdateException
    {
        return new UpdateException('update_recovery_required', 'شواهد ادامه ایمن به‌روزرسانی کامل نیست؛ بازیابی لازم است.', $previous);
    }
}
