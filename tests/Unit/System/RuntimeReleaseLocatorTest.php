<?php

namespace Tests\Unit\System;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class RuntimeReleaseLocatorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/seo-os-runtime-release-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0755, true);
        file_put_contents($this->root.'/VERSION', "0.1.0-alpha.1\n");
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root) && ! is_link($this->root)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $item) {
                $item->isDir() && ! $item->isLink()
                    ? rmdir($item->getPathname())
                    : unlink($item->getPathname());
            }

            rmdir($this->root);
        }

        parent::tearDown();
    }

    public function test_missing_pointer_resolves_the_stable_root_release(): void
    {
        $resolved = $this->locator()->resolve();

        $this->assertSame('root', $resolved['kind']);
        $this->assertSame($this->root, $resolved['release_root']);
        $this->assertNull($resolved['release_id']);
        $this->assertSame('0.1.0-alpha.1', $resolved['product_version']);
        $this->assertNull($resolved['package_digest_sha256']);
    }

    public function test_valid_pointer_resolves_only_the_matching_private_release_slot(): void
    {
        $digest = str_repeat('a', 64);
        $releaseId = '0.1.0-alpha.2-'.substr($digest, 0, 16);
        $slot = $this->seedSlot($releaseId, '0.1.0-alpha.2', $digest);
        $this->writePointer($releaseId, '0.1.0-alpha.2', $digest);

        $resolved = $this->locator()->resolve();

        $this->assertSame('slot', $resolved['kind']);
        $this->assertSame($slot, $resolved['release_root']);
        $this->assertSame($releaseId, $resolved['release_id']);
        $this->assertSame('0.1.0-alpha.2', $resolved['product_version']);
        $this->assertSame($digest, $resolved['package_digest_sha256']);
    }

    public function test_corrupt_pointer_fails_closed_instead_of_falling_back_to_root(): void
    {
        $path = $this->pointerPath();
        $this->ensureDirectory(dirname($path));
        file_put_contents($path, '{not-json');

        $this->expectException(RuntimeException::class);

        $this->locator()->resolve();
    }

    public function test_unsupported_pointer_schema_fails_closed(): void
    {
        $digest = str_repeat('b', 64);
        $releaseId = '0.1.0-alpha.2-'.substr($digest, 0, 16);
        $this->seedSlot($releaseId, '0.1.0-alpha.2', $digest);
        $this->writePointer($releaseId, '0.1.0-alpha.2', $digest, 'seo-release-pointer/999');

        $this->expectException(RuntimeException::class);

        $this->locator()->resolve();
    }

    /** @dataProvider invalidReleaseIds */
    public function test_unsafe_release_ids_fail_closed(string $releaseId): void
    {
        $digest = str_repeat('c', 64);
        $this->writePointer($releaseId, '0.1.0-alpha.2', $digest);

        $this->expectException(RuntimeException::class);

        $this->locator()->resolve();
    }

    /** @return iterable<string, array{string}> */
    public static function invalidReleaseIds(): iterable
    {
        yield 'traversal' => ['../escape'];
        yield 'slash' => ['alpha/escape'];
        yield 'backslash' => ['alpha\\escape'];
        yield 'control' => ["alpha\nrelease"];
        yield 'empty' => [''];
    }

    public function test_missing_release_slot_fails_closed(): void
    {
        $digest = str_repeat('d', 64);
        $releaseId = '0.1.0-alpha.2-'.substr($digest, 0, 16);
        $this->writePointer($releaseId, '0.1.0-alpha.2', $digest);

        $this->expectException(RuntimeException::class);

        $this->locator()->resolve();
    }

    public function test_symlinked_release_slot_fails_closed(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symlink support is unavailable.');
        }

        $digest = str_repeat('e', 64);
        $releaseId = '0.1.0-alpha.2-'.substr($digest, 0, 16);
        $releaseRoot = $this->releaseRoot();
        $this->ensureDirectory($releaseRoot);

        $outside = sys_get_temp_dir().'/seo-os-runtime-release-outside-'.bin2hex(random_bytes(6));
        mkdir($outside, 0755, true);
        file_put_contents($outside.'/VERSION', "0.1.0-alpha.2\n");
        file_put_contents($outside.'/release.json', json_encode($this->slotMarker($releaseId, '0.1.0-alpha.2', $digest), JSON_THROW_ON_ERROR));

        try {
            if (! @symlink($outside, $releaseRoot.'/'.$releaseId)) {
                $this->markTestSkipped('Unable to create symlink fixture.');
            }
            $this->writePointer($releaseId, '0.1.0-alpha.2', $digest);

            $this->expectException(RuntimeException::class);
            $this->locator()->resolve();
        } finally {
            @unlink($releaseRoot.'/'.$releaseId);
            @unlink($outside.'/VERSION');
            @unlink($outside.'/release.json');
            @rmdir($outside);
        }
    }

    public function test_slot_marker_mismatch_fails_closed(): void
    {
        $digest = str_repeat('f', 64);
        $releaseId = '0.1.0-alpha.2-'.substr($digest, 0, 16);
        $this->seedSlot($releaseId, '0.1.0-alpha.2', str_repeat('0', 64));
        $this->writePointer($releaseId, '0.1.0-alpha.2', $digest);

        $this->expectException(RuntimeException::class);

        $this->locator()->resolve();
    }

    public function test_symlinked_pointer_fails_closed(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symlink support is unavailable.');
        }

        $digest = str_repeat('1', 64);
        $releaseId = '0.1.0-alpha.2-'.substr($digest, 0, 16);
        $this->seedSlot($releaseId, '0.1.0-alpha.2', $digest);
        $this->ensureDirectory(dirname($this->pointerPath()));
        $target = $this->root.'/pointer-target.json';
        file_put_contents($target, json_encode($this->pointer($releaseId, '0.1.0-alpha.2', $digest), JSON_THROW_ON_ERROR));
        @chmod($target, 0600);

        try {
            if (! @symlink($target, $this->pointerPath())) {
                $this->markTestSkipped('Unable to create symlink fixture.');
            }

            $this->expectException(RuntimeException::class);
            $this->locator()->resolve();
        } finally {
            @unlink($this->pointerPath());
            @unlink($target);
        }
    }

    public function test_group_or_world_writable_pointer_fails_closed(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX file mode assertion is not portable to Windows.');
        }

        $digest = str_repeat('2', 64);
        $releaseId = '0.1.0-alpha.2-'.substr($digest, 0, 16);
        $this->seedSlot($releaseId, '0.1.0-alpha.2', $digest);
        $this->writePointer($releaseId, '0.1.0-alpha.2', $digest);
        chmod($this->pointerPath(), 0666);
        clearstatcache(true, $this->pointerPath());

        $this->expectException(RuntimeException::class);

        $this->locator()->resolve();
    }

    private function locator(): object
    {
        $helper = dirname(__DIR__, 3).'/bootstrap/runtime-release.php';
        $this->assertFileExists($helper, 'Stable runtime release locator must exist before release switching is supported.');
        require_once $helper;
        $this->assertTrue(class_exists('SeoOsRuntimeReleaseLocator'));

        return new \SeoOsRuntimeReleaseLocator($this->root);
    }

    private function seedSlot(string $releaseId, string $version, string $digest): string
    {
        $slot = $this->releaseRoot().'/'.$releaseId;
        $this->ensureDirectory($slot);
        file_put_contents($slot.'/VERSION', $version."\n");
        file_put_contents($slot.'/release.json', json_encode($this->slotMarker($releaseId, $version, $digest), JSON_THROW_ON_ERROR));
        @chmod($slot.'/release.json', 0600);

        return $slot;
    }

    /** @return array<string, string> */
    private function slotMarker(string $releaseId, string $version, string $digest): array
    {
        return [
            'schema' => 'seo-release-slot/1',
            'release_id' => $releaseId,
            'product_version' => $version,
            'package_digest_sha256' => $digest,
            'launcher_schema' => 'seo-launcher/1',
        ];
    }

    private function writePointer(string $releaseId, string $version, string $digest, string $schema = 'seo-release-pointer/1'): void
    {
        $this->ensureDirectory(dirname($this->pointerPath()));
        file_put_contents($this->pointerPath(), json_encode([
            ...$this->pointer($releaseId, $version, $digest),
            'schema' => $schema,
        ], JSON_THROW_ON_ERROR));
        @chmod($this->pointerPath(), 0600);
    }

    /** @return array<string, string> */
    private function pointer(string $releaseId, string $version, string $digest): array
    {
        return [
            'schema' => 'seo-release-pointer/1',
            'release_id' => $releaseId,
            'product_version' => $version,
            'package_digest_sha256' => $digest,
            'launcher_schema' => 'seo-launcher/1',
        ];
    }

    private function pointerPath(): string
    {
        return $this->root.'/storage/app/lifecycle/update/active-release.json';
    }

    private function releaseRoot(): string
    {
        return $this->root.'/storage/app/lifecycle/update/releases';
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0700, true);
        }
    }
}
