<?php

namespace App\Services\Backup;

final class DatabaseRowCodec
{
    private const BINARY_TAG = '__seo_backup_binary_base64';

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $families
     * @return array<string, mixed>
     */
    public function encodeRow(array $row, array $families): array
    {
        $this->assertSameColumns($row, $families, true);
        $encoded = [];

        foreach ($families as $column => $family) {
            $value = $row[$column];

            if ($value === null) {
                $encoded[$column] = null;

                continue;
            }

            if ($family === 'binary') {
                if (! is_string($value)) {
                    throw $this->integrityFailure();
                }

                $encoded[$column] = [self::BINARY_TAG => base64_encode($value)];

                continue;
            }

            if (is_float($value) || is_array($value) || is_object($value) || is_resource($value)) {
                throw $this->integrityFailure();
            }

            if (is_bool($value)) {
                $encoded[$column] = $value ? '1' : '0';

                continue;
            }

            if (is_int($value) || is_string($value)) {
                $encoded[$column] = (string) $value;

                continue;
            }

            throw $this->integrityFailure();
        }

        return $encoded;
    }

    /**
     * @param  array<string, mixed>  $encoded
     * @param  array<string, string>  $families
     * @return array<string, mixed>
     */
    public function decodeRow(array $encoded, array $families): array
    {
        $this->assertSameColumns($encoded, $families, false);
        $decoded = [];

        foreach ($families as $column => $family) {
            $value = $encoded[$column];

            if ($value === null) {
                $decoded[$column] = null;

                continue;
            }

            if ($family === 'binary') {
                if (! is_array($value) || array_keys($value) !== [self::BINARY_TAG]) {
                    throw $this->invalidBundle();
                }

                $base64 = $value[self::BINARY_TAG] ?? null;
                if (! is_string($base64)) {
                    throw $this->invalidBundle();
                }

                $binary = base64_decode($base64, true);
                if ($binary === false) {
                    throw $this->invalidBundle();
                }

                $decoded[$column] = $binary;

                continue;
            }

            if (! is_string($value)) {
                throw $this->invalidBundle();
            }

            $decoded[$column] = $value;
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $families
     */
    private function assertSameColumns(array $values, array $families, bool $capturing): void
    {
        $valueKeys = array_keys($values);
        $familyKeys = array_keys($families);
        sort($valueKeys, SORT_STRING);
        sort($familyKeys, SORT_STRING);

        if ($valueKeys === $familyKeys) {
            return;
        }

        throw $capturing ? $this->integrityFailure() : $this->invalidBundle();
    }

    private function integrityFailure(): BackupException
    {
        return new BackupException(
            'backup_integrity_failed',
            'اعتبارسنجی داده‌های پشتیبان ناموفق بود.',
        );
    }

    private function invalidBundle(): BackupException
    {
        return new BackupException(
            'restore_invalid_bundle',
            'فایل پشتیبان معتبر نیست.',
        );
    }
}
