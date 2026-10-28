<?php

namespace Tests\Feature\System;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A freshly unpacked runtime has no .env and no database yet, so framework
 * defaults (database cache/session/queue) cannot work. Rate limiting on the
 * installer routes must still function in a genuinely fresh process.
 */
class PreInstallRuntimeTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/pre-install-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_installer_rate_limiting_works_before_a_database_exists(): void
    {
        $result = $this->bootFreshProcess(installed: false);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('attempts=1', $result['output']);
        $this->assertFileDoesNotExist($this->directory.'/missing.sqlite');
    }

    public function test_fresh_runtime_without_environment_file_is_persian_and_rtl(): void
    {
        $result = $this->bootFreshProcess(installed: false);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('locale=fa direction=rtl', $result['output']);
    }

    public function test_installed_runtime_keeps_its_configured_cache_store(): void
    {
        File::put($this->directory.'/.installed', '{}');

        $result = $this->bootFreshProcess(installed: true);

        // An installed instance must not silently fall back to file cache:
        // its configured (here deliberately broken) database store is used.
        $this->assertNotSame(0, $result['exit'], $result['output']);
    }

    /** @return array{exit: int, output: string} */
    private function bootFreshProcess(bool $installed): array
    {
        $script = $this->directory.'/probe.php';
        File::put($script, <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
// Like a freshly unpacked runtime: no .env file exists yet.
$app->useEnvironmentPath($argv[2]);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $key = 'pre-install-probe-'.bin2hex(random_bytes(8));
    Illuminate\Support\Facades\RateLimiter::hit($key);
    echo 'attempts='.Illuminate\Support\Facades\RateLimiter::attempts($key);
    echo ' locale='.App\Support\LocaleContext::from(app()->getLocale())['locale'];
    echo ' direction='.App\Support\LocaleContext::from(app()->getLocale())['direction'];
    Illuminate\Support\Facades\RateLimiter::clear($key);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    exit(3);
}
PHP);

        $process = new Process([PHP_BINARY, $script, base_path(), $this->directory], base_path(), [
            'APP_ENV' => 'production',
            // Nothing from this test runner's own .env may leak into the fresh runtime.
            'APP_LOCALE' => false,
            'APP_FALLBACK_LOCALE' => false,
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'CACHE_STORE' => 'database',
            'SESSION_DRIVER' => 'database',
            'QUEUE_CONNECTION' => 'database',
            'DB_CONNECTION' => 'sqlite',
            'DB_URL' => '',
            'DB_DATABASE' => $this->directory.'/missing.sqlite',
            'INSTALLER_ASSUME_INSTALLED' => 'false',
            'INSTALLER_LOCK_PATH' => $this->directory.'/.installed',
            'INSTALLER_RUNTIME_KEY_PATH' => $this->directory.'/runtime-app-key',
        ]);
        $process->setTimeout(60);
        $process->run();

        return ['exit' => (int) $process->getExitCode(), 'output' => $process->getOutput().$process->getErrorOutput()];
    }
}
