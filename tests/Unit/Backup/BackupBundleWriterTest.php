<?php

namespace Tests\Unit\Backup;

use App\Services\Backup\ApplicationTableCatalog;
use App\Services\Backup\BackupManifest;
use App\Support\Backup\BackupBundleWriter;
use Tests\TestCase;

class BackupBundleWriterTest extends TestCase
{
    public function test_writer_emits_exact_deterministic_binary_framing_and_manifest_metadata(): void
    {
        [$firstManifest, $firstBytes] = $this->writeBundle();
        [$secondManifest, $secondBytes] = $this->writeBundle();

        $this->assertSame($firstManifest->canonicalJson(), $secondManifest->canonicalJson());
        $this->assertSame($firstBytes, $secondBytes);
        $this->assertStringStartsWith("SEOBACKUP\n", $firstBytes);

        $manifestJson = $firstManifest->canonicalJson();
        $expected = "SEOBACKUP\n".pack('N', strlen($manifestJson)).$manifestJson;

        foreach ([
            ['database/users.ndjson', "{\"id\":\"1\"}\n"],
            ['settings/runtime.json', '{"APP_KEY":"base64:test"}'],
        ] as [$path, $payload]) {
            $expected .= pack('N', strlen($path));
            $expected .= $path;
            $expected .= pack('N2', 0, strlen($payload));
            $expected .= $payload;
        }

        $this->assertSame($expected, $firstBytes);

        $entries = $firstManifest->toArray()['entries'];
        $this->assertSame('database/users.ndjson', $entries[0]['path']);
        $this->assertSame(strlen("{\"id\":\"1\"}\n"), $entries[0]['size']);
        $this->assertSame(hash('sha256', "{\"id\":\"1\"}\n"), $entries[0]['sha256']);
        $this->assertSame(1, $entries[0]['record_count']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $firstManifest->toArray()['content_digest_sha256']);
    }

    /** @return array{BackupManifest, string} */
    private function writeBundle(): array
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
            $bytes = stream_get_contents($output);
            $this->assertIsString($bytes);

            return [$manifest, $bytes];
        } finally {
            fclose($users);
            fclose($settings);
            fclose($output);
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
