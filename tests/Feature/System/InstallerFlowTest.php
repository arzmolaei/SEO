<?php

namespace Tests\Feature\System;

use App\Contracts\SystemProbe;
use App\Support\InstallationState;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InstallerFlowTest extends TestCase
{
    private string $directory;

    private string $lockPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/installer-flow-'.bin2hex(random_bytes(6)));
        $this->lockPath = $this->directory.'/.installed';
        File::ensureDirectoryExists($this->directory);
        config([
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->lockPath,
            'installer.journal_path' => $this->directory.'/journal.json',
            'installer.mutex_path' => $this->directory.'/installer.lock',
        ]);
        $this->app->bind(SystemProbe::class, fn () => $this->probe());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_uninstalled_application_redirects_normal_web_traffic_to_installer(): void
    {
        $this->get('/')
            ->assertRedirect('/install');
    }

    public function test_ready_preflight_exposes_environment_phase_without_database_backed_runtime_dependencies(): void
    {
        $this->app->bind(SystemProbe::class, fn () => $this->probe(
            databaseReachable: false,
            databaseDriver: '',
            queueConnection: '',
            schedulerSupported: false,
        ));

        $this->get('/install')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('install/index')
                ->where('installer.phase', 'environment')
                ->where('installer.installed', false)
                ->where('installer.values', [])
                ->where('installer.actions', ['refresh_preflight', 'save_environment'])
                ->where('preflight.ready', true)
                ->missing('preflight.checks.database')
                ->missing('preflight.checks.queue')
                ->missing('preflight.checks.scheduler')
            );

        $this->assertSame('file', config('session.driver'));
        $this->assertSame('file', config('cache.default'));
        $this->assertSame('sync', config('queue.default'));
    }

    public function test_failed_preflight_stays_on_preflight_and_does_not_offer_environment_save(): void
    {
        $this->app->bind(SystemProbe::class, fn () => $this->probe(phpVersion: '8.2.0'));

        $this->get('/install')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installer.phase', 'preflight')
                ->where('installer.actions', ['refresh_preflight'])
                ->where('preflight.ready', false)
            );
    }

    public function test_legacy_complete_endpoint_cannot_finalize_a_partial_installation(): void
    {
        $this->post('/install/complete')
            ->assertNotFound();

        $state = new InstallationState($this->lockPath, false);
        $this->assertFalse($state->isInstalled());
        $this->assertFileDoesNotExist($this->lockPath);
    }

    public function test_existing_installed_lock_still_prevents_reopening_installer(): void
    {
        (new InstallationState($this->lockPath, false))->markInstalled([
            'installer_schema_version' => 2,
            'product_version' => '0.1.0',
            'installation_id' => '01JARTESTINSTALLATION00000000',
        ]);

        $this->get('/install')
            ->assertRedirect('/');
    }

    private function probe(
        bool $databaseReachable = true,
        string $databaseDriver = 'mysql',
        string $queueConnection = 'database',
        bool $schedulerSupported = true,
        string $phpVersion = '8.3.0',
    ): SystemProbe {
        return new class($databaseReachable, $databaseDriver, $queueConnection, $schedulerSupported, $phpVersion) implements SystemProbe
        {
            public function __construct(
                private bool $databaseReachable,
                private string $databaseDriver,
                private string $queueConnection,
                private bool $schedulerSupported,
                private string $phpVersion,
            ) {}

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
                return $this->databaseReachable;
            }

            public function databaseDriver(): string
            {
                return $this->databaseDriver;
            }

            public function queueConnection(): string
            {
                return $this->queueConnection;
            }

            public function schedulerSupported(): bool
            {
                return $this->schedulerSupported;
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
