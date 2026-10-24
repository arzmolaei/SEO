<?php

namespace Tests\Unit\Update;

use App\Services\Update\UpdateException;
use App\Support\Update\UpdatePackageFormat;
use App\Support\Update\UpdatePackageWriter;
use Tests\TestCase;

class UpdatePackageWriterTest extends TestCase
{
    public function test_writer_emits_deterministic_bounded_package_bytes(): void
    {
        $first = fopen('php://temp', 'w+b');
        $second = fopen('php://temp', 'w+b');

        $firstEntries = $this->entries();
        $secondEntries = $this->entries();

        $writer = new UpdatePackageWriter($this->limits());
        $firstManifest = $writer->write($first, $this->manifestFields(), $firstEntries);
        $secondManifest = $writer->write($second, $this->manifestFields(), $secondEntries);

        $this->assertSame($firstManifest->canonicalJson(), $secondManifest->canonicalJson());
        $this->assertSame($this->streamContents($first), $this->streamContents($second));
        $this->assertStringStartsWith(UpdatePackageFormat::MAGIC, $this->streamContents($first));
        $this->assertSame(['VERSION', 'app/Release.php'], array_column($firstManifest->toArray()['entries'], 'path'));

        $this->closeEntries($firstEntries);
        $this->closeEntries($secondEntries);
        fclose($first);
        fclose($second);
    }

    public function test_lazy_inputs_open_one_file_at_a_time_and_close_owned_streams(): void
    {
        $output = fopen('php://temp', 'w+b');
        $previous = null;
        $opens = 0;
        $entries = [];
        foreach (['VERSION', 'app/Release.php'] as $path) {
            $entries[] = [
                'path' => $path,
                'media_type' => 'text/plain',
                'stream' => function () use (&$previous, &$opens) {
                    $this->assertFalse(is_resource($previous));
                    $previous = fopen('php://temp', 'w+b');
                    $this->assertIsResource($previous);
                    fwrite($previous, 'payload');
                    $opens++;

                    return $previous;
                },
            ];
        }
        try {
            (new UpdatePackageWriter($this->limits()))->write($output, $this->manifestFields(), $entries);
            $this->assertSame(6, $opens);
            $this->assertFalse(is_resource($previous));
        } finally {
            fclose($output);
        }
    }

    public function test_writer_preserves_utf8_payload_and_rejects_duplicate_or_unsafe_paths(): void
    {
        $output = fopen('php://temp', 'w+b');
        $persian = fopen('php://temp', 'w+b');
        fwrite($persian, "کلیدواژه‌ فارسی\n");

        $writer = new UpdatePackageWriter($this->limits());
        $manifest = $writer->write($output, $this->manifestFields(), [[
            'path' => 'resources/views/fa.blade.php',
            'stream' => $persian,
            'media_type' => 'text/plain; charset=utf-8',
        ]]);

        $this->assertSame(hash('sha256', "کلیدواژه‌ فارسی\n"), $manifest->toArray()['entries'][0]['sha256']);

        fclose($persian);
        fclose($output);

        foreach ([
            ['../VERSION', '../VERSION'],
            ['backslash', 'app\\Release.php'],
            ['env', '.env'],
            ['storage', 'storage/app/private/secret.txt'],
            ['tests', 'tests/Feature/SecretTest.php'],
            ['frontend-source', 'resources/js/app.tsx'],
        ] as [$label, $path]) {
            $output = fopen('php://temp', 'w+b');
            $payload = fopen('php://temp', 'w+b');
            fwrite($payload, 'x');

            try {
                $writer->write($output, $this->manifestFields(), [[
                    'path' => $path,
                    'stream' => $payload,
                    'media_type' => 'application/octet-stream',
                ]]);
                $this->fail("{$label} path should be rejected.");
            } catch (UpdateException $exception) {
                $this->assertSame('update_invalid_package', $exception->codeName());
            } finally {
                fclose($payload);
                fclose($output);
            }
        }

        $output = fopen('php://temp', 'w+b');
        $a = fopen('php://temp', 'w+b');
        $b = fopen('php://temp', 'w+b');
        fwrite($a, 'a');
        fwrite($b, 'b');

        try {
            $writer->write($output, $this->manifestFields(), [
                ['path' => 'VERSION', 'stream' => $a, 'media_type' => 'text/plain'],
                ['path' => 'VERSION', 'stream' => $b, 'media_type' => 'text/plain'],
            ]);
            $this->fail('Duplicate package paths should be rejected.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_invalid_package', $exception->codeName());
        } finally {
            fclose($a);
            fclose($b);
            fclose($output);
        }
    }

    public function test_writer_rejects_entry_and_package_limits(): void
    {
        $output = fopen('php://temp', 'w+b');
        $payload = fopen('php://temp', 'w+b');
        fwrite($payload, '12345');

        $writer = new UpdatePackageWriter([
            ...$this->limits(),
            'max_entry_bytes' => 4,
        ]);

        $this->expectException(UpdateException::class);

        try {
            $writer->write($output, $this->manifestFields(), [[
                'path' => 'VERSION',
                'stream' => $payload,
                'media_type' => 'text/plain',
            ]]);
        } finally {
            fclose($payload);
            fclose($output);
        }
    }

    /** @return list<array{path:string,stream:resource,media_type:string}> */
    private function entries(): array
    {
        $version = fopen('php://temp', 'w+b');
        fwrite($version, "0.1.0-alpha.2\n");
        $release = fopen('php://temp', 'w+b');
        fwrite($release, "<?php return 2;\n");

        return [
            ['path' => 'VERSION', 'stream' => $version, 'media_type' => 'text/plain'],
            ['path' => 'app/Release.php', 'stream' => $release, 'media_type' => 'application/x-httpd-php'],
        ];
    }

    /** @param list<array{path:string,stream:resource,media_type:string}> $entries */
    private function closeEntries(array $entries): void
    {
        foreach ($entries as $entry) {
            fclose($entry['stream']);
        }
    }

    /** @return array<string, mixed> */
    private function manifestFields(): array
    {
        return [
            'schema' => 'seo-update/1',
            'created_at_utc' => '2026-10-23T16:30:00Z',
            'target_product_version' => '0.1.0-alpha.2',
            'allowed_source_product_versions' => ['0.1.0-alpha.1'],
            'channel' => 'development',
            'launcher_schema' => 'seo-launcher/1',
            'minimum_php_version' => '8.4.1',
            'required_php_extensions' => ['ctype', 'json', 'pdo'],
            'source_schema_fingerprint' => str_repeat('1', 64),
            'target_schema_fingerprint' => str_repeat('2', 64),
            'migration_tree_sha256' => str_repeat('3', 64),
        ];
    }

    /** @return array<string, int> */
    private function limits(): array
    {
        return [
            'max_manifest_bytes' => 32 * 1024,
            'max_path_bytes' => 1024,
            'max_entries' => 32,
            'max_entry_bytes' => 1024 * 1024,
            'max_package_bytes' => 2 * 1024 * 1024,
            'chunk_bytes' => 7,
        ];
    }

    /** @param resource $stream */
    private function streamContents($stream): string
    {
        rewind($stream);
        $contents = stream_get_contents($stream);
        $this->assertIsString($contents);

        return $contents;
    }
}
