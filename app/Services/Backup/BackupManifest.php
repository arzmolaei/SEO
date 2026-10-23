<?php

namespace App\Services\Backup;

final class BackupManifest
{
    public const SCHEMA = 'seo-backup/1';

    /** @var list<string> */
    private const REQUIRED_FIELDS = [
        'schema',
        'created_at_utc',
        'product_version',
        'installer_schema_version',
        'source_installation_id',
        'source_owner_user_id',
        'source_database_driver',
        'source_database_server_version',
        'application_schema_fingerprint',
        'tables',
        'entries',
        'excluded_runtime_tables',
        'content_digest_sha256',
    ];

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        self::assertExactKeys($data, self::REQUIRED_FIELDS);

        if (($data['schema'] ?? null) !== self::SCHEMA) {
            throw self::invalidBundle();
        }

        self::requireNonEmptyString($data['created_at_utc'] ?? null);
        $productVersion = self::requireNonEmptyString($data['product_version'] ?? null);
        if (strtolower($productVersion) === 'dev') {
            throw self::invalidBundle();
        }

        if (! is_int($data['installer_schema_version'] ?? null) || $data['installer_schema_version'] < 1) {
            throw self::invalidBundle();
        }

        $installationId = self::requireNonEmptyString($data['source_installation_id'] ?? null);
        if (preg_match('/\A[a-f0-9]{32}\z/', $installationId) !== 1) {
            throw self::invalidBundle();
        }

        if (! is_int($data['source_owner_user_id'] ?? null) || $data['source_owner_user_id'] < 1) {
            throw self::invalidBundle();
        }

        self::requireNonEmptyString($data['source_database_driver'] ?? null);
        self::requireNonEmptyString($data['source_database_server_version'] ?? null);
        self::assertSha256($data['application_schema_fingerprint'] ?? null);
        self::assertSha256($data['content_digest_sha256'] ?? null);
        self::validateTables($data['tables'] ?? null);
        self::validateEntries($data['entries'] ?? null);

        $excluded = $data['excluded_runtime_tables'] ?? null;
        if (! is_array($excluded) || ! array_is_list($excluded)) {
            throw self::invalidBundle();
        }

        if ($excluded !== (new ApplicationTableCatalog)->excludedTables()) {
            throw self::invalidBundle();
        }

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

    public function assertCompatible(string $productVersion, string $schemaFingerprint): void
    {
        $productVersion = trim($productVersion);

        if (
            $productVersion === ''
            || strtolower($productVersion) === 'dev'
            || $productVersion !== (string) $this->data['product_version']
            || $schemaFingerprint !== (string) $this->data['application_schema_fingerprint']
        ) {
            throw new BackupException(
                'restore_incompatible_schema',
                'فایل پشتیبان با نسخه یا ساختار این نصب سازگار نیست.',
            );
        }
    }

    private static function validateTables(mixed $tables): void
    {
        if (! is_array($tables) || ! array_is_list($tables)) {
            throw self::invalidBundle();
        }

        $expectedNames = (new ApplicationTableCatalog)->tableNames();
        $names = [];

        foreach ($tables as $index => $table) {
            if (! is_array($table)) {
                throw self::invalidBundle();
            }

            self::assertAllowedKeys($table, ['name', 'restore_order', 'cursor', 'columns']);
            self::assertRequiredKeys($table, ['name', 'restore_order', 'cursor', 'columns']);

            $name = self::requireNonEmptyString($table['name'] ?? null);
            if (in_array($name, $names, true)) {
                throw self::invalidBundle();
            }
            $names[] = $name;

            if (($table['restore_order'] ?? null) !== $index + 1) {
                throw self::invalidBundle();
            }

            $cursor = $table['cursor'] ?? null;
            if (! is_array($cursor) || ! array_is_list($cursor) || $cursor === []) {
                throw self::invalidBundle();
            }
            foreach ($cursor as $column) {
                self::requireNonEmptyString($column);
            }

            $columns = $table['columns'] ?? null;
            if (! is_array($columns) || ! array_is_list($columns) || $columns === []) {
                throw self::invalidBundle();
            }

            $columnNames = [];
            foreach ($columns as $column) {
                if (! is_array($column)) {
                    throw self::invalidBundle();
                }

                self::assertAllowedKeys($column, ['name', 'type', 'type_name', 'family', 'nullable', 'auto_increment']);
                self::assertRequiredKeys($column, ['name', 'type', 'family', 'nullable']);
                $columnName = self::requireNonEmptyString($column['name'] ?? null);
                if (in_array($columnName, $columnNames, true)) {
                    throw self::invalidBundle();
                }
                $columnNames[] = $columnName;
                self::requireNonEmptyString($column['type'] ?? null);
                self::requireNonEmptyString($column['family'] ?? null);
                if (! is_bool($column['nullable'] ?? null)) {
                    throw self::invalidBundle();
                }
                if (array_key_exists('type_name', $column)) {
                    self::requireNonEmptyString($column['type_name']);
                }
                if (array_key_exists('auto_increment', $column) && ! is_bool($column['auto_increment'])) {
                    throw self::invalidBundle();
                }
            }
        }

        if ($names !== $expectedNames) {
            throw self::invalidBundle();
        }
    }

    private static function validateEntries(mixed $entries): void
    {
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw self::invalidBundle();
        }

        $paths = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw self::invalidBundle();
            }

            self::assertAllowedKeys($entry, ['path', 'size', 'sha256', 'media_type', 'record_count']);
            self::assertRequiredKeys($entry, ['path', 'size', 'sha256', 'media_type']);

            $path = self::requireNonEmptyString($entry['path'] ?? null);
            if (in_array($path, $paths, true)) {
                throw self::invalidBundle();
            }
            $paths[] = $path;

            if (! is_int($entry['size'] ?? null) || $entry['size'] < 0) {
                throw self::invalidBundle();
            }
            self::assertSha256($entry['sha256'] ?? null);
            self::requireNonEmptyString($entry['media_type'] ?? null);

            if (array_key_exists('record_count', $entry) && (! is_int($entry['record_count']) || $entry['record_count'] < 0)) {
                throw self::invalidBundle();
            }
        }
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private static function assertExactKeys(array $value, array $expected): void
    {
        $actualKeys = array_keys($value);
        sort($actualKeys, SORT_STRING);
        $expectedKeys = $expected;
        sort($expectedKeys, SORT_STRING);

        if ($actualKeys !== $expectedKeys) {
            throw self::invalidBundle();
        }
    }

    /** @param array<string, mixed> $value @param list<string> $allowed */
    private static function assertAllowedKeys(array $value, array $allowed): void
    {
        foreach (array_keys($value) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw self::invalidBundle();
            }
        }
    }

    /** @param array<string, mixed> $value @param list<string> $required */
    private static function assertRequiredKeys(array $value, array $required): void
    {
        foreach ($required as $key) {
            if (! array_key_exists($key, $value)) {
                throw self::invalidBundle();
            }
        }
    }

    private static function requireNonEmptyString(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw self::invalidBundle();
        }

        return $value;
    }

    private static function assertSha256(mixed $value): void
    {
        if (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw self::invalidBundle();
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

    private static function invalidBundle(): BackupException
    {
        return new BackupException(
            'restore_invalid_bundle',
            'فایل پشتیبان معتبر نیست.',
        );
    }
}
