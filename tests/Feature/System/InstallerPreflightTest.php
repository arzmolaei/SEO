<?php

namespace Tests\Feature\System;

use App\Contracts\SystemProbe;
use App\Support\Installer\InstallerPreflight;
use Tests\TestCase;

class InstallerPreflightTest extends TestCase
{
    public function test_preflight_is_ready_without_database_queue_or_scheduler_configuration(): void
    {
        $report = (new InstallerPreflight($this->probe([
            'databaseReachable' => false,
            'databaseDriver' => '',
            'queueConnection' => '',
            'schedulerSupported' => false,
            'missingExtensions' => ['pdo_mysql'],
        ])))->check();

        $this->assertTrue($report['ready']);
        $this->assertArrayNotHasKey('database', $report['checks']);
        $this->assertArrayNotHasKey('queue', $report['checks']);
        $this->assertArrayNotHasKey('scheduler', $report['checks']);
        $this->assertSame([], $report['checks']['extensions']['value']['missing']);
    }

    public function test_true_host_blockers_fail_preflight_before_database_setup(): void
    {
        $report = (new InstallerPreflight($this->probe([
            'phpVersion' => '8.4.0',
            'missingExtensions' => ['mbstring'],
            'writable' => false,
            'freeDiskBytes' => 64 * 1024 * 1024,
            'appKeyConfigured' => false,
            'productionAssetsPresent' => false,
            'applicationFilesPresent' => false,
        ])))->check();

        $this->assertFalse($report['ready']);

        foreach (['php', 'extensions', 'storage', 'disk', 'app_key', 'assets', 'runtime_files'] as $check) {
            $this->assertSame('fail', $report['checks'][$check]['status'], $check);
            $this->assertTrue($report['checks'][$check]['required'], $check);
        }
    }

    public function test_unmeasurable_disk_space_is_a_non_blocking_warning(): void
    {
        $report = (new InstallerPreflight($this->probe([
            'freeDiskBytes' => null,
        ])))->check();

        $this->assertTrue($report['ready']);
        $this->assertSame('warning', $report['checks']['disk']['status']);
        $this->assertFalse($report['checks']['disk']['required']);
    }

    public function test_low_php_upload_limits_warn_without_blocking_installation(): void
    {
        $report = (new InstallerPreflight($this->probe(), static fn (): ?int => 8 * 1024 * 1024))->check();

        $this->assertTrue($report['ready']);
        $this->assertSame('warning', $report['checks']['upload_limit']['status']);
        $this->assertFalse($report['checks']['upload_limit']['required']);
        $this->assertSame(8 * 1024 * 1024, $report['checks']['upload_limit']['value']);
        $this->assertStringContainsString('upload_max_filesize', $report['checks']['upload_limit']['message']);
    }

    public function test_sufficient_or_unlimited_upload_limits_pass(): void
    {
        foreach ([256 * 1024 * 1024, null] as $limit) {
            $report = (new InstallerPreflight($this->probe(), static fn (): ?int => $limit))->check();
            $this->assertSame('pass', $report['checks']['upload_limit']['status']);
        }
    }

    /**
     * @param array{
     *   phpVersion?: string,
     *   missingExtensions?: list<string>,
     *   writable?: bool,
     *   databaseReachable?: bool,
     *   databaseDriver?: string,
     *   queueConnection?: string,
     *   schedulerSupported?: bool,
     *   freeDiskBytes?: int|null,
     *   appKeyConfigured?: bool,
     *   productionAssetsPresent?: bool,
     *   applicationFilesPresent?: bool
     * } $overrides
     */
    private function probe(array $overrides = []): SystemProbe
    {
        $state = array_merge([
            'phpVersion' => '8.4.1',
            'missingExtensions' => [],
            'writable' => true,
            'databaseReachable' => true,
            'databaseDriver' => 'mysql',
            'queueConnection' => 'database',
            'schedulerSupported' => true,
            'freeDiskBytes' => 1024 * 1024 * 1024,
            'appKeyConfigured' => true,
            'productionAssetsPresent' => true,
            'applicationFilesPresent' => true,
        ], $overrides);

        return new class($state) implements SystemProbe
        {
            /** @param array<string, mixed> $state */
            public function __construct(private array $state) {}

            public function phpVersion(): string
            {
                return (string) $this->state['phpVersion'];
            }

            public function extensionLoaded(string $extension): bool
            {
                /** @var list<string> $missing */
                $missing = $this->state['missingExtensions'];

                return ! in_array($extension, $missing, true);
            }

            public function pathIsWritable(string $path): bool
            {
                return (bool) $this->state['writable'];
            }

            public function databaseReachable(): bool
            {
                return (bool) $this->state['databaseReachable'];
            }

            public function databaseDriver(): string
            {
                return (string) $this->state['databaseDriver'];
            }

            public function queueConnection(): string
            {
                return (string) $this->state['queueConnection'];
            }

            public function schedulerSupported(): bool
            {
                return (bool) $this->state['schedulerSupported'];
            }

            public function freeDiskBytes(string $path): ?int
            {
                $value = $this->state['freeDiskBytes'];

                return is_int($value) ? $value : null;
            }

            public function appKeyConfigured(): bool
            {
                return (bool) $this->state['appKeyConfigured'];
            }

            public function productionAssetsPresent(): bool
            {
                return (bool) $this->state['productionAssetsPresent'];
            }

            public function applicationFilesPresent(): bool
            {
                return (bool) $this->state['applicationFilesPresent'];
            }
        };
    }
}
