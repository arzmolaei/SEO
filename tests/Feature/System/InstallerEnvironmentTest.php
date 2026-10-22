<?php

namespace Tests\Feature\System;

use App\Support\Installer\EnvironmentWriter;
use App\Support\Installer\InstallerEnvironment;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class InstallerEnvironmentTest extends TestCase
{
    private string $directory;

    private string $environmentPath;

    private string $examplePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/installer-environment-'.bin2hex(random_bytes(6)));
        $this->environmentPath = $this->directory.'/.env';
        $this->examplePath = $this->directory.'/.env.example';
        File::ensureDirectoryExists($this->directory);

        file_put_contents($this->examplePath, implode(PHP_EOL, [
            'APP_NAME="SEO OS"',
            'APP_ENV=local',
            'APP_KEY=',
            'APP_DEBUG=true',
            'APP_URL=http://localhost',
            'CUSTOM_KEEP="from-example"',
            'DB_CONNECTION=sqlite',
            'DB_HOST=127.0.0.1',
            'DB_PORT=3306',
            'DB_DATABASE=laravel',
            'DB_USERNAME=root',
            'DB_PASSWORD=',
            'SESSION_DRIVER=database',
            'CACHE_STORE=database',
            'QUEUE_CONNECTION=database',
            'SELF_HOST_REGISTRATION=true',
            '',
        ]));

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_writer_preserves_unrelated_values_and_persists_safe_production_defaults_atomically(): void
    {
        file_put_contents($this->environmentPath, implode(PHP_EOL, [
            'APP_NAME="Existing SEO"',
            'CUSTOM_KEEP="do-not-touch"',
            'DB_PASSWORD="old-secret"',
            '',
        ]));

        $writer = new EnvironmentWriter($this->environmentPath, $this->examplePath);
        $writer->persist($this->validValues(), 'new secret # with spaces', false);

        $contents = (string) file_get_contents($this->environmentPath);

        $this->assertStringContainsString('APP_NAME="Existing SEO"', $contents);
        $this->assertStringContainsString('CUSTOM_KEEP="do-not-touch"', $contents);
        $this->assertStringContainsString('APP_ENV=production', $contents);
        $this->assertStringContainsString('APP_DEBUG=false', $contents);
        $this->assertStringContainsString('APP_URL="https://seo.example.test"', $contents);
        $this->assertStringContainsString('APP_KEY="'.config('app.key').'"', $contents);
        $this->assertStringContainsString('DB_CONNECTION=mysql', $contents);
        $this->assertStringContainsString('DB_HOST="127.0.0.1"', $contents);
        $this->assertStringContainsString('DB_PORT=3306', $contents);
        $this->assertStringContainsString('DB_DATABASE="seo_os"', $contents);
        $this->assertStringContainsString('DB_USERNAME="seo_user"', $contents);
        $this->assertStringContainsString('DB_PASSWORD="new secret # with spaces"', $contents);
        $this->assertStringContainsString('SESSION_DRIVER=database', $contents);
        $this->assertStringContainsString('CACHE_STORE=database', $contents);
        $this->assertStringContainsString('QUEUE_CONNECTION=database', $contents);
        $this->assertStringContainsString('SELF_HOST_REGISTRATION=false', $contents);
        $this->assertFileDoesNotExist($this->environmentPath.'.tmp');
    }

    public function test_missing_env_starts_from_example_and_public_values_never_return_password(): void
    {
        $writer = new EnvironmentWriter($this->environmentPath, $this->examplePath);
        $writer->persist($this->validValues(), 'top-secret', false);

        $public = (new InstallerEnvironment($this->environmentPath))->publicValues();

        $this->assertSame([
            'app_url' => 'https://seo.example.test',
            'database_driver' => 'mysql',
            'database_host' => '127.0.0.1',
            'database_port' => 3306,
            'database_name' => 'seo_os',
            'database_username' => 'seo_user',
            'has_database_password' => true,
        ], $public);
        $this->assertStringNotContainsString('top-secret', json_encode($public, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('CUSTOM_KEEP="from-example"', (string) file_get_contents($this->environmentPath));
    }

    public function test_keep_current_password_retains_secret_without_echoing_it(): void
    {
        $writer = new EnvironmentWriter($this->environmentPath, $this->examplePath);
        $writer->persist($this->validValues(), 'first-secret', false);
        $writer->persist([
            ...$this->validValues(),
            'database_host' => 'db.internal',
        ], null, true);

        $contents = (string) file_get_contents($this->environmentPath);

        $this->assertStringContainsString('DB_PASSWORD="first-secret"', $contents);
        $this->assertStringContainsString('DB_HOST="db.internal"', $contents);
        $this->assertTrue((new InstallerEnvironment($this->environmentPath))->publicValues()['has_database_password']);
    }

    public function test_invalid_environment_values_are_rejected_before_writing(): void
    {
        $invalid = [
            ['app_url' => 'javascript:alert(1)'],
            ['database_driver' => 'pgsql'],
            ['database_host' => "db\nINJECTED=true"],
            ['database_port' => 70000],
            ['database_name' => ''],
            ['database_username' => ''],
        ];

        foreach ($invalid as $override) {
            $path = $this->directory.'/invalid-'.bin2hex(random_bytes(4));
            $writer = new EnvironmentWriter($path, $this->examplePath);

            try {
                $writer->persist([...$this->validValues(), ...$override], 'secret', false);
                $this->fail('Invalid installer environment values were accepted.');
            } catch (InvalidArgumentException) {
                $this->assertFileDoesNotExist($path);
            }
        }
    }

    public function test_failed_atomic_replace_leaves_existing_environment_byte_for_byte_unchanged(): void
    {
        $original = "APP_NAME=Existing\nDB_PASSWORD=old-secret\nCUSTOM_KEEP=yes\n";
        file_put_contents($this->environmentPath, $original);

        $writer = new EnvironmentWriter(
            $this->environmentPath,
            $this->examplePath,
            static fn (string $temporaryPath, string $destinationPath): bool => false,
        );

        try {
            $writer->persist($this->validValues(), 'new-secret', false);
            $this->fail('Expected atomic replacement failure.');
        } catch (RuntimeException) {
            $this->assertSame($original, file_get_contents($this->environmentPath));
            $this->assertFileDoesNotExist($this->environmentPath.'.tmp');
        }
    }

    /** @return array<string, int|string> */
    private function validValues(): array
    {
        return [
            'app_url' => 'https://seo.example.test',
            'database_driver' => 'mysql',
            'database_host' => '127.0.0.1',
            'database_port' => 3306,
            'database_name' => 'seo_os',
            'database_username' => 'seo_user',
        ];
    }
}
