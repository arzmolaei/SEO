<?php

namespace App\Services\Backup;

use App\Support\Lifecycle\LifecycleMutex;
use App\Support\Lifecycle\LifecycleMutexHandle;
use App\Support\Lifecycle\MaintenanceWindow;
use Throwable;

final class RecoverInterruptedRestore
{
    public function __construct(
        private readonly ApplySystemStateSnapshot $applySystemStateSnapshot,
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

        try {
            $this->applySystemStateSnapshot->handle(
                $safetyBundlePath,
                $safetyMetadataPath,
                $recoveryRoot,
                $ownership,
            );

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
