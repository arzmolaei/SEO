<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\EnvironmentSettingsUpdater;
use App\Support\Installer\InstallerEnvironment;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RestoreSystemBackupTest extends TestCase
{
    private string $directory;

    private string $environmentPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/restore-system-backup-'.bin2hex(random_bytes(6)));
        $this->environmentPath = $this->directory.'/.env';
        File::ensureDirectoryExists($this->directory);

        File::put($this->environmentPath, implode("\n", [
            '# destination environment',
            'APP_KEY=base64:destination-key',
            'APP_LOCALE=de',
            'APP_FALLBACK_LOCALE=en',
            'SELF_HOST_REGISTRATION=false',
            'APP_URL=https://destination.example.test',
            'DB_CONNECTION=sqlite',
            'DB_DATABASE=/srv/destination.sqlite',
            'DB_PASSWORD=destination-db-secret',
            'MAIL_PASSWORD=destination-mail-secret',
            'FILESYSTEM_DISK=local',
            'EXTERNAL_API_TOKEN=destination-external-secret',
            '',
        ]));

        config([
            'app.key' => 'base64:destination-key',
            'app.locale' => 'de',
            'app.fallback_locale' => 'en',
            'installer.public_registration' => false,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            File::deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    public function test_environment_updater_changes_only_restore_allowlist_and_refreshes_runtime_config(): void
    {
        (new EnvironmentSettingsUpdater($this->environmentPath))->apply([
            'APP_KEY' => 'base64:source-key',
            'APP_LOCALE' => 'fa',
            'APP_FALLBACK_LOCALE' => 'en',
            'SELF_HOST_REGISTRATION' => true,
        ]);

        $values = (new InstallerEnvironment($this->environmentPath))->values();

        $this->assertSame('base64:source-key', $values['APP_KEY']);
        $this->assertSame('fa', $values['APP_LOCALE']);
        $this->assertSame('en', $values['APP_FALLBACK_LOCALE']);
        $this->assertSame('true', $values['SELF_HOST_REGISTRATION']);
        $this->assertSame('https://destination.example.test', $values['APP_URL']);
        $this->assertSame('sqlite', $values['DB_CONNECTION']);
        $this->assertSame('/srv/destination.sqlite', $values['DB_DATABASE']);
        $this->assertSame('destination-db-secret', $values['DB_PASSWORD']);
        $this->assertSame('destination-mail-secret', $values['MAIL_PASSWORD']);
        $this->assertSame('local', $values['FILESYSTEM_DISK']);
        $this->assertSame('destination-external-secret', $values['EXTERNAL_API_TOKEN']);

        $contents = (string) File::get($this->environmentPath);
        $this->assertStringContainsString('# destination environment', $contents);
        $this->assertFileDoesNotExist($this->environmentPath.'.tmp');

        $this->assertSame('base64:source-key', config('app.key'));
        $this->assertSame('fa', config('app.locale'));
        $this->assertSame('en', config('app.fallback_locale'));
        $this->assertTrue((bool) config('installer.public_registration'));
    }
}
