<?php

namespace App\Support\Exports;

use HashContext;
use InvalidArgumentException;
use RuntimeException;

final class SpreadsheetCsvWriter
{
    /** @var resource */
    private $stream;

    private HashContext $hash;

    private int $rowCount = 0;

    private bool $begun = false;

    /** @param resource $stream */
    public function __construct($stream)
    {
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('CSV stream must be a resource.');
        }

        $this->stream = $stream;
        $this->hash = hash_init('sha256');
    }

    /** @param list<string> $headers */
    public function begin(array $headers): void
    {
        if ($this->begun) {
            throw new RuntimeException('CSV writer has already begun.');
        }

        $this->begun = true;
        $this->writeBytes("\xEF\xBB\xBF".$this->encodeRow($headers));
    }

    /** @param list<mixed> $cells */
    public function write(array $cells): void
    {
        if (! $this->begun) {
            throw new RuntimeException('CSV writer must begin before writing rows.');
        }

        $this->writeBytes($this->encodeRow($cells));
        $this->rowCount++;
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    public function sha256(): string
    {
        return hash_final(hash_copy($this->hash));
    }

    public function rewind(): void
    {
        if (! rewind($this->stream)) {
            throw new RuntimeException('CSV stream could not be rewound.');
        }
    }

    /** @param list<mixed> $cells */
    private function encodeRow(array $cells): string
    {
        return implode(',', array_map($this->encodeCell(...), $cells))."\r\n";
    }

    private function encodeCell(mixed $value): string
    {
        $encoded = match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            is_string($value) => $value,
            default => throw new InvalidArgumentException('CSV cells must be scalar or null.'),
        };

        if (strpbrk($encoded, ",\"\r\n") === false) {
            return $encoded;
        }

        return '"'.str_replace('"', '""', $encoded).'"';
    }

    private function writeBytes(string $bytes): void
    {
        $length = strlen($bytes);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite($this->stream, substr($bytes, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('CSV stream write failed.');
            }

            hash_update($this->hash, substr($bytes, $offset, $written));
            $offset += $written;
        }
    }
}
