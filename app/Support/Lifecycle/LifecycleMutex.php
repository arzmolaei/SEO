<?php

namespace App\Support\Lifecycle;

use InvalidArgumentException;
use RuntimeException;

final class LifecycleMutex
{
    public function __construct(
        private readonly ?string $lockPath = null,
        private readonly ?RuntimeReleaseAdmission $releaseAdmission = null,
    ) {}

    public function acquire(string $operation): LifecycleMutexHandle
    {
        $operation = trim($operation);

        if ($operation === '' || strlen($operation) > 64 || preg_match('/\A[a-z0-9][a-z0-9._:-]*\z/', $operation) !== 1) {
            throw new InvalidArgumentException('Lifecycle operation name is invalid.');
        }

        $stream = $this->openStream();

        if (! flock($stream, LOCK_EX | LOCK_NB)) {
            fclose($stream);

            throw new RuntimeException('Another lifecycle operation is already running.');
        }

        try {
            ($this->releaseAdmission ?? new RuntimeReleaseAdmission)->assertCurrent();
        } catch (\Throwable $exception) {
            @flock($stream, LOCK_UN);
            @fclose($stream);

            throw $exception;
        }

        $ownerToken = bin2hex(random_bytes(16));
        $metadata = json_encode([
            'operation' => $operation,
            'started_at' => gmdate(DATE_ATOM),
            'owner_token' => $ownerToken,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

        try {
            if (! rewind($stream) || ! ftruncate($stream, 0)) {
                throw new RuntimeException('Unable to prepare lifecycle mutex metadata.');
            }

            if (fwrite($stream, $metadata) !== strlen($metadata) || ! fflush($stream)) {
                throw new RuntimeException('Unable to write lifecycle mutex metadata.');
            }
        } catch (\Throwable $exception) {
            @flock($stream, LOCK_UN);
            @fclose($stream);

            throw $exception;
        }

        return new LifecycleMutexHandle($stream, $operation, $ownerToken);
    }

    public function acquireReadLease(): LifecycleReadLease
    {
        $stream = $this->openStream();
        if (! flock($stream, LOCK_SH | LOCK_NB)) {
            fclose($stream);

            throw new RuntimeException('Another lifecycle operation is already running.');
        }
        try {
            ($this->releaseAdmission ?? new RuntimeReleaseAdmission)->assertCurrent();
        } catch (\Throwable $exception) {
            @flock($stream, LOCK_UN);
            @fclose($stream);

            throw $exception;
        }

        return new LifecycleReadLease($stream);
    }

    /** @return resource */
    private function openStream(): mixed
    {
        $path = $this->lockPath ?? (string) config(
            'backup.lifecycle_mutex_path',
            storage_path('app/lifecycle/lifecycle.lock'),
        );
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create lifecycle state directory.');
        }

        @chmod($directory, 0700);

        $stream = @fopen($path, 'c+');
        if ($stream === false) {
            throw new RuntimeException('Unable to open lifecycle mutex.');
        }

        @chmod($path, 0600);

        return $stream;
    }
}
