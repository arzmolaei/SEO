<?php

namespace App\Services\Backup;

use App\Support\Lifecycle\LifecycleMutex;
use App\Support\Lifecycle\LifecycleMutexHandle;
use App\Support\Lifecycle\MaintenanceWindow;
use Throwable;

final class CreateSystemBackup
{
    public function __construct(
        private readonly LifecycleMutex $mutex,
        private readonly MaintenanceWindow $maintenanceWindow,
        private readonly CaptureSystemState $captureSystemState,
    ) {}

    public function handle(): SystemBackupResult
    {
        $handle = null;
        $captured = null;

        try {
            $handle = $this->acquire();
            $captured = $this->maintenanceWindow->run(
                'backup',
                fn (): CapturedSystemState => $this->captureSystemState->handle($handle),
            );
        } catch (BackupException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->generationFailure($exception);
        } finally {
            if ($handle instanceof LifecycleMutexHandle) {
                $handle->release();
            }
        }

        try {
            return new SystemBackupResult($captured, $this->filename($captured->manifest()));
        } catch (BackupException $exception) {
            $captured->close();

            throw $exception;
        } catch (Throwable $exception) {
            $captured->close();

            throw $this->generationFailure($exception);
        }
    }

    private function acquire(): LifecycleMutexHandle
    {
        try {
            return $this->mutex->acquire('backup');
        } catch (Throwable $exception) {
            throw new BackupException(
                'backup_unavailable',
                'امکان شروع عملیات پشتیبان‌گیری در حال حاضر وجود ندارد. دوباره تلاش کنید.',
                $exception,
            );
        }
    }

    private function filename(BackupManifest $manifest): string
    {
        $data = $manifest->toArray();
        $installationId = $data['source_installation_id'] ?? null;
        $createdAt = $data['created_at_utc'] ?? null;

        if (
            ! is_string($installationId)
            || preg_match('/\A[a-f0-9]{32}\z/', $installationId) !== 1
            || ! is_string($createdAt)
            || preg_match('/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})Z\z/', $createdAt, $matches) !== 1
        ) {
            throw new BackupException(
                'backup_integrity_failed',
                'اعتبارسنجی داده‌های پشتیبان ناموفق بود.',
            );
        }

        $timestamp = $matches[1].$matches[2].$matches[3].'T'.$matches[4].$matches[5].$matches[6].'Z';

        return 'seo-backup-v1-'.substr($installationId, 0, 8).'-'.$timestamp.'.seobak';
    }

    private function generationFailure(Throwable $exception): BackupException
    {
        return new BackupException(
            'backup_generation_failed',
            'تولید فایل پشتیبان انجام نشد. دوباره تلاش کنید.',
            $exception,
        );
    }
}
