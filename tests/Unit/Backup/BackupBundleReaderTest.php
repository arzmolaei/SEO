<?php

namespace Tests\Unit\Backup;

use App\Services\Backup\ApplicationTableCatalog;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupManifest;
use App\Support\Backup\BackupBundleReader;
use App\Support\Backup\BackupBundleWriter;
use Tests\TestCase;

class BackupBundleReaderTest extends TestCase
{
    public function test_reader_verifies_bundle_incrementally_and_exposes_entry_chunks(): void
    {
        [$stream, $expectedManifest] = $this->validBundle();
        $entries = [];

        try {
            $manifest = (new BackupBundleReader)->read(
                $stream,
                function (string $path, string $chunk) use (&$entries): void {
                    $entries[$path] = ($entries[$path] ?? '').$chunk;
                },
            );

            $this->assertSame($expectedManifest->canonicalJson(), $manifest->canonicalJson());
            $this->assertSame("{\"id\":\"1\"}\n", $entries['database/users.ndjson']);
            $this->assertSame('{"APP_KEY":"base64:test"}', $entries['settings/runtime.json']);
        } finally {
            fclose($stream);
        }
    }

    public function test_reader_rejects_payload_checksum_mismatch_and_trailing_bytes(): void
    {
        [$stream] = $this->validBundle();
        $bytes = stream_get_contents($stream);
        fclose($stream);
        $this->assertIsString($bytes);

        $payload = "{\"id\":\"1\"}\n";
        $position = strrpos($bytes, $payload);
        $this->assertNotFalse($position);
        $tampered = substr_replace($bytes, "{\"id\":\"9\"}\n", $position, strlen($payload));

        $this->assertReaderFailure($tampered, 'restore_checksum_mismatch');
        $this->assertReaderFailure($bytes."unexpected", 'restore_invalid_bundle');
    }

    public function test_reader_rejects_oversized_manifest_before_reading_attacker_declared_payload(): void
    {
        $stream = $this->stream("SEOBACKUP\n".pack('N', 4096));

        try {
            $reader = new BackupBundleReader([
                'max_manifest_bytes' => 64,
                'max_path_bytes' => 1024,
                'max_entries' => 100,
                'max_entry_bytes' => 1024,
                'max_bundle_bytes' => 4096,
                'chunk_bytes' => 16,
            ]);

            try {
                $reader->verify($stream);
                $this->fail('Oversized manifest length was accepted.');
            } catch (BackupException $exception) {
                $this->assertSame('restore_invalid_bundle', $exception->codeName());
            }
        } finally {
            fclose($stream);
        }
    }

    public function test_reader_rejects_traversal_and_control_byte_paths(): void
    {
        foreach (["../secret.txt", "storage/app/private/uploads/bad\0name.txt"] as $path) {
            $payload = 'x';
            $manifest = BackupManifest::fromArray([
                ...$this->manifestFields(),
                'entries' => [[
                    'path' => $path,
                    'size' => 1,
                    'sha256' => hash('sha256', $payload),
                    'media_type' => 'application/octet-stream',
                ]],
                'content_digest_sha256' => str_repeat('b', 64),
            ]);
            $json = $manifest->canonicalJson();
            $bytes = "SEOBACKUP\n".pack('N', strlen($json)).$json
                .pack('N', strlen($path)).$path.pack('N2', 0, 1).$payload;

            $this->assertReaderFailure($bytes, 'restore_path_rejected');
        }
    }

    private function assertReaderFailure(string $bytes, string $expectedCode): void
    {
        $stream = $this->stream($bytes);

        try {
            try {
                (new BackupBundleReader)->verify($stream);
                $this->fail("Reader accepted invalid bundle expected to fail with {$expectedCode}.");
            } catch (BackupException $exception) {
                $this->assertSame($expectedCode, $exception->codeName());
            }
        } finally {
            fclose($stream);
        }
    }

    /** @return array{resource, BackupManifest} */
    private function validBundle(): array
    {
        $output = fopen('php://temp', 'w+b');
        $users = $this->stream("{\"id\":\"1\"}\n");
        $settings = $this->stream('{"APP_KEY":"base64:test"}');

        try {
            $manifest = (new BackupBundleWriter)->write($output, $this->manifestFields(), [
                [
                    'path' => 'database/users.ndjson',
                    'stream' => $users,
                    'media_type' => 'application/x-ndjson',
                    'record_count' => 1,
                ],
                [
                    'path' => 'settings/runtime.json',
                    'stream' => $settings,
                    'media_type' => 'application/json',
                ],
            ]);
            rewind($output);

            return [$output, $manifest];
        } finally {
            fclose($users);
            fclose($settings);
        }
    }

    /** @return resource */
    private function stream(string $bytes)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    /** @return array<string, mixed> */
    private function manifestFields(): array
    {
        return [
            'schema' => BackupManifest::SCHEMA,
            'created_at_utc' => '2026-10-23T08:00:00Z',
            'product_version' => '0.1.0-alpha.1',
            'installer_schema_version' => 2,
            'source_installation_id' => '0123456789abcdef0123456789abcdef',
            'source_owner_user_id' => 1,
            'source_database_driver' => 'mysql',
            'source_database_server_version' => '10.11.0-MariaDB',
            'application_schema_fingerprint' => str_repeat('a', 64),
            'tables' => array_map(
                static fn (string $name, int $index): array => [
                    'name' => $name,
                    'restore_order' => $index + 1,
                    'cursor' => ['id'],
                    'columns' => [
                        ['name' => 'id', 'type' => 'bigint', 'family' => 'integer', 'nullable' => false],
                    ],
                ],
                ApplicationTableCatalog::TABLES,
                array_keys(ApplicationTableCatalog::TABLES),
            ),
            'excluded_runtime_tables' => (new ApplicationTableCatalog)->excludedTables(),
        ];
    }
}
