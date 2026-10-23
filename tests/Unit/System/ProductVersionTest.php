<?php

namespace Tests\Unit\System;

use App\Support\Lifecycle\ProductVersion;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ProductVersionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/seo-os-product-version-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            foreach (glob($this->directory.'/*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            rmdir($this->directory);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_returns_a_trimmed_stable_version_from_the_version_file(): void
    {
        $path = $this->directory.'/VERSION';
        file_put_contents($path, "  0.1.0-alpha.1\n");

        $version = new ProductVersion($path);

        $this->assertSame('0.1.0-alpha.1', $version->current());
        $this->assertTrue($version->isDistributable());
        $this->assertSame('0.1.0-alpha.1', $version->requireDistributable());
    }

    #[Test]
    public function it_falls_back_to_dev_when_the_version_file_is_missing_or_blank(): void
    {
        $missing = new ProductVersion($this->directory.'/missing-VERSION');
        $this->assertSame('dev', $missing->current());
        $this->assertFalse($missing->isDistributable());

        $blankPath = $this->directory.'/VERSION';
        file_put_contents($blankPath, " \n\t");

        $blank = new ProductVersion($blankPath);
        $this->assertSame('dev', $blank->current());
        $this->assertFalse($blank->isDistributable());
    }

    #[Test]
    public function distributable_identity_rejects_dev(): void
    {
        $path = $this->directory.'/VERSION';
        file_put_contents($path, "dev\n");

        $version = new ProductVersion($path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stable product version is required.');

        $version->requireDistributable();
    }
}
