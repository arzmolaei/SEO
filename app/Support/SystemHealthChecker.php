<?php

namespace App\Support;

use App\Contracts\SystemProbe;

class SystemHealthChecker
{
    private const MINIMUM_PHP_VERSION = '8.3.0';

    private const MINIMUM_FREE_DISK_BYTES = 134217728;

    /** @var list<string> */
    private const REQUIRED_EXTENSIONS = [
        'ctype',
        'fileinfo',
        'filter',
        'hash',
        'mbstring',
        'openssl',
        'pcre',
        'pdo',
        'session',
        'tokenizer',
        'xml',
    ];

    public function __construct(private SystemProbe $probe) {}

    /**
     * @return array{
     *   ready: bool,
     *   checks: array<string, array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string}>
     * }
     */
    public function check(): array
    {
        $checks = [
            'php' => $this->phpCheck(),
            'extensions' => $this->extensionsCheck(),
            'storage' => $this->storageCheck(),
            'database' => $this->databaseCheck(),
            'queue' => $this->queueCheck(),
            'scheduler' => $this->schedulerCheck(),
            'disk' => $this->diskCheck(),
            'app_key' => $this->appKeyCheck(),
            'assets' => $this->assetsCheck(),
        ];

        $ready = true;

        foreach ($checks as $check) {
            if ($check['required'] && $check['status'] === 'fail') {
                $ready = false;
                break;
            }
        }

        return [
            'ready' => $ready,
            'checks' => $checks,
        ];
    }

    /** @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string} */
    private function phpCheck(): array
    {
        $version = $this->probe->phpVersion();
        $passes = version_compare($version, self::MINIMUM_PHP_VERSION, '>=');

        return $this->result(
            $passes ? 'pass' : 'fail',
            true,
            $version,
            $passes ? 'PHP runtime is supported.' : 'PHP 8.3 or newer is required.',
        );
    }

    /** @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string} */
    private function extensionsCheck(): array
    {
        $required = self::REQUIRED_EXTENSIONS;
        $databaseExtension = $this->databaseExtension($this->probe->databaseDriver());

        if ($databaseExtension !== null) {
            $required[] = $databaseExtension;
        }

        $required = array_values(array_unique($required));
        $missing = array_values(array_filter(
            $required,
            fn (string $extension): bool => ! $this->probe->extensionLoaded($extension),
        ));

        return $this->result(
            $missing === [] ? 'pass' : 'fail',
            true,
            [
                'required' => $required,
                'missing' => $missing,
            ],
            $missing === []
                ? 'Required PHP extensions are available.'
                : 'One or more required PHP extensions are missing.',
        );
    }

    /** @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string} */
    private function storageCheck(): array
    {
        $paths = ['storage', 'bootstrap/cache'];
        $unwritable = array_values(array_filter(
            $paths,
            fn (string $path): bool => ! $this->probe->pathIsWritable($path),
        ));

        return $this->result(
            $unwritable === [] ? 'pass' : 'fail',
            true,
            [
                'paths' => $paths,
                'unwritable' => $unwritable,
            ],
            $unwritable === []
                ? 'Application storage paths are writable.'
                : 'Application storage paths must be writable.',
        );
    }

    /** @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string} */
    private function databaseCheck(): array
    {
        $reachable = $this->probe->databaseReachable();

        return $this->result(
            $reachable ? 'pass' : 'fail',
            true,
            $this->probe->databaseDriver(),
            $reachable ? 'Database connection is available.' : 'Database connection could not be established.',
        );
    }

    /** @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string} */
    private function queueCheck(): array
    {
        $connection = trim($this->probe->queueConnection());

        if ($connection === '') {
            return $this->result('fail', true, $connection, 'A queue connection must be configured.');
        }

        if ($connection === 'sync') {
            return $this->result(
                'warning',
                true,
                $connection,
                'Sync queue works for installation, but a persistent queue is recommended for production.',
            );
        }

        return $this->result('pass', true, $connection, 'Queue backend is configured.');
    }

    /** @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string} */
    private function schedulerCheck(): array
    {
        $supported = $this->probe->schedulerSupported();

        return $this->result(
            $supported ? 'pass' : 'fail',
            true,
            $supported,
            $supported ? 'Scheduler runtime is available.' : 'Scheduler runtime files are unavailable.',
        );
    }

    /** @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string} */
    private function diskCheck(): array
    {
        $freeBytes = $this->probe->freeDiskBytes('.');

        if ($freeBytes === null) {
            return $this->result(
                'warning',
                false,
                null,
                'Free disk space could not be measured.',
            );
        }

        $passes = $freeBytes >= self::MINIMUM_FREE_DISK_BYTES;

        return $this->result(
            $passes ? 'pass' : 'fail',
            true,
            $freeBytes,
            $passes ? 'Disk space is sufficient.' : 'At least 128 MiB of free disk space is required.',
        );
    }

    /** @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string} */
    private function appKeyCheck(): array
    {
        $configured = $this->probe->appKeyConfigured();

        return $this->result(
            $configured ? 'pass' : 'fail',
            true,
            $configured,
            $configured ? 'Application key is configured.' : 'Application key must be generated before installation can finish.',
        );
    }

    /** @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string} */
    private function assetsCheck(): array
    {
        $present = $this->probe->productionAssetsPresent();

        return $this->result(
            $present ? 'pass' : 'fail',
            true,
            $present,
            $present ? 'Production frontend assets are present.' : 'Built frontend assets are required; Node.js is not required on the target host.',
        );
    }

    private function databaseExtension(string $driver): ?string
    {
        return match ($driver) {
            'mysql', 'mariadb' => 'pdo_mysql',
            'pgsql' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            default => null,
        };
    }

    /**
     * @param 'pass'|'warning'|'fail' $status
     * @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string}
     */
    private function result(string $status, bool $required, mixed $value, string $message): array
    {
        return compact('status', 'required', 'value', 'message');
    }
}
