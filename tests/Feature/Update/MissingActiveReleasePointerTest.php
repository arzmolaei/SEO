<?php

namespace Tests\Feature\Update;

use App\Services\Update\UpdateException;
use App\Support\Update\ActiveReleasePointer;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MissingActiveReleasePointerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/missing-pointer-'.bin2hex(random_bytes(6)));
        foreach (['bootstrap', 'public', 'vendor', 'storage/app/lifecycle/update'] as $dir) {
            File::ensureDirectoryExists($this->root.'/'.$dir);
        }
        foreach (['bootstrap/runtime-release.php', 'public/index.php', 'artisan', 'VERSION'] as $file) {
            File::copy(base_path($file), $this->root.'/'.$file);
        }
        File::put($this->root.'/vendor/autoload.php', '<?php echo "UNSAFE_ROOT_BOOT"; exit(0);');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public static function updateEvidence(): array
    {
        $cases = [];
        foreach (['state.json', 'last-complete.json', 'releases/retained/VERSION', 'installed-version'] as $evidence) {
            foreach (['missing', 'corrupt', 'symlink'] as $damage) {
                $cases[$evidence.' '.$damage] = [$evidence, $damage];
            }
        }

        return $cases;
    }

    #[DataProvider('updateEvidence')]
    public function test_entrypoints_refuse_root_fallback_after_update_evidence(string $evidence, string $damage): void
    {
        $path = $evidence === 'installed-version'
            ? $this->root.'/storage/app/.installed'
            : $this->root.'/storage/app/lifecycle/update/'.$evidence;
        File::ensureDirectoryExists(dirname($path));
        File::put($path, match ($evidence) {
            'state.json' => '{"phase":"uploaded"}',
            'installed-version' => '{"product_version":"0.1.0-alpha.2"}',
            default => '{}',
        });
        $pointerPath = $this->root.'/storage/app/lifecycle/update/active-release.json';
        if ($damage === 'corrupt') {
            File::put($pointerPath, '{invalid');
        } elseif ($damage === 'symlink') {
            symlink($this->root.'/VERSION', $pointerPath);
        }
        foreach (['public/index.php', 'artisan'] as $entrypoint) {
            $process = new Process([PHP_BINARY, $this->root.'/'.$entrypoint]);
            $process->run();
            $this->assertFalse($process->isSuccessful(), $entrypoint.' silently booted root');
            $this->assertStringNotContainsString('UNSAFE_ROOT_BOOT', $process->getOutput());
            $this->assertStringContainsString('recovery is required', $process->getOutput().$process->getErrorOutput());
        }
        $this->expectException(UpdateException::class);
        (new ActiveReleasePointer($this->root))->current();
    }

    public function test_pristine_install_can_boot_root_and_explicit_root_survives_update_evidence(): void
    {
        $pointer = new ActiveReleasePointer($this->root);
        $version = trim(File::get($this->root.'/VERSION'));
        $this->assertSame('root', $pointer->current()['kind']);
        $pointer->activateRoot($version);
        File::put($this->root.'/storage/app/lifecycle/update/last-complete.json', '{}');
        $this->assertSame('root', $pointer->current()['kind']);
    }
}
