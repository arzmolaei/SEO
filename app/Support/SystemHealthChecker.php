<?php

namespace App\Support;

use App\Contracts\SystemProbe;
use App\Support\Installer\InstallerPreflight;

class SystemHealthChecker
{
    public function __construct(private readonly SystemProbe $probe) {}

    /**
     * @return array{
     *   ready: bool,
     *   checks: array<string, array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string}>
     * }
     */
    public function check(): array
    {
        $preflight = (new InstallerPreflight($this->probe))->check();
        $checks = $preflight['checks'];
        $checks['database_extension'] = $this->databaseExtensionCheck();
        $checks['database'] = $this->databaseCheck();
        $checks['queue'] = $this->queueCheck();
        $checks['scheduler'] = $this->schedulerCheck();

        foreach ($checks as $check) {
            if ($check['required'] && $check['status'] === 'fail') {
                return ['ready' => false, 'checks' => $checks];
            }
        }

        return ['ready' => true, 'checks' => $checks];
    }

    /** @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string} */
    private function databaseExtensionCheck(): array
    {
        $driver = $this->probe->databaseDriver();
        $extension = match ($driver) {
            'mysql', 'mariadb' => 'pdo_mysql',
            'pgsql' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            default => null,
        };

        if ($extension === null) {
            return $this->result('fail', true, $driver, 'Configured database driver is not supported.');
        }

        $loaded = $this->probe->extensionLoaded($extension);

        return $this->result(
            $loaded ? 'pass' : 'fail',
            true,
            ['driver' => $driver, 'extension' => $extension],
            $loaded ? 'Database PDO driver extension is available.' : 'Database PDO driver extension is missing.',
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

    /**
     * @param  'pass'|'warning'|'fail'  $status
     * @return array{status: 'pass'|'warning'|'fail', required: bool, value: mixed, message: string}
     */
    private function result(string $status, bool $required, mixed $value, string $message): array
    {
        return compact('status', 'required', 'value', 'message');
    }
}
