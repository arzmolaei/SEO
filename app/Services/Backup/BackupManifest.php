<?php

namespace App\Services\Backup;

use JsonException;

final class BackupManifest
{
    public const SCHEMA = 'seo-backup/1';

    public const MAGIC = "SEOBACKUP\n";

    /** @var list<string> */
    private const FIELDS = [
        'schema',
        'created_at_utc',
        'product_version',
        'installer_schema_version',
        'source_installation_id',
        'source_owner_user_id',
        'source_database_driver',
        'source_database_server_version',
        'application_schema_fingerprint',
        'durable_tables',
        'excluded_runtime_tables',
        'entries',
        'content_digest_sha256',
    ];

    /** @param array<string, mixed> $payload */
    private function __construct(private readonly array $payload) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        self::validateFields($payload);
        self::validatePayload($payload);

        return new self($payload);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }

    public function toCanonicalJson(): string
    {
        try {
            return json_encode(
                self::canonicalize($this->payload),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new BackupException('Unable to encode backup manifest.', previous: $exception);
        }
    }

    public function assertCompatible(string $productVersion, string $schemaFingerprint): void
    {
        if (! hash_equals($productVersion, (string) $this->payload['product_version'])) {
            throw new BackupException('Backup product version is incompatible.');
        }

        if (! hash_equals($schemaFingerprint, (string) $this->payload['application_schema_fingerprint'])) {
            throw new BackupException('Backup application schema is incompatible.');
        }
    }

    /** @param array<string, mixed> $payload */
    private static function validateFields(array $payload): void
    {
        foreach (array_keys($payload) as $field) {
            if (! in_array($field, self::FIELDS, true)) {
                throw new BackupException("Unknown backup manifest field: {$field}");
            }
        }

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new BackupException("Missing backup manifest field: {$field}");
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private static function validatePayload(array $payload): void
    {
        if ($payload['schema'] !== self::SCHEMA) {
            throw new BackupException('Backup schema is unsupported.');
        }

        foreach ([
            'created_at_utc',
            'product_version',
            'source_installation_id',
            'source_database_driver',
            'source_database_server_version',
        ] as $field) {
            if (! is_string($payload[$field]) || trim($payload[$field]) === '') {
                throw new BackupException("Backup manifest field is invalid: {$field}");
            }
        }

        if (! is_int($payload['installer_schema_version']) || $payload['installer_schema_version'] < 1) {
            throw new BackupException('Backup installer schema version is invalid.');
        }

        if (! is_int($payload['source_owner_user_id']) || $payload['source_owner_user_id'] < 1) {
            throw new BackupException('Backup source owner is invalid.');
        }

        self::assertSha256($payload['application_schema_fingerprint'], 'application_schema_fingerprint');
        self::assertSha256($payload['content_digest_sha256'], 'content_digest_sha256');

        if (! is_array($payload['durable_tables']) || ! array_is_list($payload['durable_tables'])) {
            throw new BackupException('Backup durable table catalog is invalid.');
        }

        $seenTables = [];
        foreach ($payload['durable_tables'] as $table) {
            if (! is_string($table) || $table === '') {
                throw new BackupException('Backup durable table name is invalid.');
            }

            if (isset($seenTables[$table])) {
                throw new BackupException("Duplicate durable table: {$table}");
            }

            $seenTables[$table] = true;
        }

        if ($payload['durable_tables'] !== ApplicationTableCatalog::TABLES) {
            throw new BackupException('Backup durable table catalog is incompatible.');
        }

        if (
            ! is_array($payload['excluded_runtime_tables'])
            || ! array_is_list($payload['excluded_runtime_tables'])
            || $payload['excluded_runtime_tables'] !== ApplicationTableCatalog::excludedTables()
        ) {
            throw new BackupException('Backup excluded table catalog is incompatible.');
        }

        if (! is_array($payload['entries']) || ! array_is_list($payload['entries'])) {
            throw new BackupException('Backup manifest entries are invalid.');
        }

        $seenPaths = [];
        foreach ($payload['entries'] as $entry) {
            self::validateEntry($entry, $seenPaths);
        }
    }

    /**
     * @param mixed $entry
     * @param array<string, true> $seenPaths
     */
    private static function validateEntry(mixed $entry, array &$seenPaths): void
    {
        if (! is_array($entry) || array_is_list($entry)) {
            throw new BackupException('Backup manifest entry is invalid.');
        }

        $allowed = ['path', 'size', 'sha256'];

        foreach (array_keys($entry) as $field) {
            if (! in_array($field, $allowed, true)) {
                throw new BackupException("Unknown backup manifest entry field: {$field}");
            }
        }

        foreach ($allowed as $field) {
            if (! array_key_exists($field, $entry)) {
                throw new BackupException("Missing backup manifest entry field: {$field}");
            }
        }

        $path = $entry['path'];
        if (! is_string($path) || $path === '') {
            throw new BackupException('Backup manifest entry path is invalid.');
        }

        if (isset($seenPaths[$path])) {
            throw new BackupException("Duplicate backup manifest entry path: {$path}");
        }
        $seenPaths[$path] = true;

        if (! is_int($entry['size']) || $entry['size'] < 0) {
            throw new BackupException("Backup manifest entry size is invalid: {$path}");
        }

        self::assertSha256($entry['sha256'], "entry sha256 for {$path}");
    }

    private static function assertSha256(mixed $value, string $field): void
    {
        if (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw new BackupException("Backup SHA-256 value is invalid: {$field}");
        }
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
