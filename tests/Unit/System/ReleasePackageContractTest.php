<?php

namespace Tests\Unit\System;

use App\Support\ReleasePackageContract;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ReleasePackageContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/seo-os-release-contract-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }

            rmdir($this->root);
        }

        parent::tearDown();
    }

    public function test_complete_production_package_satisfies_contract(): void
    {
        $this->seedRequiredRuntimeFiles();

        $report = (new ReleasePackageContract)->inspect($this->root);

        $this->assertTrue($report['valid']);
        $this->assertSame([], $report['missing']);
        $this->assertSame([], $report['forbidden']);
    }

    public function test_missing_runtime_artifacts_are_reported(): void
    {
        $this->seedRequiredRuntimeFiles([
            'vendor/autoload.php',
            'public/build/manifest.json',
        ]);

        $report = (new ReleasePackageContract)->inspect($this->root);

        $this->assertFalse($report['valid']);
        $this->assertContains('vendor/autoload.php', $report['missing']);
        $this->assertContains('public/build/manifest.json', $report['missing']);
    }

    public function test_development_and_secret_artifacts_are_rejected(): void
    {
        $this->seedRequiredRuntimeFiles();

        foreach ([
            '.env',
            'auth.json',
            'node_modules/package.json',
            'tests/Feature/ExampleTest.php',
            'resources/js/app.tsx',
            'resources/css/app.css',
            '.github/workflows/ci.yml',
            'package.json',
            'package-lock.json',
            'vite.config.ts',
            'tsconfig.json',
            'phpunit.xml',
            'phpstan.neon',
            'storage/oauth-private.key',
        ] as $path) {
            $this->writeFile($path);
        }

        $report = (new ReleasePackageContract)->inspect($this->root);

        $this->assertFalse($report['valid']);
        $this->assertContains('.env', $report['forbidden']);
        $this->assertContains('node_modules', $report['forbidden']);
        $this->assertContains('tests', $report['forbidden']);
        $this->assertContains('resources/js', $report['forbidden']);
        $this->assertContains('storage/**/*.key', $report['forbidden']);
    }

    public function test_nested_private_key_artifacts_are_rejected(): void
    {
        $this->seedRequiredRuntimeFiles();
        $this->writeFile('storage/app/private/oauth-private.key');

        $report = (new ReleasePackageContract)->inspect($this->root);

        $this->assertFalse($report['valid']);
        $this->assertContains('storage/**/*.key', $report['forbidden']);
    }

    /** @param list<string> $except */
    private function seedRequiredRuntimeFiles(array $except = []): void
    {
        foreach ([
            'VERSION',
            'artisan',
            'bootstrap/app.php',
            'vendor/autoload.php',
            'public/build/manifest.json',
            'public/fonts/open/vazirmatn/Vazirmatn[wght].woff2',
            'resources/views/app.blade.php',
            '.env.example',
        ] as $path) {
            if (! in_array($path, $except, true)) {
                $this->writeFile($path);
            }
        }
    }

    private function writeFile(string $path): void
    {
        $absolutePath = $this->root.'/'.$path;
        $directory = dirname($absolutePath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($absolutePath, 'fixture');
    }
}
