<?php

namespace Tests\Unit\Update;

use App\Services\Update\UpdateException;
use App\Support\Update\UpdatePackageFormat;
use App\Support\Update\UpdatePackageReader;
use App\Support\Update\UpdatePackageWriter;
use Tests\TestCase;

class UpdatePackageReaderTest extends TestCase
{
    public function test_reader_verifies_and_streams_each_payload_chunk(): void
    {
        $package = $this->validPackage();
        $seen = [];

        $manifest = (new UpdatePackageReader($this->limits()))->read(
            $package,
            function (string $path, string $chunk) use (&$seen): void {
                $seen[$path] = ($seen[$path] ?? '').$chunk;
            },
        );

        $this->assertSame('0.1.0-alpha.2', $manifest->toArray()['target_product_version']);
        $this->assertSame("0.1.0-alpha.2\n", $seen['VERSION']);
        $this->assertSame("<?php return 2;\n", $seen['app/Release.php']);

        fclose($package);
    }

    public function test_reader_rejects_bad_magic_trailing_bytes_and_payload_checksum_mismatch(): void
    {
        $valid = $this->streamContents($this->validPackage());

        foreach ([
            'magic' => 'X'.substr($valid, 1),
            'trailing' => $valid.'x',
            'checksum' => substr_replace($valid, 'X', -1, 1),
        ] as $label => $bytes) {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $bytes);
            rewind($stream);

            try {
                (new UpdatePackageReader($this->limits()))->read($stream);
                $this->fail("{$label} corruption should be rejected.");
            } catch (UpdateException $exception) {
                $this->assertSame('update_invalid_package', $exception->codeName());
            } finally {
                fclose($stream);
            }
        }
    }

    public function test_reader_rejects_manifest_path_entry_and_total_length_attacks_before_large_allocation(): void
    {
        $cases = [
            'manifest' => UpdatePackageFormat::MAGIC.pack('N', 65536),
            'path' => UpdatePackageFormat::MAGIC.pack('N', 2).'{}'.pack('N', 4096),
        ];

        foreach ($cases as $label => $bytes) {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $bytes);
            rewind($stream);

            try {
                (new UpdatePackageReader([
                    ...$this->limits(),
                    'max_manifest_bytes' => 1024,
                    'max_path_bytes' => 64,
                ]))->read($stream);
                $this->fail("{$label} oversized length should be rejected.");
            } catch (UpdateException $exception) {
                $this->assertSame('update_invalid_package', $exception->codeName());
            } finally {
                fclose($stream);
            }
        }

        $package = $this->validPackage();
        try {
            (new UpdatePackageReader([
                ...$this->limits(),
                'max_package_bytes' => 32,
            ]))->read($package);
            $this->fail('Oversized package should be rejected.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_invalid_package', $exception->codeName());
        } finally {
            fclose($package);
        }
    }

    public function test_runtime_release_path_contract_is_allowlist_first(): void
    {
        foreach ([
            'VERSION',
            'artisan',
            'composer.json',
            'composer.lock',
            '.env.example',
            'app/Foo.php',
            'bootstrap/app.php',
            'bootstrap/runtime-release.php',
            'config/app.php',
            'database/migrations/2026_10_23_example.php',
            'resources/views/app.blade.php',
            'routes/web.php',
            'vendor/autoload.php',
            'public/build/manifest.json',
            'public/build/assets/app-abc123.js',
            'public/fonts/open/vazirmatn/font.woff2',
        ] as $path) {
            UpdatePackageFormat::assertPackagePath($path);
            $this->addToAssertionCount(1);
        }

        foreach ([
            '',
            '/VERSION',
            '../VERSION',
            'app/../VERSION',
            'app\\Foo.php',
            "app/Foo\n.php",
            '.env',
            'storage/app/private/data.bin',
            'tests/Feature/Test.php',
            'resources/js/app.tsx',
            '.github/workflows/ci.yml',
            'package.json',
            'vite.config.ts',
            'public/index.php',
            'public/robots.txt',
            'random.txt',
        ] as $path) {
            try {
                UpdatePackageFormat::assertPackagePath($path);
                $this->fail("Unsafe package path was accepted: {$path}");
            } catch (UpdateException $exception) {
                $this->assertSame('update_invalid_package', $exception->codeName());
            }
        }
    }

    /** @return resource */
    private function validPackage()
    {
        $output = fopen('php://temp', 'w+b');
        $version = fopen('php://temp', 'w+b');
        $release = fopen('php://temp', 'w+b');
        fwrite($version, "0.1.0-alpha.2\n");
        fwrite($release, "<?php return 2;\n");

        (new UpdatePackageWriter($this->limits()))->write($output, $this->manifestFields(), [
            ['path' => 'VERSION', 'stream' => $version, 'media_type' => 'text/plain'],
            ['path' => 'app/Release.php', 'stream' => $release, 'media_type' => 'application/x-httpd-php'],
        ]);

        fclose($version);
        fclose($release);
        rewind($output);

        return $output;
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
            'launcher_schema' => 'seo-launcher/2',
            'minimum_php_version' => '8.3.0',
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
            'chunk_bytes' => 5,
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
