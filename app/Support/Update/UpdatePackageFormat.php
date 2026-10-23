<?php

namespace App\Support\Update;

use App\Services\Update\UpdateException;

final class UpdatePackageFormat
{
    public const MAGIC = "SEOUPDATE\n";

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
            throw self::invalidPackage();
        }

        return pack('N2', intdiv($value, self::UINT32_BASE), $value % self::UINT32_BASE);
    }

    public static function unpackUint64(string $bytes, int $maximum): int
    {
        if (strlen($bytes) !== 8 || $maximum < 0) {
            throw self::invalidPackage();
        }

        $parts = unpack('Nhigh/Nlow', $bytes);
        if (! is_array($parts)) {
            throw self::invalidPackage();
        }

        $high = (int) $parts['high'];
        $low = (int) $parts['low'];
        $maxHigh = intdiv($maximum, self::UINT32_BASE);
        $maxLow = $maximum % self::UINT32_BASE;

        if ($high > $maxHigh || ($high === $maxHigh && $low > $maxLow)) {
            throw self::invalidPackage();
        }

        return ($high * self::UINT32_BASE) + $low;
    }

    public static function assertPackagePath(string $path): void
    {
        $invalid = $path === ''
            || str_contains($path, '\\')
            || str_starts_with($path, '/')
            || preg_match('/\A[A-Za-z]:/', $path) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1;

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                $invalid = true;
                break;
            }
        }

        if (! $invalid) {
            $invalid = ! self::isAllowlistedPath($path);
        }

        if ($invalid) {
            throw self::invalidPackage();
        }
    }

    private static function isAllowlistedPath(string $path): bool
    {
        if (in_array($path, ['VERSION', 'artisan', 'composer.json', 'composer.lock', '.env.example'], true)) {
            return true;
        }

        foreach ([
            'app/',
            'bootstrap/',
            'config/',
            'database/migrations/',
            'resources/views/',
            'routes/',
            'vendor/',
            'public/',
        ] as $prefix) {
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

    private static function invalidPackage(): UpdateException
    {
        return new UpdateException('update_invalid_package', 'بسته به‌روزرسانی معتبر نیست.');
    }
}
