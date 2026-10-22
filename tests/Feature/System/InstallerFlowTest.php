<?php

namespace Tests\Feature\System;

use App\Contracts\SystemProbe;
use App\Support\InstallationState;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InstallerFlowTest extends TestCase
{
    private string $lockPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lockPath = storage_path('framework/testing/installer-'.bin2hex(random_bytes(6)).'.lock');
        config([
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->lockPath,
        ]);
        File::delete($this->lockPath);
        $this->app->bind(SystemProbe::class, fn () => $this->probe());
    }

    protected function tearDown(): void
    {
        File::delete($this->lockPath);

        parent::tearDown();
    }

    public function test_uninstalled_application_redirects_normal_web_traffic_to_installer(): void
    {
        $this->get('/')
            ->assertRedirect('/install');
    }

    public function test_installer_page_renders_health_report_without_database_backed_runtime_dependencies(): void
    {
        $this->get('/install')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('install/index')
                ->where('report.ready', true)
                ->where('report.checks.database.status', 'pass')
                ->where('installed', false)
            );

        $this->assertSame('file', config('session.driver'));
        $this->assertSame('file', config('cache.default'));
        $this->assertSame('sync', config('queue.default'));
    }

    public function test_ready_installer_can_write_lock_and_then_cannot_be_reopened(): void
    {
        $this->post('/install/complete')
            ->assertRedirect('/');

        $state = new InstallationState($this->lockPath, false);
        $this->assertTrue($state->isInstalled());
        $this->assertFileExists($this->lockPath);

        $this->get('/install')
            ->assertRedirect('/');
    }

    public function test_installer_refuses_completion_when_required_health_check_fails(): void
    {
        $this->app->bind(SystemProbe::class, fn () => $this->probe(databaseReachable: false));

        $this->post('/install/complete')
            ->assertUnprocessable();

        $state = new InstallationState($this->lockPath, false);
        $this->assertFalse($state->isInstalled());
        $this->assertFileDoesNotExist($this->lockPath);
    }

    private function probe(bool $databaseReachable = true): SystemProbe
    {
        return new class($databaseReachable) implements SystemProbe
        {
            public function __construct(private bool $databaseReachable) {}

            public function phpVersion(): string
            {
                return '8.4.1';
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
                return 'mysql';
            }

            public function queueConnection(): string
            {
                return 'database';
            }

            public function schedulerSupported(): bool
            {
                return true;
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
        };
    }
}
