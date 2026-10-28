<?php

namespace App\Services\Update;

use App\Services\Backup\RestoreJournal;
use App\Support\Lifecycle\LifecycleMutex;
use App\Support\Lifecycle\LifecycleMutexHandle;
use App\Support\Update\ActiveReleasePointer;

final class SwitchSystemUpdateRelease
{
    public function __construct(
        private readonly UpdateJournal $journal,
        private readonly RestoreJournal $restoreJournal,
        private readonly LifecycleMutex $mutex,
        private readonly UpdateQuiescence $quiescence,
        private readonly ActiveReleasePointer $activeReleasePointer,
    ) {}

    /** @return array{status: 'target_active', target_version: string} */
    public function handle(string $expectedDigest): array
    {
        $state = $this->stagedState($expectedDigest);
        $uploadId = $this->requiredUploadId($state);
        $ownership = $this->mutex->acquire('update');

        try {
            return $this->switchUnderLock($ownership, $uploadId, $expectedDigest);
        } finally {
            $ownership->release();
        }
    }

    /** @return array{status: 'target_active', target_version: string} */
    private function switchUnderLock(
        LifecycleMutexHandle $ownership,
        string $uploadId,
        string $expectedDigest,
    ): array {
        $ownership->assertActive();
        $state = $this->stagedState($expectedDigest);
        if ($this->requiredUploadId($state) !== $uploadId) {
            throw $this->invalidState();
        }

        if ($this->restoreJournal->hasActiveOperation()) {
            throw new UpdateException('update_lifecycle_conflict', 'یک عملیات بازیابی دیگر باید ابتدا تعیین تکلیف شود.');
        }

        $this->assertPackageDigest($uploadId, $expectedDigest);
        $this->quiescence->assertReady();
        $this->assertSourceRelease($state);

        $targetReleaseId = $this->requiredReleaseId($state, 'target_release_id');
        $targetVersion = $this->requiredVersion($state, 'target_product_version');

        $ownership->assertActive();
        $this->journal->transition('staged', 'switch_pending');

        $ownership->assertActive();
        $this->activeReleasePointer->activateSlot(
            $targetReleaseId,
            $targetVersion,
            $expectedDigest,
        );

        $this->journal->transition('switch_pending', 'target_active', [
            'migration_started' => false,
        ]);
        $ownership->assertActive();

        return [
            'status' => 'target_active',
            'target_version' => $targetVersion,
        ];
    }

    /** @return array<string, mixed> */
    private function stagedState(string $expectedDigest): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $expectedDigest) !== 1) {
            throw $this->invalidState();
        }

        $state = $this->journal->state();
        if (
            ! is_array($state)
            || ($state['phase'] ?? null) !== 'staged'
            || ($state['package_digest_sha256'] ?? null) !== $expectedDigest
        ) {
            throw $this->invalidState();
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    private function assertSourceRelease(array $state): void
    {
        $sourceKind = $state['source_release_kind'] ?? null;
        $sourceReleaseId = $state['source_release_id'] ?? null;
        $sourceVersion = $state['source_product_version'] ?? null;
        if (
            ! in_array($sourceKind, ['root', 'slot'], true)
            || ! is_string($sourceVersion)
            || $sourceVersion === ''
        ) {
            throw $this->invalidState();
        }

        $current = $this->activeReleasePointer->current();
        if (
            ($current['kind'] ?? null) !== $sourceKind
            || ($current['product_version'] ?? null) !== $sourceVersion
        ) {
            throw $this->sourceMismatch();
        }

        if ($sourceKind === 'root') {
            if ($sourceReleaseId !== null || ($current['release_id'] ?? null) !== null) {
                throw $this->sourceMismatch();
            }

            return;
        }

        if (
            ! is_string($sourceReleaseId)
            || $sourceReleaseId === ''
            || ($current['release_id'] ?? null) !== $sourceReleaseId
        ) {
            throw $this->sourceMismatch();
        }
    }

    private function assertPackageDigest(string $uploadId, string $expectedDigest): void
    {
        $path = $this->uploadPath($uploadId);
        if (is_link($path) || ! is_file($path)) {
            throw $this->packageChanged();
        }

        $actualDigest = hash_file('sha256', $path);
        if (! is_string($actualDigest) || ! hash_equals($expectedDigest, $actualDigest)) {
            throw $this->packageChanged();
        }
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

    /** @param array<string, mixed> $state */
    private function requiredReleaseId(array $state, string $key): string
    {
        $releaseId = $state[$key] ?? null;
        if (! is_string($releaseId) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $releaseId) !== 1) {
            throw $this->invalidState();
        }

        return $releaseId;
    }

    /** @param array<string, mixed> $state */
    private function requiredVersion(array $state, string $key): string
    {
        $version = $state[$key] ?? null;
        if (
            ! is_string($version)
            || $version === 'dev'
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9.+_-]{0,127}\z/', $version) !== 1
        ) {
            throw $this->invalidState();
        }

        return $version;
    }

    private function uploadPath(string $uploadId): string
    {
        $directory = (string) config(
            'update.upload_path',
            storage_path('app/lifecycle/update/uploads'),
        );

        return rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$uploadId.'.seoupdate';
    }

    private function invalidState(): UpdateException
    {
        return new UpdateException('update_invalid_state', 'وضعیت ایمن به‌روزرسانی معتبر نیست.');
    }

    private function packageChanged(): UpdateException
    {
        return new UpdateException(
            'update_package_changed',
            'فایل به‌روزرسانی پس از تأیید تغییر کرده است.',
        );
    }

    private function sourceMismatch(): UpdateException
    {
        return new UpdateException(
            'update_source_mismatch',
            'نسخه فعال با مبدا ثبت‌شده به‌روزرسانی سازگار نیست.',
        );
    }
}
