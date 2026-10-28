<?php

namespace App\Support;

/**
 * The largest request body the PHP host accepts for a file upload, so the UI
 * can refuse an oversized lifecycle file before sending it and explain the
 * host setting to change, instead of PHP's raw "POST data is too large".
 */
final class HostUploadLimit
{
    public static function current(): ?int
    {
        return self::fromIni((string) ini_get('upload_max_filesize'), (string) ini_get('post_max_size'));
    }

    /** @return int|null bytes, or null when the host sets no limit */
    public static function fromIni(string $uploadMaxFilesize, string $postMaxSize): ?int
    {
        $limits = array_filter(
            [self::bytes($uploadMaxFilesize), self::bytes($postMaxSize)],
            static fn (int $value): bool => $value > 0,
        );

        return $limits === [] ? null : min($limits);
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if (preg_match('/\A(\d+)\s*([kmg]?)\z/i', $value, $match) !== 1) {
            return 0;
        }

        return (int) $match[1] * match (strtolower($match[2])) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };
    }
}
