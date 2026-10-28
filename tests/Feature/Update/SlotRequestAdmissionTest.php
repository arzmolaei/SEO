<?php

namespace Tests\Feature\Update;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * After the release switch every request is served by a private slot whose
 * base path differs from the stable installation root, while public/index.php
 * has already loaded the root launcher. Admission during maintenance must not
 * load a second launcher copy (its classes are not redeclarable).
 */
class SlotRequestAdmissionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/slot-admission-'.bin2hex(random_bytes(6)));
        $root = $this->directory.'/installation';
        File::ensureDirectoryExists($root.'/bootstrap');
        File::ensureDirectoryExists($root.'/storage/app/lifecycle/update');
        File::ensureDirectoryExists($root.'/storage/framework/views');
        File::ensureDirectoryExists($root.'/storage/framework/cache/data');
        File::ensureDirectoryExists($root.'/storage/logs');
        File::copy(base_path('bootstrap/runtime-release.php'), $root.'/bootstrap/runtime-release.php');
        File::put($root.'/storage/app/lifecycle/update/state.json', json_encode([
            'phase' => 'target_active',
            'upload_id' => str_repeat('a', 32),
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_maintenance_admission_in_a_slot_request_reuses_the_loaded_root_launcher(): void
    {
        $root = $this->directory.'/installation';
        $script = $this->directory.'/slot-request.php';
        File::put($script, <<<'PHP'
<?php
// public/index.php has already loaded the stable root launcher.
require_once $argv[2].'/bootstrap/runtime-release.php';
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->useStoragePath($argv[2].'/storage');
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(Illuminate\Http\Request::create('/settings/update', 'GET'));
echo 'status='.$response->getStatusCode();
PHP);

        $process = new Process([PHP_BINARY, $script, base_path(), $root], base_path(), [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'INSTALLER_ASSUME_INSTALLED' => 'true',
            'BACKUP_LIFECYCLE_LOCK_PATH' => $root.'/storage/app/lifecycle/lifecycle.lock',
            'DB_CONNECTION' => 'sqlite',
            'DB_URL' => '',
            'DB_DATABASE' => ':memory:',
            'SESSION_DRIVER' => 'array',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ]);
        $process->setTimeout(60);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringNotContainsString('Cannot redeclare', $output);
        // Recovery path stays reachable: unauthenticated users are sent to log in.
        $this->assertStringContainsString('status=302', $output);
    }
}
