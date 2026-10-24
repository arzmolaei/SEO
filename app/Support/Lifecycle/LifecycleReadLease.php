<?php

namespace App\Support\Lifecycle;

final class LifecycleReadLease
{
    /** @var resource|null */
    private mixed $stream;

    /** @param resource $stream */
    public function __construct(mixed $stream)
    {
        $this->stream = $stream;
    }

    public function release(): void
    {
        if (is_resource($this->stream)) {
            @flock($this->stream, LOCK_UN);
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
