<?php

namespace App\Services\Backup;

final class SystemBackupResult
{
    public function __construct(
        private readonly CapturedSystemState $capturedState,
        private readonly string $filename,
    ) {}

    /** @return resource */
    public function stream()
    {
        return $this->capturedState->stream();
    }

    public function filename(): string
    {
        return $this->filename;
    }

    public function close(): void
    {
        $this->capturedState->close();
    }

    public function __destruct()
    {
        $this->close();
    }
}
