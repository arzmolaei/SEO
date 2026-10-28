<?php

namespace Tests\Unit\System;

use App\Support\ReleasePackageBuilder;
use App\Support\ReleasePackageContract;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class LifecycleReleaseIdentityTest extends TestCase
{
    private string $source;

    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $this->source = sys_get_temp_dir()."/seo-os-versioned-release-source-{$suffix}";
        $this->destination = sys_get_temp_dir()."/seo-os-versioned-release-output-{$suffix}";

        $this->seedReleaseTree($this->source);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->source);
        $this->removeDirectory($this->destination);

        parent::tearDown();
    }

    #[Test]
    public function it_copies_a_stable_version_into_the_runtime_release_tree(): void
    {
        file_put_contents($this->source.'/VERSION', "0.1.0-alpha.1\n");

        (new ReleasePackageBuilder)->build($this->source, $this->destination);

        $this->assertFileExists($this->destination.'/VERSION');
        $this->assertSame("0.1.0-alpha.1\n", file_get_contents($this->destination.'/VERSION'));
        $this->assertTrue((new ReleasePackageContract)->inspect($this->destination)['valid']);
    }

    #[Test]
    public function release_contract_rejects_a_missing_version_identity(): void
    {
        $report = (new ReleasePackageContract)->inspect($this->source);

        $this->assertFalse($report['valid']);
        $this->assertContains('VERSION', $report['missing']);
    }

    #[Test]
    public function release_contract_rejects_a_dev_version_identity(): void
    {
        file_put_contents($this->source.'/VERSION', "dev\n");

        $report = (new ReleasePackageContract)->inspect($this->source);

        $this->assertFalse($report['valid']);
        $this->assertContains('VERSION', $report['missing']);
    }

    private function seedReleaseTree(string $root): void
    {
        foreach ([
            'artisan' => '#!/usr/bin/env php',
            'composer.json' => '{}',
            'composer.lock' => '{}',
            '.env.example' => 'APP_ENV=production',
            'app/Foo.php' => '<?php',
            'bootstrap/app.php' => '<?php',
            'bootstrap/runtime-release.php' => '<?php',
            'config/app.php' => '<?php return [];',
            'database/migrations/0001_example.php' => '<?php',
            'lang/fa/validation.php' => '<?php return [];',
            'public/index.php' => '<?php',
            'public/build/manifest.json' => '{}',
            'public/fonts/open/vazirmatn/Vazirmatn[wght].woff2' => 'font',
            'resources/views/app.blade.php' => '<html></html>',
            'routes/web.php' => '<?php',
            'vendor/autoload.php' => '<?php',
        ] as $path => $contents) {
            $fullPath = $root.'/'.$path;
            $directory = dirname($fullPath);

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            file_put_contents($fullPath, $contents);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
