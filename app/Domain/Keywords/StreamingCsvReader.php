<?php

namespace App\Domain\Keywords;

use Generator;
use RuntimeException;

final class StreamingCsvReader
{
    /**
     * @return Generator<int, array<string, string|null>>
     */
    public function rows(string $path): Generator
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open CSV file: {$path}");
        }

        try {
            $this->prepareEncoding($handle);

            $header = fgetcsv($handle, null, ',', '"', '');

            if ($header === false) {
                return;
            }

            $headers = $this->normalizeHeaders($header);

            while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if ($this->isEmptyRow($values)) {
                    continue;
                }

                $row = [];

                foreach ($headers as $index => $name) {
                    $row[$name] = $values[$index] ?? null;
                }

                yield $row;
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function prepareEncoding($handle): void
    {
        $prefix = fread($handle, 4);

        if ($prefix === false) {
            throw new RuntimeException('Unable to inspect CSV encoding.');
        }

        $encoding = null;
        $offset = 0;

        if (str_starts_with($prefix, "\xEF\xBB\xBF")) {
            $offset = 3;
        } elseif (str_starts_with($prefix, "\xFF\xFE")) {
            $encoding = 'UTF-16LE';
            $offset = 2;
        } elseif (str_starts_with($prefix, "\xFE\xFF")) {
            $encoding = 'UTF-16BE';
            $offset = 2;
        }

        if (fseek($handle, $offset) !== 0) {
            throw new RuntimeException('Unable to position CSV stream after encoding detection.');
        }

        if ($encoding === null) {
            return;
        }

        $filter = stream_filter_append(
            $handle,
            "convert.iconv.{$encoding}/UTF-8",
            STREAM_FILTER_READ,
        );

        if ($filter === false) {
            throw new RuntimeException("Unable to convert {$encoding} CSV stream to UTF-8.");
        }
    }

    /**
     * @param  array<int, string|null>  $header
     * @return list<string>
     */
    private function normalizeHeaders(array $header): array
    {
        $headers = [];

        foreach ($header as $index => $value) {
            $name = (string) ($value ?? '');

            if ($index === 0) {
                $name = preg_replace('/^\xEF\xBB\xBF/', '', $name) ?? $name;
            }

            $headers[] = $name;
        }

        return $headers;
    }

    /** @param array<int, string|null> $values */
    private function isEmptyRow(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }
}
