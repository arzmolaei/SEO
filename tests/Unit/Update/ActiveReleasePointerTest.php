<?php

namespace Tests\Unit\Update;

use App\Services\Update\UpdateException;
use App\Support\Update\ActiveReleasePointer;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ActiveReleasePointerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/seo-os-active-release-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0755, true);
        file_put_contents($this->root.'/VERSION', "0.1.0-alpha.1\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function test_slot_activation_is_atomic_private_and_matches_dependency_free_locator(): void
    {
        $digest = str_repeat('a', 64);
        $releaseId = '0.1.0-alpha.2-'.substr($digest, 0, 16);
        $this->seedSlot($releaseId, '0.1.0-alpha.2', $digest);

        $pointer = new ActiveReleasePointer($this->root);
        $pointer->activateSlot($releaseId, '0.1.0-alpha.2', $digest);

        $this->assertFileExists($this->pointerPath());
        $this->assertFileDoesNotExist($this->pointerPath().'.tmp');

        if (PHP_OS_FAMILY !== 'Windows') {
            clearstatcache(true, $this->pointerPath());
            $mode = fileperms($this->pointerPath());
            $this->assertIsInt($mode);
            $this->assertSame(0600, $mode & 0777);
        }

        require_once dirname(__DIR__, 3).'/bootstrap/runtime-release.php';
        $this->assertSame((new \SeoOsRuntimeReleaseLocator($this->root))->resolve(), $pointer->current());
        $this->assertSame('slot', $pointer->current()['kind']);
        $this->assertSame($releaseId, $pointer->current()['release_id']);
    }

    public function test_activate_root_persists_explicit_pointer_only_after_matching_root_version_is_proven(): void
    {
        $digest = str_repeat('b', 64);
        $releaseId = '0.1.0-alpha.2-'.substr($digest, 0, 16);
        $this->seedSlot($releaseId, '0.1.0-alpha.2', $digest);

        $pointer = new ActiveReleasePointer($this->root);
        $pointer->activateSlot($releaseId, '0.1.0-alpha.2', $digest);
        $pointer->activateRoot('0.1.0-alpha.1');

        $this->assertFileExists($this->pointerPath());
        $this->assertSame([
            'schema' => 'seo-root-release-pointer/1',
            'product_version' => '0.1.0-alpha.1',
            'launcher_schema' => 'seo-launcher/2',
        ], json_decode(file_get_contents($this->pointerPath()), true, flags: JSON_THROW_ON_ERROR));
        $this->assertFileDoesNotExist($this->pointerPath().'.tmp');
        $this->assertSame('root', $pointer->current()['kind']);
        $this->assertSame('0.1.0-alpha.1', $pointer->current()['product_version']);

        $pointer->activateSlot($releaseId, '0.1.0-alpha.2', $digest);

        try {
            $pointer->activateRoot('0.1.0-alpha.9');
            $this->fail('Mismatched root version should be rejected.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_invalid_state', $exception->codeName());
            $this->assertSame($releaseId, $pointer->current()['release_id']);
        }
    }

    public function test_current_never_redeclares_a_second_launcher_copy_in_a_booted_process(): void
    {
        // The test bootstrap mirrors public/index.php and artisan: the stable
        // launcher is already loaded from another path. A copy under the
        // installation root must be ignored instead of fatally redeclaring it.
        mkdir($this->root.'/bootstrap', 0755, true);
        copy(dirname(__DIR__, 3).'/bootstrap/runtime-release.php', $this->root.'/bootstrap/runtime-release.php');

        $current = (new ActiveReleasePointer($this->root))->current();

        $this->assertSame('root', $current['kind']);
        $this->assertSame('0.1.0-alpha.1', $current['product_version']);
    }

    public function test_fresh_process_without_an_installation_launcher_fails_closed(): void
    {
        $script = $this->root.'/fresh-process.php';
        file_put_contents($script, '<?php'."\n".'require '.var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true).";\n"
            .'try { (new App\\Support\\Update\\ActiveReleasePointer('.var_export($this->root, true).'))->current(); echo "resolved"; }'."\n"
            .'catch (App\\Services\\Update\\UpdateException $e) { echo $e->codeName(); }'."\n");

        $process = new Process([PHP_BINARY, $script], timeout: 30);
        $process->run();

        $this->assertSame('update_invalid_state', trim($process->getOutput()), $process->getErrorOutput());
    }

    public function test_slot_marker_must_match_exact_release_version_digest_and_launcher_schema(): void
    {
        $digest = str_repeat('c', 64);
        $releaseId = '0.1.0-alpha.2-'.substr($digest, 0, 16);

        foreach ([
            'version' => ['0.1.0-alpha.9', $digest, 'seo-launcher/2'],
            'digest' => ['0.1.0-alpha.2', str_repeat('d', 64), 'seo-launcher/2'],
            'launcher' => ['0.1.0-alpha.2', $digest, 'seo-launcher/999'],
        ] as $label => [$markerVersion, $markerDigest, $launcher]) {
            $this->removeDirectory($this->releaseRoot());
            $this->seedSlot($releaseId, $markerVersion, $markerDigest, $launcher);

            try {
                (new ActiveReleasePointer($this->root))->activateSlot($releaseId, '0.1.0-alpha.2', $digest);
                $this->fail("Mismatched {$label} marker should be rejected.");
            } catch (UpdateException $exception) {
                $this->assertSame('update_invalid_state', $exception->codeName());
                $this->assertFileDoesNotExist($this->pointerPath());
            }
        }
    }

    public function test_unsafe_release_ids_and_symlinked_slots_are_rejected(): void
    {
        $digest = str_repeat('e', 64);

        foreach (['', '../escape', 'slot/escape', 'slot\\escape', "slot\nescape"] as $releaseId) {
            try {
                (new ActiveReleasePointer($this->root))->activateSlot($releaseId, '0.1.0-alpha.2', $digest);
                $this->fail("Unsafe release id should be rejected: {$releaseId}");
            } catch (UpdateException $exception) {
                $this->assertSame('update_invalid_state', $exception->codeName());
            }
        }

        if (! function_exists('symlink')) {
            return;
        }

        $releaseId = '0.1.0-alpha.2-'.substr($digest, 0, 16);
        $outside = sys_get_temp_dir().'/seo-os-active-release-outside-'.bin2hex(random_bytes(6));
        mkdir($outside, 0755, true);
        file_put_contents($outside.'/VERSION', "0.1.0-alpha.2\n");
        file_put_contents($outside.'/release.json', json_encode($this->slotMarker($releaseId, '0.1.0-alpha.2', $digest, 'seo-launcher/2'), JSON_THROW_ON_ERROR));
        $this->ensureDirectory($this->releaseRoot());

        try {
            if (! @symlink($outside, $this->releaseRoot().'/'.$releaseId)) {
                return;
            }

            $this->expectException(UpdateException::class);
            (new ActiveReleasePointer($this->root))->activateSlot($releaseId, '0.1.0-alpha.2', $digest);
        } finally {
            @unlink($this->releaseRoot().'/'.$releaseId);
            @unlink($outside.'/VERSION');
            @unlink($outside.'/release.json');
            @rmdir($outside);
        }
    }

    public function test_replacing_slot_a_with_slot_b_never_leaves_a_stale_pointer(): void
    {
        $digestA = str_repeat('1', 64);
        $digestB = str_repeat('2', 64);
        $releaseA = '0.1.0-alpha.2-'.substr($digestA, 0, 16);
        $releaseB = '0.1.0-alpha.3-'.substr($digestB, 0, 16);
        $this->seedSlot($releaseA, '0.1.0-alpha.2', $digestA);
        $this->seedSlot($releaseB, '0.1.0-alpha.3', $digestB);

        $pointer = new ActiveReleasePointer($this->root);
        $pointer->activateSlot($releaseA, '0.1.0-alpha.2', $digestA);
        $pointer->activateSlot($releaseB, '0.1.0-alpha.3', $digestB);

        $this->assertSame($releaseB, $pointer->current()['release_id']);
        $this->assertSame('0.1.0-alpha.3', $pointer->current()['product_version']);
        $this->assertFileDoesNotExist($this->pointerPath().'.tmp');
    }

    private function seedSlot(string $releaseId, string $version, string $digest, string $launcher = 'seo-launcher/2'): void
    {
        $slot = $this->releaseRoot().'/'.$releaseId;
        $this->ensureDirectory($slot);
        file_put_contents($slot.'/VERSION', $version."\n");
        file_put_contents($slot.'/release.json', json_encode($this->slotMarker($releaseId, $version, $digest, $launcher), JSON_THROW_ON_ERROR));
        @chmod($slot.'/release.json', 0600);
    }

    /** @return array<string, string> */
    private function slotMarker(string $releaseId, string $version, string $digest, string $launcher): array
    {
        return [
            'schema' => 'seo-release-slot/1',
            'release_id' => $releaseId,
            'product_version' => $version,
            'package_digest_sha256' => $digest,
            'launcher_schema' => $launcher,
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

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() && ! $item->isLink()
                ? rmdir($item->getPathname())
                : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
