<?php

namespace App\Services\Backup;

use JsonException;

final class DatabaseRowCodec
{
    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $families
     */
    public static function encodeRow(array $row, array $families): string
    {
        ksort($row, SORT_STRING);

        $encoded = [];

        foreach ($row as $column => $value) {
            $family = $families[$column] ?? 'text';
            $encoded[$column] = self::encodeValue($column, $value, $family);
        }

        try {
            return json_encode(
                $encoded,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new BackupException('Unable to encode database row.', previous: $exception);
        }
    }

    /**
     * @param  array<string, string>  $families
     * @return array<string, mixed>
     */
    public static function decodeRow(string $encoded, array $families): array
    {
        try {
            $row = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BackupException('Unable to decode database row.', previous: $exception);
        }

        if (! is_array($row) || array_is_list($row)) {
            throw new BackupException('Database row payload must be a JSON object.');
        }

        foreach ($row as $column => $value) {
            if (! is_string($column)) {
                throw new BackupException('Database row column name is invalid.');
            }

            $family = $families[$column] ?? 'text';
            $row[$column] = self::decodeValue($column, $value, $family);
        }

        return $row;
    }

    private static function encodeValue(string $column, mixed $value, string $family): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($family) {
            'binary' => self::encodeBinary($column, $value),
            'text', 'json' => self::encodeTextual($column, $value),
            default => throw new BackupException("Unsupported database column family for {$column}: {$family}"),
        };
    }

    private static function decodeValue(string $column, mixed $value, string $family): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($family) {
            'binary' => self::decodeBinary($column, $value),
            'text', 'json' => self::decodeTextual($column, $value),
            default => throw new BackupException("Unsupported database column family for {$column}: {$family}"),
        };
    }

    private static function encodeBinary(string $column, mixed $value): string
    {
        if (! is_string($value)) {
            throw new BackupException("Binary database value must be a string: {$column}");
        }

        return 'base64:'.base64_encode($value);
    }

    private static function decodeBinary(string $column, mixed $value): string
    {
        if (! is_string($value) || ! str_starts_with($value, 'base64:')) {
            throw new BackupException("Invalid base64 database value: {$column}");
        }

        $decoded = base64_decode(substr($value, 7), true);

        if ($decoded === false) {
            throw new BackupException("Invalid base64 database value: {$column}");
        }

        return $decoded;
    }

    private static function encodeTextual(string $column, mixed $value): string|int|bool
    {
        if (is_float($value)) {
            throw new BackupException("Floating-point database values are not allowed: {$column}");
        }

        if (! is_string($value) && ! is_int($value) && ! is_bool($value)) {
            throw new BackupException("Unsupported database scalar value: {$column}");
        }

        return $value;
    }

    private static function decodeTextual(string $column, mixed $value): string|int|bool
    {
        if (! is_string($value) && ! is_int($value) && ! is_bool($value)) {
            throw new BackupException("Unsupported database scalar value: {$column}");
        }

        return $value;
    }
}
