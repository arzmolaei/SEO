<?php

namespace Tests\Feature\Update;

use App\Services\Update\BuildSystemUpdatePackage;
use App\Services\Update\UpdateException;
use App\Support\Update\UpdatePackageFormat;
use App\Support\Update\UpdatePackageReader;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BuildSystemUpdatePackageTest extends TestCase
{
    private string $root;

    private string $release;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/seo-update-build-'.bin2hex(random_bytes(6));
        $this->release = $this->root.'/release';
        foreach ([
            'VERSION' => "0.1.0-alpha.2\n",
            'artisan' => '<?php',
            'composer.json' => '{}',
            'composer.lock' => '{}',
            '.env.example' => 'APP_ENV=production',
            'app/Release.php' => '<?php // کلیدواژه‌ فارسی',
            'bootstrap/app.php' => '<?php',
            'bootstrap/runtime-release.php' => '<?php',
            'vendor/autoload.php' => '<?php',
            'public/index.php' => '<?php // stable launcher',
            'public/build/manifest.json' => '{}',
            'public/build/assets/app-target.js' => 'target asset',
            'public/fonts/open/vazirmatn/Vazirmatn[wght].woff2' => 'font',
            'resources/views/app.blade.php' => '<html></html>',
            'database/migrations/0001_example.php' => '<?php // migration',
            'storage/logs/laravel.log' => 'private runtime log',
        ] as $path => $contents) {
            File::ensureDirectoryExists(dirname($this->release.'/'.$path));
            File::put($this->release.'/'.$path, $contents);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_builds_deterministic_verified_runtime_package_with_exact_compatibility(): void
    {
        $builder = $this->app->make(BuildSystemUpdatePackage::class);
        $first = $builder->build($this->release, $this->root.'/first.seoupdate', $this->compatibility());
        $second = $builder->build($this->release, $this->root.'/second.seoupdate', $this->compatibility());
        $this->assertSame(File::get($this->root.'/first.seoupdate'), File::get($this->root.'/second.seoupdate'));
        $this->assertSame($first->canonicalJson(), $second->canonicalJson());

        $stream = fopen($this->root.'/first.seoupdate', 'rb');
        $this->assertIsResource($stream);
        try {
            $manifest = $this->app->make(UpdatePackageReader::class)->read($stream)->toArray();
        } finally {
            fclose($stream);
        }
        $this->assertSame(['0.1.0-alpha.1'], $manifest['allowed_source_product_versions']);
        $this->assertSame('0.1.0-alpha.2', $manifest['target_product_version']);
        $this->assertSame(str_repeat('1', 64), $manifest['source_schema_fingerprint']);
        $this->assertSame(str_repeat('2', 64), $manifest['target_schema_fingerprint']);
        $this->assertSame('development', $manifest['channel']);
        $paths = array_column($manifest['entries'], 'path');
        $this->assertContains('vendor/autoload.php', $paths);
        $this->assertContains('public/build/assets/app-target.js', $paths);
        foreach (['.env', 'public/index.php', 'storage/logs/laravel.log', 'tests', 'resources/js'] as $excluded) {
            $this->assertNotContains($excluded, $paths);
        }
        $migration = [[
            'path' => 'database/migrations/0001_example.php',
            'size' => strlen('<?php // migration'),
            'sha256' => hash('sha256', '<?php // migration'),
            'media_type' => 'application/octet-stream',
        ]];
        $this->assertSame(hash('sha256', UpdatePackageFormat::canonicalMetadataJson($migration)), $manifest['migration_tree_sha256']);
        $this->assertFileDoesNotExist($this->root.'/first.seoupdate.tmp');
    }

    public function test_cli_builds_with_explicit_source_metadata_and_reproducible_timestamp(): void
    {
        $output = $this->root.'/cli.seoupdate';
        $process = new Process([
            PHP_BINARY, base_path('scripts/release/build-update-package.php'),
            $this->release, $output, '0.1.0-alpha.1', str_repeat('1', 64), str_repeat('2', 64), 'development',
        ], base_path(), ['SOURCE_DATE_EPOCH' => '0']);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $stream = fopen($output, 'rb');
        $this->assertIsResource($stream);
        try {
            $manifest = $this->app->make(UpdatePackageReader::class)->read($stream)->toArray();
            $this->assertSame('1970-01-01T00:00:00Z', $manifest['created_at_utc']);
            $this->assertSame(['0.1.0-alpha.1'], $manifest['allowed_source_product_versions']);
        } finally {
            fclose($stream);
        }
    }

    public function test_rejects_build_host_config_route_and_event_caches_before_publishing(): void
    {
        foreach (['config.php', 'routes-v7.php', 'events.php'] as $name) {
            File::ensureDirectoryExists($this->release.'/bootstrap/cache');
            $cache = $this->release.'/bootstrap/cache/'.$name;
            File::put($cache, "<?php return ['app_key' => 'build-host-secret', 'database' => 'build-host-db'];");
            $output = $this->root.'/cached-'.$name.'.seoupdate';
            try {
                $this->app->make(BuildSystemUpdatePackage::class)->build($this->release, $output, $this->compatibility());
                $this->fail('Build-host caches must never be distributable.');
            } catch (UpdateException) {
                $this->assertFileDoesNotExist($output);
                $this->assertFileDoesNotExist($output.'.tmp');
            } finally {
                File::delete($cache);
            }
        }
    }

    public function test_rejects_dirty_release_and_preserves_existing_output(): void
    {
        File::put($this->release.'/.env', 'APP_KEY=secret');
        File::put($this->root.'/existing.seoupdate', 'previous verified package');
        $this->expectException(UpdateException::class);
        try {
            $this->app->make(BuildSystemUpdatePackage::class)->build($this->release, $this->root.'/existing.seoupdate', $this->compatibility());
        } finally {
            $this->assertSame('previous verified package', File::get($this->root.'/existing.seoupdate'));
        }
    }

    public function test_rejects_symlinked_runtime_inputs_and_leaves_no_output(): void
    {
        symlink($this->release.'/VERSION', $this->release.'/vendor/linked.php');
        $this->expectException(UpdateException::class);
        try {
            $this->app->make(BuildSystemUpdatePackage::class)->build($this->release, $this->root.'/unsafe.seoupdate', $this->compatibility());
        } finally {
            $this->assertFileDoesNotExist($this->root.'/unsafe.seoupdate');
            $this->assertFileDoesNotExist($this->root.'/unsafe.seoupdate.tmp');
        }
    }

    public function test_rejects_downgrade_and_output_inside_source_without_mutation(): void
    {
        foreach ([
            [$this->root.'/downgrade.seoupdate', [...$this->compatibility(), 'source_product_version' => '0.1.0-alpha.3']],
            [$this->release.'/app/output.seoupdate', $this->compatibility()],
        ] as [$output, $metadata]) {
            try {
                $this->app->make(BuildSystemUpdatePackage::class)->build($this->release, $output, $metadata);
                $this->fail('Unsafe build must be rejected.');
            } catch (UpdateException) {
                $this->assertFileDoesNotExist($output);
                $this->assertFileDoesNotExist($output.'.tmp');
                $this->assertSame("0.1.0-alpha.2\n", File::get($this->release.'/VERSION'));
            }
        }
    }

    /** @return array<string, mixed> */
    private function compatibility(): array
    {
        return [
            'source_product_version' => '0.1.0-alpha.1',
            'source_schema_fingerprint' => str_repeat('1', 64),
            'target_schema_fingerprint' => str_repeat('2', 64),
            'channel' => 'development',
            'minimum_php_version' => '8.4.1',
            'required_php_extensions' => ['ctype', 'json', 'pdo'],
            'created_at_utc' => '2026-10-24T00:00:00Z',
        ];
    }
}
