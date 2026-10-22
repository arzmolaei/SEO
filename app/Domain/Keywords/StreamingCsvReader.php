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
