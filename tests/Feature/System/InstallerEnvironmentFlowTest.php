<?php

namespace Tests\Feature\System;

use App\Contracts\SystemProbe;
use App\Support\Installer\DatabaseVerifier;
use App\Support\Installer\EnvironmentWriter;
use App\Support\Installer\InstallerEnvironment;
use App\Support\Installer\InstallerJournal;
use App\Support\Installer\InstallerMutex;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InstallerEnvironmentFlowTest extends TestCase
{
    private string $directory;

    private string $environmentPath;

    private string $examplePath;

    private InstallerJournal $journal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/installer-environment-flow-'.bin2hex(random_bytes(6)));
        $this->environmentPath = $this->directory.'/.env';
        $this->examplePath = $this->directory.'/.env.example';
        File::ensureDirectoryExists($this->directory);
        file_put_contents($this->examplePath, $this->exampleEnvironment());

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('i', 32)),
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->directory.'/.installed',
            'installer.journal_path' => $this->directory.'/journal.json',
            'installer.mutex_path' => $this->directory.'/installer.lock',
        ]);

        $this->journal = new InstallerJournal($this->directory.'/journal.json');
        $this->app->instance(InstallerJournal::class, $this->journal);
        $this->app->instance(InstallerMutex::class, new InstallerMutex($this->directory.'/installer.lock'));
        $this->app->instance(InstallerEnvironment::class, new InstallerEnvironment($this->environmentPath));
        $this->app->instance(EnvironmentWriter::class, new EnvironmentWriter($this->environmentPath, $this->examplePath));
        $this->app->bind(SystemProbe::class, fn () => $this->probe());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_ready_preflight_can_persist_environment_without_putting_password_in_journal(): void
    {
        $response = $this->post('/install/environment', [
            ...$this->validPayload(),
            'database_password' => 'browser-secret',
            'keep_database_password' => false,
        ]);

        $response->assertRedirect('/install');

        $journal = $this->journal->read();
        $serialized = json_encode($journal, JSON_THROW_ON_ERROR);

        $this->assertSame('database', $journal['phase']);
        $this->assertArrayNotHasKey('database_fingerprint', $journal);
        $this->assertStringNotContainsString('browser-secret', $serialized);
        $this->assertStringNotContainsString('database_password', $serialized);
        $this->assertStringContainsString('DB_PASSWORD="browser-secret"', (string) file_get_contents($this->environmentPath));
    }

    public function test_environment_write_is_blocked_when_preflight_has_a_required_failure(): void
    {
        $this->app->bind(SystemProbe::class, fn () => $this->probe(phpVersion: '8.4.0'));

        $this->from('/install')->post('/install/environment', [
            ...$this->validPayload(),
            'database_password' => 'browser-secret',
            'keep_database_password' => false,
        ])
            ->assertRedirect('/install')
            ->assertSessionHasErrors('preflight');

        $this->assertFileDoesNotExist($this->environmentPath);
        $this->assertSame([], $this->journal->read());
    }

    public function test_successful_database_verification_advances_to_migrations_with_non_secret_evidence(): void
    {
        $this->persistEnvironment();
        $this->app->instance(DatabaseVerifier::class, new DatabaseVerifier(
            probe: static fn (array $connection): null => null,
            extensionLoaded: static fn (string $extension): bool => true,
        ));

        $this->post('/install/database/verify')
            ->assertRedirect('/install');

        $journal = $this->journal->read();
        $serialized = json_encode($journal, JSON_THROW_ON_ERROR);

        $this->assertSame('migrations', $journal['phase']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $journal['database_fingerprint']);
        $this->assertSame([
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'seo_os',
        ], $journal['database_facts']);
        $this->assertStringNotContainsString('seo_user', $serialized);
        $this->assertStringNotContainsString('browser-secret', $serialized);
    }

    public function test_failed_database_verification_stays_in_database_phase_with_sanitized_error(): void
    {
        $this->persistEnvironment();
        $this->app->instance(DatabaseVerifier::class, new DatabaseVerifier(
            probe: static function (array $connection): void {
                throw new \RuntimeException('browser-secret seo_user raw failure');
            },
            extensionLoaded: static fn (string $extension): bool => true,
        ));

        $this->from('/install')->post('/install/database/verify')
            ->assertRedirect('/install')
            ->assertSessionHasErrors([
                'database' => 'Database verification failed unexpectedly.',
            ])
            ->assertSessionHas('database_verification_code', 'unknown');

        $journal = $this->journal->read();
        $this->assertSame('database', $journal['phase']);
        $this->assertArrayNotHasKey('database_fingerprint', $journal);
        $this->assertStringNotContainsString('browser-secret', json_encode($journal, JSON_THROW_ON_ERROR));
    }

    public function test_changing_environment_invalidates_previous_database_and_downstream_evidence(): void
    {
        $this->persistEnvironment();
        $this->journal->replace([
            'phase' => 'owner',
            'database_fingerprint' => hash('sha256', 'old'),
            'database_facts' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'seo_os',
            ],
            'migrations_completed_at' => '2026-10-22T19:00:00+00:00',
            'owner_user_id' => 1,
            'operations_acknowledged_at' => '2026-10-22T19:01:00+00:00',
        ]);

        $this->post('/install/environment', [
            ...$this->validPayload(),
            'database_host' => 'db.internal',
            'database_password' => '',
            'keep_database_password' => true,
        ])->assertRedirect('/install');

        $journal = $this->journal->read();

        $this->assertSame('database', $journal['phase']);
        $this->assertArrayNotHasKey('database_fingerprint', $journal);
        $this->assertArrayNotHasKey('migrations_completed_at', $journal);
        $this->assertArrayNotHasKey('owner_user_id', $journal);
        $this->assertArrayNotHasKey('operations_acknowledged_at', $journal);
    }

    private function persistEnvironment(): void
    {
        (new EnvironmentWriter($this->environmentPath, $this->examplePath))->persist(
            $this->validPayload(),
            'browser-secret',
            false,
        );
        $this->journal->replace(['phase' => 'database']);
    }

    /** @return array<string, int|string> */
    private function validPayload(): array
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

    private function exampleEnvironment(): string
    {
        return implode(PHP_EOL, [
            'APP_NAME="SEO OS"',
            'APP_ENV=local',
            'APP_KEY=',
            'APP_DEBUG=true',
            'APP_URL=http://localhost',
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
        ]);
    }

    private function probe(string $phpVersion = '8.4.1'): SystemProbe
    {
        return new class($phpVersion) implements SystemProbe
        {
            public function __construct(private string $phpVersion) {}

            public function phpVersion(): string
            {
                return $this->phpVersion;
            }

            public function extensionLoaded(string $extension): bool
            {
                return true;
            }

            public function pathIsWritable(string $path): bool
            {
                return true;
            }

            public function databaseReachable(): bool
            {
                return false;
            }

            public function databaseDriver(): string
            {
                return '';
            }

            public function queueConnection(): string
            {
                return '';
            }

            public function schedulerSupported(): bool
            {
                return false;
            }

            public function freeDiskBytes(string $path): ?int
            {
                return 1024 * 1024 * 1024;
            }

            public function appKeyConfigured(): bool
            {
                return true;
            }

            public function productionAssetsPresent(): bool
            {
                return true;
            }

            public function applicationFilesPresent(): bool
            {
                return true;
            }
        };
    }
}
