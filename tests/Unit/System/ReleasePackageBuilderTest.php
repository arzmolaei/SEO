<?php

namespace Tests\Unit\System;

use App\Support\ReleasePackageBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReleasePackageBuilderTest extends TestCase
{
    private string $source;

    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $this->source = sys_get_temp_dir()."/seo-os-release-source-{$suffix}";
        $this->destination = sys_get_temp_dir()."/seo-os-release-output-{$suffix}";

        $this->writeFixture('VERSION', "0.1.0-alpha.1\n");
        $this->writeFixture('artisan', '#!/usr/bin/env php');
        $this->writeFixture('composer.json', '{}');
        $this->writeFixture('composer.lock', '{}');
        $this->writeFixture('.env.example', 'APP_ENV=production');
        $this->writeFixture('app/Foo.php', '<?php');
        $this->writeFixture('bootstrap/app.php', '<?php');
        $this->writeFixture('bootstrap/runtime-release.php', '<?php');
        $this->writeFixture('config/app.php', '<?php return [];');
        $this->writeFixture('database/migrations/0001_example.php', '<?php');
        $this->writeFixture('public/index.php', '<?php');
        $this->writeFixture('public/build/manifest.json', '{}');
        $this->writeFixture('public/fonts/open/vazirmatn/Vazirmatn[wght].woff2', 'font');
        $this->writeFixture('resources/views/app.blade.php', '<html></html>');
        $this->writeFixture('routes/web.php', '<?php');
        $this->writeFixture('vendor/autoload.php', '<?php');

        $this->writeFixture('.env', 'APP_KEY=secret');
        $this->writeFixture('auth.json', '{"secret":true}');
        $this->writeFixture('.github/workflows/ci.yml', 'name: CI');
        $this->writeFixture('node_modules/example/index.js', 'dev');
        $this->writeFixture('tests/ExampleTest.php', '<?php');
        $this->writeFixture('resources/js/app.tsx', 'dev');
        $this->writeFixture('resources/css/app.css', 'dev');
        $this->writeFixture('package.json', '{}');
        $this->writeFixture('package-lock.json', '{}');
        $this->writeFixture('storage/logs/laravel.log', 'sensitive runtime log');
        $this->writeFixture('storage/app/private/upload.txt', 'private upload');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->source);
        $this->removeDirectory($this->destination);

        parent::tearDown();
    }

    #[Test]
    public function it_builds_a_runtime_release_tree_without_development_or_runtime_state(): void
    {
        (new ReleasePackageBuilder)->build($this->source, $this->destination);

        $this->assertFileExists($this->destination.'/VERSION');
        $this->assertFileExists($this->destination.'/artisan');
        $this->assertFileExists($this->destination.'/bootstrap/runtime-release.php');
        $this->assertFileExists($this->destination.'/vendor/autoload.php');
        $this->assertFileExists($this->destination.'/public/build/manifest.json');
        $this->assertFileExists($this->destination.'/public/fonts/open/vazirmatn/Vazirmatn[wght].woff2');
        $this->assertFileExists($this->destination.'/resources/views/app.blade.php');
        $this->assertFileExists($this->destination.'/database/migrations/0001_example.php');

        $this->assertFileDoesNotExist($this->destination.'/.env');
        $this->assertFileDoesNotExist($this->destination.'/auth.json');
        $this->assertDirectoryDoesNotExist($this->destination.'/.github');
        $this->assertDirectoryDoesNotExist($this->destination.'/node_modules');
        $this->assertDirectoryDoesNotExist($this->destination.'/tests');
        $this->assertDirectoryDoesNotExist($this->destination.'/resources/js');
        $this->assertDirectoryDoesNotExist($this->destination.'/resources/css');
        $this->assertFileDoesNotExist($this->destination.'/package.json');
        $this->assertFileDoesNotExist($this->destination.'/package-lock.json');
        $this->assertFileDoesNotExist($this->destination.'/storage/logs/laravel.log');
        $this->assertFileDoesNotExist($this->destination.'/storage/app/private/upload.txt');

        $this->assertDirectoryExists($this->destination.'/storage/app/private');
        $this->assertDirectoryExists($this->destination.'/storage/framework/cache/data');
        $this->assertDirectoryExists($this->destination.'/storage/framework/sessions');
        $this->assertDirectoryExists($this->destination.'/storage/framework/views');
        $this->assertDirectoryExists($this->destination.'/storage/logs');
    }

    private function writeFixture(string $path, string $contents): void
    {
        $fullPath = $this->source.'/'.$path;
        $directory = dirname($fullPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($fullPath, $contents);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
