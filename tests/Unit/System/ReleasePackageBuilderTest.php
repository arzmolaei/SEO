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

        $this->put('artisan', '#!/usr/bin/env php');
        $this->put('composer.json', '{}');
        $this->put('composer.lock', '{}');
        $this->put('.env.example', 'APP_ENV=production');
        $this->put('app/Foo.php', '<?php');
        $this->put('bootstrap/app.php', '<?php');
        $this->put('config/app.php', '<?php return [];');
        $this->put('database/migrations/0001_example.php', '<?php');
        $this->put('public/index.php', '<?php');
        $this->put('public/build/manifest.json', '{}');
        $this->put('public/fonts/open/vazirmatn/Vazirmatn[wght].woff2', 'font');
        $this->put('resources/views/app.blade.php', '<html></html>');
        $this->put('routes/web.php', '<?php');
        $this->put('vendor/autoload.php', '<?php');

        $this->put('.env', 'APP_KEY=secret');
        $this->put('auth.json', '{"secret":true}');
        $this->put('.github/workflows/ci.yml', 'name: CI');
        $this->put('node_modules/example/index.js', 'dev');
        $this->put('tests/ExampleTest.php', '<?php');
        $this->put('resources/js/app.tsx', 'dev');
        $this->put('resources/css/app.css', 'dev');
        $this->put('package.json', '{}');
        $this->put('package-lock.json', '{}');
        $this->put('storage/logs/laravel.log', 'sensitive runtime log');
        $this->put('storage/app/private/upload.txt', 'private upload');
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

        $this->assertFileExists($this->destination.'/artisan');
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

    private function put(string $path, string $contents): void
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
