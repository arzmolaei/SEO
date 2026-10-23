<?php

namespace App\Support\Lifecycle;

use RuntimeException;

final class LifecycleMutexHandle
{
    /** @var resource|null */
    private mixed $stream;

    /** @param resource $stream */
    public function __construct(
        mixed $stream,
        private readonly string $operation,
        private readonly string $ownerToken,
    ) {
        $this->stream = $stream;
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function ownerToken(): string
    {
        return $this->ownerToken;
    }

    public function isActive(): bool
    {
        return is_resource($this->stream);
    }

    public function assertActive(): void
    {
        if (! $this->isActive()) {
            throw new RuntimeException('Lifecycle mutex ownership is no longer active.');
        }
    }

    public function release(): void
    {
        if (! is_resource($this->stream)) {
            return;
        }

        $stream = $this->stream;
        $this->stream = null;

        @rewind($stream);
        @ftruncate($stream, 0);
        @fflush($stream);
        @flock($stream, LOCK_UN);
        @fclose($stream);
    }

    public function __destruct()
    {
        $this->release();
    }
}
