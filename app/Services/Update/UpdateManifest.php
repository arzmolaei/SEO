<?php

namespace App\Services\Update;

use App\Support\Update\UpdatePackageFormat;

final class UpdateManifest
{
    public const SCHEMA = 'seo-update/1';

    public const LAUNCHER_SCHEMA = 'seo-launcher/2';

    /** @var list<string> */
    private const REQUIRED_FIELDS = [
        'schema',
        'created_at_utc',
        'target_product_version',
        'allowed_source_product_versions',
        'channel',
        'launcher_schema',
        'minimum_php_version',
        'required_php_extensions',
        'source_schema_fingerprint',
        'target_schema_fingerprint',
        'migration_tree_sha256',
        'entries',
        'content_digest_sha256',
    ];

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        self::assertExactKeys($data, self::REQUIRED_FIELDS);

        if (($data['schema'] ?? null) !== self::SCHEMA || ($data['launcher_schema'] ?? null) !== self::LAUNCHER_SCHEMA) {
            throw self::invalidPackage();
        }

        self::requireNonEmptyString($data['created_at_utc'] ?? null);
        self::assertStableVersion($data['target_product_version'] ?? null);

        $sources = $data['allowed_source_product_versions'] ?? null;
        if (! is_array($sources) || ! array_is_list($sources) || count($sources) !== 1) {
            throw self::invalidPackage();
        }
        self::assertStableVersion($sources[0] ?? null);

        if (! in_array($data['channel'] ?? null, ['stable', 'beta', 'development'], true)) {
            throw self::invalidPackage();
        }

        self::assertStableVersion($data['minimum_php_version'] ?? null);
        self::validateExtensions($data['required_php_extensions'] ?? null);
        self::assertSha256($data['source_schema_fingerprint'] ?? null);
        self::assertSha256($data['target_schema_fingerprint'] ?? null);
        self::assertSha256($data['migration_tree_sha256'] ?? null);
        self::validateEntries($data['entries'] ?? null);
        self::assertSha256($data['content_digest_sha256'] ?? null);

        return new self($data);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function canonicalJson(): string
    {
        return json_encode(
            self::canonicalize($this->data),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private static function validateExtensions(mixed $extensions): void
    {
        if (! is_array($extensions) || ! array_is_list($extensions)) {
            throw self::invalidPackage();
        }

        $seen = [];
        foreach ($extensions as $extension) {
            $extension = self::requireNonEmptyString($extension);
            if (isset($seen[$extension]) || preg_match('/\A[a-z0-9_]+\z/i', $extension) !== 1) {
                throw self::invalidPackage();
            }
            $seen[$extension] = true;
        }

        $sorted = $extensions;
        sort($sorted, SORT_STRING);
        if ($extensions !== $sorted) {
            throw self::invalidPackage();
        }
    }

    private static function validateEntries(mixed $entries): void
    {
        if (! is_array($entries) || ! array_is_list($entries) || $entries === []) {
            throw self::invalidPackage();
        }

        $paths = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw self::invalidPackage();
            }

            self::assertExactKeys($entry, ['path', 'size', 'sha256', 'media_type']);
            $path = self::requireNonEmptyString($entry['path'] ?? null);
            UpdatePackageFormat::assertPackagePath($path);

            if (isset($paths[$path])) {
                throw self::invalidPackage();
            }
            $paths[$path] = true;

            if (! is_int($entry['size'] ?? null) || $entry['size'] < 0) {
                throw self::invalidPackage();
            }
            self::assertSha256($entry['sha256'] ?? null);
            self::requireNonEmptyString($entry['media_type'] ?? null);
        }

        $ordered = array_keys($paths);
        $sorted = $ordered;
        sort($sorted, SORT_STRING);
        if ($ordered !== $sorted) {
            throw self::invalidPackage();
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $expected
     */
    private static function assertExactKeys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        $expectedKeys = $expected;
        sort($expectedKeys, SORT_STRING);

        if ($actual !== $expectedKeys) {
            throw self::invalidPackage();
        }
    }

    private static function assertStableVersion(mixed $value): void
    {
        $version = self::requireNonEmptyString($value);
        if (strtolower($version) === 'dev' || preg_match('/\A[0-9]+(?:\.[0-9]+){1,3}(?:[-+][0-9A-Za-z.-]+)?\z/', $version) !== 1) {
            throw self::invalidPackage();
        }
    }

    private static function requireNonEmptyString(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw self::invalidPackage();
        }

        return $value;
    }

    private static function assertSha256(mixed $value): void
    {
        if (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw self::invalidPackage();
        }
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
