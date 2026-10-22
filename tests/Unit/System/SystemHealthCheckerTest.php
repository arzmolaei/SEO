<?php

namespace Tests\Unit\System;

use App\Contracts\SystemProbe;
use App\Support\SystemHealthChecker;
use PHPUnit\Framework\TestCase;

class SystemHealthCheckerTest extends TestCase
{
    public function test_healthy_self_host_environment_is_ready_without_shell_probes(): void
    {
        $checker = new SystemHealthChecker($this->probe());

        $report = $checker->check();

        $this->assertTrue($report['ready']);
        $this->assertSame('pass', $report['checks']['php']['status']);
        $this->assertSame('pass', $report['checks']['extensions']['status']);
        $this->assertSame('pass', $report['checks']['storage']['status']);
        $this->assertSame('pass', $report['checks']['database_extension']['status']);
        $this->assertSame('pass', $report['checks']['database']['status']);
        $this->assertSame('pass', $report['checks']['queue']['status']);
        $this->assertSame('pass', $report['checks']['scheduler']['status']);
        $this->assertSame('pass', $report['checks']['disk']['status']);
        $this->assertSame('pass', $report['checks']['app_key']['status']);
        $this->assertSame('pass', $report['checks']['assets']['status']);
        $this->assertSame('pass', $report['checks']['runtime_files']['status']);
    }

    public function test_required_runtime_failures_make_environment_not_ready(): void
    {
        $checker = new SystemHealthChecker($this->probe([
            'phpVersion' => '8.2.28',
            'missingExtensions' => ['mbstring', 'pdo_mysql'],
            'writable' => false,
            'databaseReachable' => false,
            'queueConnection' => '',
            'schedulerSupported' => false,
            'freeDiskBytes' => 32 * 1024 * 1024,
            'appKeyConfigured' => false,
            'productionAssetsPresent' => false,
            'applicationFilesPresent' => false,
        ]));

        $report = $checker->check();

        $this->assertFalse($report['ready']);
        $this->assertSame('fail', $report['checks']['php']['status']);
        $this->assertSame('fail', $report['checks']['extensions']['status']);
        $this->assertSame('fail', $report['checks']['storage']['status']);
        $this->assertSame('fail', $report['checks']['database_extension']['status']);
        $this->assertSame('fail', $report['checks']['database']['status']);
        $this->assertSame('fail', $report['checks']['queue']['status']);
        $this->assertSame('fail', $report['checks']['scheduler']['status']);
        $this->assertSame('fail', $report['checks']['disk']['status']);
        $this->assertSame('fail', $report['checks']['app_key']['status']);
        $this->assertSame('fail', $report['checks']['assets']['status']);
        $this->assertSame('fail', $report['checks']['runtime_files']['status']);
    }

    public function test_sync_queue_is_reported_as_warning_but_does_not_block_installation(): void
    {
        $checker = new SystemHealthChecker($this->probe([
            'queueConnection' => 'sync',
        ]));

        $report = $checker->check();

        $this->assertTrue($report['ready']);
        $this->assertSame('warning', $report['checks']['queue']['status']);
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
