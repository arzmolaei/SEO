<?php

namespace App\Services\Exports;

use Carbon\CarbonImmutable;
use RuntimeException;

final class ProjectSeoExportResult
{
    /** @var resource|null */
    private $stream;

    /** @param resource $stream */
    public function __construct(
        $stream,
        private readonly string $filename,
        private readonly string $mimeType,
        private readonly int $rowCount,
        private readonly string $schemaVersion,
        private readonly string $sha256,
        private readonly CarbonImmutable $generatedAt,
    ) {
        if (! is_resource($stream)) {
            throw new RuntimeException('Export stream is unavailable.');
        }

        $this->stream = $stream;
    }

    /** @return resource */
    public function stream()
    {
        if (! is_resource($this->stream)) {
            throw new RuntimeException('Export stream is closed.');
        }

        return $this->stream;
    }

    public function filename(): string
    {
        return $this->filename;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    public function schemaVersion(): string
    {
        return $this->schemaVersion;
    }

    public function sha256(): string
    {
        return $this->sha256;
    }

    public function generatedAt(): CarbonImmutable
    {
        return $this->generatedAt;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
