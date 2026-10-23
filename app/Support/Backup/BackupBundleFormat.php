<?php

namespace App\Support\Backup;

use App\Services\Backup\ApplicationTableCatalog;
use App\Services\Backup\BackupException;

final class BackupBundleFormat
{
    public const MAGIC = "SEOBACKUP\n";

    private const UINT32_BASE = 4_294_967_296;

    /** @param array<string, mixed> $metadata */
    public static function canonicalMetadataJson(array $metadata): string
    {
        return json_encode(
            self::canonicalize($metadata),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    public static function packUint64(int $value): string
    {
        if ($value < 0) {
            throw new BackupException(
                'backup_integrity_failed',
                'اعتبارسنجی داده‌های پشتیبان ناموفق بود.',
            );
        }

        $high = intdiv($value, self::UINT32_BASE);
        $low = $value % self::UINT32_BASE;

        return pack('N2', $high, $low);
    }

    public static function unpackUint64(string $bytes, int $maximum): int
    {
        if (strlen($bytes) !== 8 || $maximum < 0) {
            throw self::invalidBundle();
        }

        $parts = unpack('Nhigh/Nlow', $bytes);
        if (! is_array($parts)) {
            throw self::invalidBundle();
        }

        $high = (int) $parts['high'];
        $low = (int) $parts['low'];
        $maxHigh = intdiv($maximum, self::UINT32_BASE);
        $maxLow = $maximum % self::UINT32_BASE;

        if ($high > $maxHigh || ($high === $maxHigh && $low > $maxLow)) {
            throw self::invalidBundle();
        }

        return ($high * self::UINT32_BASE) + $low;
    }

    public static function assertCapturePath(string $path): void
    {
        self::assertPath($path, false);
    }

    public static function assertRestorePath(string $path): void
    {
        self::assertPath($path, true);
    }

    private static function assertPath(string $path, bool $restore): void
    {
        $invalid = $path === ''
            || str_contains($path, '\\')
            || str_starts_with($path, '/')
            || preg_match('/\A[A-Za-z]:/', $path) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1;

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                $invalid = true;
                break;
            }
        }

        if (! $invalid) {
            $invalid = ! self::isAllowlistedPath($path);
        }

        if (! $invalid) {
            return;
        }

        if ($restore) {
            throw new BackupException(
                'restore_path_rejected',
                'مسیر موجود در فایل پشتیبان مجاز نیست.',
            );
        }

        throw new BackupException(
            'backup_integrity_failed',
            'اعتبارسنجی داده‌های پشتیبان ناموفق بود.',
        );
    }

    private static function isAllowlistedPath(string $path): bool
    {
        if ($path === 'settings/runtime.json') {
            return true;
        }

        if (str_starts_with($path, 'database/') && str_ends_with($path, '.ndjson')) {
            $table = substr($path, strlen('database/'), - strlen('.ndjson'));

            return in_array($table, ApplicationTableCatalog::TABLES, true)
                && $path === "database/{$table}.ndjson";
        }

        foreach (['storage/app/private/uploads/', 'storage/app/public/uploads/'] as $prefix) {
            if (str_starts_with($path, $prefix) && strlen($path) > strlen($prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $result = [];
            foreach ($value as $item) {
                $result[] = self::canonicalize($item);
            }

            return $result;
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }

    private static function invalidBundle(): BackupException
    {
        return new BackupException(
            'restore_invalid_bundle',
            'فایل پشتیبان معتبر نیست.',
        );
    }
}
