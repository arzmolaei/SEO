<?php

namespace App\Services\Update;

use App\Services\Backup\ApplicationSchemaFingerprint;
use App\Services\Backup\ApplySystemStateSnapshot;
use App\Services\Backup\BackupException;
use App\Services\Backup\RestoreJournal;
use App\Support\Backup\BackupBundleReader;
use App\Support\InstallationState;
use App\Support\Lifecycle\LifecycleMutex;
use App\Support\Lifecycle\ProductVersion;
use App\Support\Update\ActiveReleasePointer;
use App\Support\Update\UpdatePackageFormat;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RecoverInterruptedUpdate
{
    public function __construct(
        private readonly UpdateJournal $journal,
        private readonly RestoreJournal $restoreJournal,
        private readonly LifecycleMutex $mutex,
        private readonly ActiveReleasePointer $pointer,
        private readonly ProductVersion $version,
        private readonly UpdateMigrationRunner $migrations,
        private readonly UpdateRuntimeIdentity $identity,
        private readonly ApplicationSchemaFingerprint $schema,
        private readonly BackupBundleReader $reader,
        private readonly ApplySystemStateSnapshot $snapshot,
        private readonly ValidateSystemUpdateHealth $health,
        private readonly InstallationState $installation,
        private readonly UpdateQuiescence $quiescence,
    ) {}

    /** @return array{status:'snapshot_restore_required'|'rolled_back',product_version:string} */
    public function handle(): array
    {
        $ownership = $this->mutex->acquire('update-recover');
        try {
            $state = $this->journal->state();
            if ($state === null || (! $this->isBootstrapRecovery($state) && ! in_array($state['phase'], ['rollback_required', 'migration_rollback', 'source_switch_pending', 'snapshot_restore_required'], true))) {
                throw $this->invalidState();
            }
            // A stale target/source process is rejected without poisoning a valid retry.
            $current = $this->pointer->current();
            $sourceActive = $this->matchesSource($current, $state);
            $sourcePhase = $this->isBootstrapRecovery($state) || $state['phase'] === 'snapshot_restore_required' || ($state['phase'] === 'source_switch_pending' && $sourceActive);
            $this->assertBooted($current, $state, $sourcePhase);
            try {
                if ($this->restoreJournal->state() !== null || ($state['lifecycle_mutex_path_sha256'] ?? null) !== $ownership->pathFingerprint()) {
                    throw $this->recoveryRequired();
                }
                $this->quiescence->assertReady();
                $this->assertIdentity($state);
                [$bundle, $metadata, $id] = $this->assertSafety($state);
                $source = $this->sourceRelease($state);
                $before = $this->migrationState($state, 'pre_update_migration_state');
                if ($before->digestSha256 !== ($state['pre_update_migration_history_sha256'] ?? null)) {
                    throw $this->recoveryRequired();
                }
                if ($this->isBootstrapRecovery($state)) {
                    $this->assertSourceSchema($state, $before);
                    try {
                        $state = $this->journal->resumeBootstrapRecovery();
                    } catch (Throwable $exception) {
                        throw $this->retry($exception);
                    }
                }
                if (! $sourcePhase) {
                    $state = $this->reverse($state, $before);
                    $this->assertSourceSchema($state, $before);
                    $ownership->assertActive();
                    try {
                        if ($source['kind'] === 'root') {
                            $this->pointer->activateRoot($source['product_version']);
                        } else {
                            $this->pointer->activateSlot($source['release_id'], $source['product_version'], $source['package_digest_sha256']);
                        }
                    } catch (Throwable $exception) {
                        throw $this->retry($exception);
                    }
                    $this->publish('source_switch_pending', 'snapshot_restore_required');

                    // No source-version application classes may execute in this request.
                    return ['status' => 'snapshot_restore_required', 'product_version' => $source['product_version']];
                }
                $this->assertSourceSchema($state, $before);
                if ($state['phase'] === 'source_switch_pending') {
                    $state = $this->publish('source_switch_pending', 'snapshot_restore_required');
                }
                $ownership->assertActive();
                $recoveryRoot = storage_path('app/lifecycle/update/recovery/'.$id);
                if (is_link(dirname($recoveryRoot)) || (is_dir(dirname($recoveryRoot)) && realpath(dirname($recoveryRoot)) !== dirname($recoveryRoot))) {
                    throw $this->recoveryRequired();
                }
                try {
                    $this->snapshot->handle($bundle, $metadata, $recoveryRoot, $ownership, preserveQueuedWork: true, preserveRuntimeSettings: true);
                } catch (BackupException $exception) {
                    if ($exception->codeName() === 'restore_recovery_failed') {
                        throw $this->retry($exception);
                    }
                    throw $this->recoveryRequired($exception);
                }
                $this->assertSourceSchema($state, $before);
                $this->assertIdentity($state);
                if (! $this->health->handleSourceRecovery($state)->healthy || $this->installation->details() !== $this->readJson($metadata)) {
                    throw $this->recoveryRequired();
                }
                // Keep the protected evidence and both releases. V1 deliberately does
                // not prune files required to diagnose or retry interrupted cleanup.
                $this->journal->clear();
                if ($this->journal->state() !== null) {
                    throw $this->recoveryRequired();
                }

                return ['status' => 'rolled_back', 'product_version' => $this->version->requireDistributable()];
            } catch (Throwable $exception) {
                if ($exception instanceof UpdateException && in_array($exception->codeName(), ['update_recovery_retry', 'update_quiescence_required'], true)) {
                    throw $exception;
                }
                $this->recordFailure();
                throw $this->recoveryRequired($exception);
            }
        } finally {
            $ownership->release();
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function reverse(array $state, UpdateMigrationState $before): array
    {
        if ($state['phase'] === 'rollback_required') {
            $cursor = $this->migrationState($state, 'migration_state');
            $state = $this->publish('rollback_required', 'migration_rollback', ['rollback_migration_state' => $cursor->toArray()]);
        }
        if ($state['phase'] === 'migration_rollback') {
            $cursor = $this->migrationState($state, 'rollback_migration_state');
            if (array_slice($cursor->ran, 0, count($before->ran)) !== $before->ran || count($cursor->ran) !== count($before->ran) + count($cursor->appliedSuffix)) {
                throw $this->recoveryRequired();
            }
            $this->migrations->rollbackExactSuffix($before, $cursor->appliedSuffix, function (UpdateMigrationState $remaining): void {
                try {
                    $this->journal->checkpoint('migration_rollback', ['rollback_migration_state' => $remaining->toArray()]);
                } catch (Throwable $exception) {
                    throw $this->retry($exception);
                }
            });
            $state = $this->publish('migration_rollback', 'source_switch_pending');
        }

        return $state;
    }

    /** @param  array<string, mixed>  $state */
    private function assertSourceSchema(array $state, UpdateMigrationState $before): void
    {
        $current = $this->migrations->snapshot();
        if ($current->ran !== $before->ran || $current->schemaFingerprintSha256 !== $before->schemaFingerprintSha256 || $this->schema->forConnection(DB::connection()) !== ($state['source_schema_fingerprint'] ?? null)) {
            throw $this->recoveryRequired();
        }
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $state
     */
    private function assertBooted(array $current, array $state, bool $source): void
    {
        $matches = $source ? $this->matchesSource($current, $state) : (
            ($current['kind'] ?? null) === 'slot'
            && ($current['release_id'] ?? null) === ($state['target_release_id'] ?? null)
            && ($current['product_version'] ?? null) === ($state['target_product_version'] ?? null)
            && ($current['package_digest_sha256'] ?? null) === ($state['package_digest_sha256'] ?? null)
        );
        if (! $matches || ($current['release_root'] ?? null) !== realpath(base_path()) || ($current['product_version'] ?? null) !== $this->version->requireDistributable()) {
            throw $this->invalidState();
        }
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $state
     */
    private function matchesSource(array $current, array $state): bool
    {
        return ($current['kind'] ?? null) === ($state['source_release_kind'] ?? null)
            && ($current['release_id'] ?? null) === ($state['source_release_id'] ?? null)
            && ($current['product_version'] ?? null) === ($state['source_product_version'] ?? null)
            && (($current['kind'] ?? null) === 'root' || ($current['package_digest_sha256'] ?? null) === ($state['source_package_digest_sha256'] ?? null));
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{kind:string,release_id:string,product_version:string,package_digest_sha256:string}
     */
    private function sourceRelease(array $state): array
    {
        $version = $this->required($state, 'source_product_version');
        $kind = $state['source_release_kind'] ?? null;
        $root = $this->pointer->installationRoot();
        if ($kind === 'root' && ($state['source_release_id'] ?? null) === null) {
            $this->assertSourceRuntime($root);
            if ((new ProductVersion($root.'/VERSION'))->requireDistributable() !== $version) {
                throw $this->recoveryRequired();
            }

            return ['kind' => 'root', 'release_id' => '', 'product_version' => $version, 'package_digest_sha256' => ''];
        }
        $id = $this->required($state, 'source_release_id');
        if ($kind !== 'slot' || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $id) !== 1) {
            throw $this->recoveryRequired();
        }
        $slot = $root.'/storage/app/lifecycle/update/releases/'.$id;
        if (is_link($slot) || ! is_dir($slot) || realpath($slot) !== $slot) {
            throw $this->recoveryRequired();
        }
        $this->assertSourceRuntime($slot);
        $digest = $this->required($state, 'source_package_digest_sha256');
        $marker = $this->readJson($slot.'/release.json');
        if ($marker !== ['schema' => 'seo-release-slot/1', 'release_id' => $id, 'product_version' => $version, 'package_digest_sha256' => $digest, 'launcher_schema' => 'seo-launcher/2'] || (new ProductVersion($slot.'/VERSION'))->requireDistributable() !== $version) {
            throw $this->recoveryRequired();
        }

        return ['kind' => 'slot', 'release_id' => $id, 'product_version' => $version, 'package_digest_sha256' => $digest];
    }

    /** @param  array<string, mixed>  $state */
    private function assertIdentity(array $state): void
    {
        foreach ($this->identity->capture() as $key => $actual) {
            if (! is_string($state[$key] ?? null) || ! hash_equals($state[$key], $actual)) {
                throw $this->recoveryRequired();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{string,string,string}
     */
    private function assertSafety(array $state): array
    {
        $id = $this->required($state, 'safety_snapshot_id');
        if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1) {
            throw $this->recoveryRequired();
        }
        $root = (string) config('update.safety_path', storage_path('app/lifecycle/update/safety'));
        $bundle = $root.'/'.$id.'.seobak';
        $metadata = $root.'/'.$id.'.json';
        if (is_link($root) || is_link($bundle) || ! is_file($bundle) || realpath($bundle) !== $bundle) {
            throw $this->recoveryRequired();
        }
        $digest = hash_file('sha256', $bundle);
        if (! is_string($digest) || ! hash_equals($this->required($state, 'safety_snapshot_sha256'), $digest)) {
            throw $this->recoveryRequired();
        }
        $stream = @fopen($bundle, 'rb');
        if ($stream === false) {
            throw $this->recoveryRequired();
        }
        try {
            $manifest = $this->reader->read($stream);
            $manifest->assertCompatible($this->required($state, 'source_product_version'), $this->required($state, 'source_schema_fingerprint'));
        } finally {
            fclose($stream);
        }
        $details = $this->readJson($metadata);
        $data = $manifest->toArray();
        if (($details['product_version'] ?? null) !== ($state['source_product_version'] ?? null) || ($data['source_installation_id'] ?? null) !== ($details['installation_id'] ?? null) || ($data['source_owner_user_id'] ?? null) !== ($details['owner_user_id'] ?? null)) {
            throw $this->recoveryRequired();
        }
        unset($details['product_version']);
        if (hash('sha256', UpdatePackageFormat::canonicalMetadataJson($details)) !== ($state['installation_identity_sha256'] ?? null)) {
            throw $this->recoveryRequired();
        }

        return [$bundle, $metadata, $id];
    }

    /** @param  array<string, mixed>  $state */
    private function migrationState(array $state, string $key): UpdateMigrationState
    {
        if (! is_array($state[$key] ?? null)) {
            throw $this->recoveryRequired();
        }

        return UpdateMigrationState::fromArray($state[$key]);
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        if (is_link($path) || ! is_file($path) || realpath($path) !== $path) {
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

    /** @param  array<string, mixed>  $state */
    private function required(array $state, string $key): string
    {
        $value = $state[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw $this->recoveryRequired();
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function publish(string $from, string $to, array $evidence = []): array
    {
        try {
            return $this->journal->transition($from, $to, $evidence);
        } catch (Throwable $exception) {
            throw $this->retry($exception);
        }
    }

    private function assertSourceRuntime(string $root): void
    {
        foreach (['bootstrap/app.php', 'bootstrap/runtime-release.php', 'vendor/autoload.php'] as $relative) {
            $path = $root.'/'.$relative;
            if (is_link($path) || ! is_file($path) || realpath($path) !== $path || ! is_readable($path)) {
                throw $this->recoveryRequired();
            }
        }
    }

    private function retry(Throwable $previous): UpdateException
    {
        return new UpdateException('update_recovery_retry', 'بازیابی در نقطه امن متوقف شد؛ پس از رفع مشکل نوشتن فایل، دوباره ادامه دهید.', $previous);
    }

    /** @param  array<string, mixed>  $state */
    private function isBootstrapRecovery(array $state): bool
    {
        return $state['phase'] === 'recovery_required'
            && ($state['recovery_code'] ?? null) === 'update_target_bootstrap_failed'
            && ($state['recovery_reverted_to_source'] ?? null) === true
            && ($state['migration_started'] ?? null) === false;
    }

    private function recordFailure(): void
    {
        try {
            $phase = $this->journal->state()['phase'] ?? null;
            if (is_string($phase) && in_array($phase, ['rollback_required', 'migration_rollback', 'source_switch_pending', 'snapshot_restore_required'], true)) {
                $this->journal->transition($phase, 'recovery_required', ['error_code' => 'update_recovery_required']);
            }
        } catch (Throwable) {
            // Preserve the last durable maintenance phase if publication itself fails.
        }
    }

    private function invalidState(): UpdateException
    {
        return new UpdateException('update_invalid_state', 'برای بازیابی باید درخواست تازه‌ای در نسخه درست برنامه اجرا شود.');
    }

    private function recoveryRequired(?Throwable $previous = null): UpdateException
    {
        return new UpdateException('update_recovery_required', 'شواهد بازیابی ایمن کامل نیست؛ حالت نگهداری و فایل‌های ایمنی حفظ شدند.', $previous);
    }
}
