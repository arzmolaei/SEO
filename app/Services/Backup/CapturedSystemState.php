<?php

namespace App\Services\Backup;

use RuntimeException;

final class CapturedSystemState
{
    private bool $closed = false;

    /** @param resource $stream */
    public function __construct(
        private $stream,
        private readonly BackupManifest $manifest,
    ) {}

    public function manifest(): BackupManifest
    {
        return $this->manifest;
    }

    /** @return resource */
    public function stream()
    {
        if ($this->closed || ! is_resource($this->stream)) {
            throw new RuntimeException('Captured system state is closed.');
        }

        return $this->stream;
    }

    public function rewind(): void
    {
        if (! rewind($this->stream())) {
            throw new RuntimeException('Unable to rewind captured system state.');
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
